(() => {
  "use strict";

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => navigator.serviceWorker.register("sw.js").catch(() => undefined));
  }

  const app = document.getElementById("sale-app");
  if (!app) return;

  const elements = {
    scanOpen: document.getElementById("scan-open"),
    manualOpen: document.getElementById("manual-open"),
    scannerOverlay: document.getElementById("scanner-overlay"),
    scannerClose: document.getElementById("scanner-close"),
    scannerNext: document.getElementById("scanner-next"),
    scannerHelp: document.getElementById("scanner-help"),
    barcodeForm: document.getElementById("manual-barcode-form"),
    barcodeInput: document.getElementById("barcode-input"),
    cartTitle: document.getElementById("cart-title"),
    cartList: document.getElementById("cart-list"),
    cartClear: document.getElementById("cart-clear"),
    saleTotal: document.getElementById("sale-total"),
    checkoutTotal: document.getElementById("checkout-total"),
    checkoutOpen: document.getElementById("checkout-open"),
    checkoutOverlay: document.getElementById("checkout-overlay"),
    checkoutClose: document.getElementById("checkout-close"),
    saveSale: document.getElementById("save-sale"),
    customerName: document.getElementById("customer-name"),
    customerContact: document.getElementById("customer-contact"),
    customerAddress: document.getElementById("customer-address"),
    success: document.getElementById("sale-success"),
    successReference: document.getElementById("success-reference"),
    newSale: document.getElementById("new-sale"),
    toast: document.getElementById("toast"),
    catalog: document.getElementById("catalog"),
    catalogSearch: document.getElementById("catalog-search"),
    catalogEmpty: document.getElementById("catalog-empty"),
    categoryTabs: document.getElementById("category-tabs"),
    productGrid: document.getElementById("product-grid"),
    discountBlock: document.getElementById("discount-block"),
    discountValue: document.getElementById("discount-value"),
    discountError: document.getElementById("discount-error"),
    checkoutSubtotal: document.getElementById("checkout-subtotal"),
    checkoutDiscount: document.getElementById("checkout-discount"),
    checkoutDiscountRow: document.getElementById("checkout-discount-row"),
    checkoutDiscountLabel: document.getElementById("checkout-discount-label"),
    customerSearch: document.getElementById("customer-search"),
    customerResults: document.getElementById("customer-results"),
    pickedCustomer: document.getElementById("picked-customer"),
    pickedCustomerName: document.getElementById("picked-customer-name"),
    pickedCustomerClear: document.getElementById("picked-customer-clear"),
    saveCustomer: document.getElementById("save-customer"),
    successReceipt: document.getElementById("success-receipt"),
  };

  const maxDiscount = Number(app.dataset.maxDiscount || 0);
  let catalog = { categories: [], products: [] };
  let activeCategory = "all";
  let catalogTerm = "";
  let discountType = "none";
  let pickedCustomerId = null;
  let customerTimer = null;

  const storageKey = `zas-sales-cart-v1:${app.dataset.staffId}`;
  let cart = loadCart();
  let scanner = null;
  let scannerRunning = false;
  let scannerWanted = false;
  let scanLock = false;
  let toastTimer = null;
  let lastCode = "";
  let lastCodeAt = 0;

  function loadCart() {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || "[]");
      if (!Array.isArray(saved)) return [];
      return saved.filter((item) =>
        Number.isInteger(Number(item.id)) &&
        typeof item.name === "string" &&
        typeof item.barcode === "string" &&
        Number(item.rate) >= 0 &&
        Number.isInteger(Number(item.quantity)) &&
        Number(item.quantity) > 0
      ).map((item) => ({
        id: Number(item.id),
        name: item.name,
        barcode: item.barcode,
        rate: Number(item.rate),
        quantity: Math.min(9999, Number(item.quantity)),
      }));
    } catch {
      localStorage.removeItem(storageKey);
      return [];
    }
  }

  function persistCart() {
    localStorage.setItem(storageKey, JSON.stringify(cart));
  }

  function formatMoney(value) {
    return `Rs. ${new Intl.NumberFormat("en-PK", { maximumFractionDigits: 0 }).format(value)}`;
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (character) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#039;", '"': "&quot;",
    })[character]);
  }

  function cartTotal() {
    return cart.reduce((total, item) => total + item.rate * item.quantity, 0);
  }

  // Mirrors resolve_discount() in PHP so the counter sees the same number the
  // server will store. The server still recomputes and remains the authority.
  function discountAmount() {
    const subtotal = cartTotal();
    const value = Number(elements.discountValue && elements.discountValue.value) || 0;
    if (discountType === "none" || value <= 0 || maxDiscount <= 0) return 0;
    const ceiling = Math.floor(subtotal * maxDiscount) / 100;
    const raw = discountType === "percent" ? Math.floor(subtotal * value) / 100 : value;
    return Math.min(raw, ceiling, subtotal);
  }

  function discountProblem() {
    const value = Number(elements.discountValue && elements.discountValue.value) || 0;
    if (discountType === "none" || value <= 0) return "";
    if (maxDiscount <= 0) return "Discounts are not enabled for your account.";
    if (discountType === "percent" && value > maxDiscount) {
      return `Your discount limit is ${maxDiscount}%.`;
    }
    if (discountType === "amount") {
      const ceiling = Math.floor(cartTotal() * maxDiscount) / 100;
      if (value > ceiling) return `Most you can give here is ${formatMoney(ceiling)} (${maxDiscount}%).`;
    }
    return "";
  }

  function renderTotals() {
    const subtotal = cartTotal();
    const problem = discountProblem();
    const discount = problem ? 0 : discountAmount();

    if (elements.checkoutSubtotal) elements.checkoutSubtotal.textContent = formatMoney(subtotal);
    if (elements.checkoutDiscountRow) {
      elements.checkoutDiscountRow.classList.toggle("hidden", discount <= 0);
      elements.checkoutDiscount.textContent = `− ${formatMoney(discount)}`;
      elements.checkoutDiscountLabel.textContent =
        discountType === "percent"
          ? `Discount ${Number(elements.discountValue.value) || 0}%`
          : "Discount";
    }
    if (elements.discountError) {
      elements.discountError.textContent = problem;
      elements.discountError.classList.toggle("hidden", problem === "");
    }
    elements.checkoutTotal.textContent = formatMoney(subtotal - discount);
    elements.saveSale.disabled = problem !== "";
  }

  function renderCart() {
    const total = cartTotal();
    elements.cartTitle.textContent = cart.length
      ? `${cart.length} product${cart.length === 1 ? "" : "s"}`
      : "No products yet";
    elements.cartClear.classList.toggle("hidden", cart.length === 0);
    elements.checkoutOpen.disabled = cart.length === 0;
    elements.saleTotal.textContent = formatMoney(total);
    renderTotals();

    if (!cart.length) {
      elements.cartList.innerHTML = '<div class="empty-card"><span class="barcode-symbol">▥</span><p>Scan a product to start the sale.</p></div>';
      persistCart();
      return;
    }

    elements.cartList.innerHTML = `<div class="cart-list">${cart.map((item) => `
      <article class="cart-card" data-product-id="${item.id}">
        <div class="item-head">
          <div><h3>${escapeHtml(item.name)}</h3><p>${formatMoney(item.rate)} each</p></div>
          <strong>${formatMoney(item.rate * item.quantity)}</strong>
        </div>
        <div class="quantity-row">
          <button class="quantity-button" type="button" data-action="decrease" aria-label="Decrease ${escapeHtml(item.name)}">−</button>
          <input class="quantity-input" type="number" inputmode="numeric" min="1" max="9999" step="1"
                 value="${item.quantity}" data-quantity-input aria-label="Quantity of ${escapeHtml(item.name)}">
          <button class="quantity-button" type="button" data-action="increase" aria-label="Increase ${escapeHtml(item.name)}">+</button>
          <button class="remove-item" type="button" data-action="remove">Remove</button>
        </div>
      </article>`).join("")}</div>
      <p class="cart-hint">Tap a quantity to type it — quicker than pressing + for a dozen.</p>`;
    persistCart();
    renderCatalog();
  }

  /**
   * Refreshes the money on screen without rebuilding the cart, so the field
   * the salesman is typing into keeps its focus and cursor.
   */
  function refreshTotalsInPlace() {
    cart.forEach((item) => {
      const card = elements.cartList.querySelector(`[data-product-id="${item.id}"]`);
      if (card) {
        const amount = card.querySelector(".item-head strong");
        if (amount) amount.textContent = formatMoney(item.rate * item.quantity);
      }
    });
    elements.saleTotal.textContent = formatMoney(cartTotal());
    renderTotals();
    persistCart();
  }

  function showToast(message, isError = false) {
    window.clearTimeout(toastTimer);
    elements.toast.textContent = message;
    elements.toast.style.background = isError ? "#8f2d34" : "#17201d";
    elements.toast.classList.remove("hidden");
    toastTimer = window.setTimeout(() => elements.toast.classList.add("hidden"), 2200);
  }

  function addProduct(product) {
    const existing = cart.find((item) => item.id === Number(product.id));
    if (existing) {
      existing.quantity = Math.min(9999, existing.quantity + 1);
    } else {
      cart.push({
        id: Number(product.id),
        name: String(product.name),
        barcode: String(product.barcode),
        rate: Number(product.rate),
        quantity: 1,
      });
    }
    renderCart();
    showToast(`${product.name} added`);
    if (navigator.vibrate) navigator.vibrate(80);
  }

  function renderCategoryTabs() {
    if (!elements.categoryTabs) return;
    const tabs = [{ id: "all", name: "All", count: catalog.products.length }].concat(catalog.categories);
    elements.categoryTabs.innerHTML = tabs.map((tab) => `
      <button type="button" role="tab" data-category="${tab.id}"
        class="${String(tab.id) === String(activeCategory) ? "active" : ""}"
        aria-selected="${String(tab.id) === String(activeCategory)}">
        ${escapeHtml(tab.name)}<span>${tab.count}</span>
      </button>`).join("");
  }

  function visibleProducts() {
    const term = catalogTerm.trim().toLowerCase();
    return catalog.products.filter((product) => {
      const inCategory =
        activeCategory === "all" || String(product.category_id) === String(activeCategory);
      const matches =
        !term ||
        product.name.toLowerCase().includes(term) ||
        product.barcode.toLowerCase().includes(term);
      return inCategory && matches;
    });
  }

  function renderCatalog() {
    if (!elements.productGrid) return;
    const list = visibleProducts();
    elements.catalogEmpty.classList.toggle("hidden", list.length > 0);
    elements.productGrid.innerHTML = list.map((product) => {
      const inCart = cart.find((item) => item.id === product.id);
      const photo = product.photo
        ? `<img src="${escapeHtml(product.photo)}" alt="" loading="lazy">`
        : '<span class="product-tile-blank">▥</span>';
      return `
        <button class="product-tile ${inCart ? "in-cart" : ""}" type="button" data-add="${product.id}">
          <span class="product-tile-photo">${photo}${inCart ? `<b>${inCart.quantity}</b>` : ""}</span>
          <span class="product-tile-name">${escapeHtml(product.name)}</span>
          <span class="product-tile-rate">${formatMoney(product.rate)}</span>
        </button>`;
    }).join("");
  }

  async function loadCatalog() {
    if (!elements.productGrid) return;
    try {
      const response = await fetch(app.dataset.catalogUrl, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || "Products could not be loaded.");
      catalog = { categories: data.categories || [], products: data.products || [] };
      renderCategoryTabs();
      renderCatalog();
    } catch (error) {
      elements.catalogEmpty.textContent = "Products could not be loaded. Pull down to retry.";
      elements.catalogEmpty.classList.remove("hidden");
    }
  }

  async function findProduct(barcode, fromCamera = false) {
    const code = String(barcode || "").trim();
    if (!code) {
      showToast("Enter a barcode number.", true);
      return false;
    }

    elements.scannerHelp.textContent = "Finding product…";
    try {
      const response = await fetch(`${app.dataset.productUrl}?barcode=${encodeURIComponent(code)}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store",
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || "Product not found.");
      addProduct(data.product);
      elements.barcodeInput.value = "";
      elements.scannerHelp.textContent = "Product added. Scan the next barcode.";
      return true;
    } catch (error) {
      const message = error instanceof Error ? error.message : "Product could not be found.";
      elements.scannerHelp.textContent = message;
      showToast(message, true);
      if (!fromCamera) elements.barcodeInput.focus();
      return false;
    }
  }

  async function stopCamera() {
    if (!scanner) return;
    try {
      if (scannerRunning) await scanner.stop();
    } catch {
      // The camera may already have stopped.
    }
    try { scanner.clear(); } catch { /* no-op */ }
    scanner = null;
    scannerRunning = false;
  }

  async function startCamera() {
    scannerWanted = true;
    elements.scannerNext.classList.add("hidden");
    if (typeof window.Html5Qrcode === "undefined") {
      elements.scannerHelp.textContent = "Camera scanner is unavailable. Enter the barcode manually.";
      elements.scannerNext.classList.remove("hidden");
      return;
    }
    if (scannerRunning || scanner) return;

    const formats = window.Html5QrcodeSupportedFormats;
    const config = formats ? {
      formatsToSupport: [formats.EAN_13, formats.EAN_8, formats.UPC_A, formats.UPC_E, formats.CODE_128, formats.QR_CODE],
      verbose: false,
    } : { verbose: false };
    scanner = new window.Html5Qrcode("reader", config);
    elements.scannerHelp.textContent = "Starting camera…";

    try {
      await scanner.start(
        { facingMode: "environment" },
        {
          fps: 10,
          aspectRatio: 1.777,
          qrbox: (width, height) => ({ width: Math.floor(width * .82), height: Math.min(150, Math.floor(height * .55)) }),
        },
        async (decodedText) => {
          const now = Date.now();
          if (scanLock || (decodedText === lastCode && now - lastCodeAt < 2400)) return;
          scanLock = true;
          lastCode = decodedText;
          lastCodeAt = now;
          await stopCamera();
          await findProduct(decodedText, true);
          window.setTimeout(async () => {
            scanLock = false;
            if (scannerWanted && !elements.scannerOverlay.classList.contains("hidden")) await startCamera();
          }, 800);
        },
        () => undefined
      );
      scannerRunning = true;
      elements.scannerHelp.textContent = "Point the camera at a barcode.";
    } catch {
      await stopCamera();
      elements.scannerHelp.textContent = "Camera could not start. Check permission or enter the barcode manually.";
      elements.scannerNext.classList.remove("hidden");
    }
  }

  async function openScanner(useCamera) {
    elements.scannerOverlay.classList.remove("hidden");
    document.body.style.overflow = "hidden";
    scannerWanted = useCamera;
    if (useCamera) {
      await startCamera();
    } else {
      elements.scannerHelp.textContent = "Enter the product barcode below.";
      window.setTimeout(() => elements.barcodeInput.focus(), 50);
    }
  }

  async function closeScanner() {
    scannerWanted = false;
    await stopCamera();
    elements.scannerOverlay.classList.add("hidden");
    document.body.style.overflow = "";
  }

  if (elements.categoryTabs) {
    elements.categoryTabs.addEventListener("click", (event) => {
      const tab = event.target.closest("button[data-category]");
      if (!tab) return;
      activeCategory = tab.dataset.category;
      renderCategoryTabs();
      renderCatalog();
    });
  }

  if (elements.productGrid) {
    elements.productGrid.addEventListener("click", (event) => {
      const tile = event.target.closest("button[data-add]");
      if (!tile) return;
      const product = catalog.products.find((item) => item.id === Number(tile.dataset.add));
      if (product) addProduct(product);
    });
  }

  if (elements.catalogSearch) {
    elements.catalogSearch.addEventListener("input", () => {
      catalogTerm = elements.catalogSearch.value;
      renderCatalog();
    });
  }

  if (elements.discountBlock) {
    elements.discountBlock.addEventListener("click", (event) => {
      const button = event.target.closest("button[data-discount-type]");
      if (!button) return;
      discountType = button.dataset.discountType;
      elements.discountBlock.querySelectorAll("button[data-discount-type]").forEach((item) => {
        item.classList.toggle("active", item === button);
      });
      elements.discountValue.disabled = discountType === "none";
      if (discountType === "none") elements.discountValue.value = "";
      elements.discountValue.placeholder = discountType === "percent" ? "5" : "500";
      if (discountType !== "none") elements.discountValue.focus();
      renderTotals();
    });
  }

  if (elements.discountValue) {
    elements.discountValue.addEventListener("input", renderTotals);
  }

  function clearPickedCustomer() {
    pickedCustomerId = null;
    elements.pickedCustomer.classList.add("hidden");
    elements.customerSearch.value = "";
    elements.customerResults.classList.add("hidden");
  }

  if (elements.customerSearch) {
    elements.customerSearch.addEventListener("input", () => {
      window.clearTimeout(customerTimer);
      const term = elements.customerSearch.value.trim();
      if (term.length < 2) {
        elements.customerResults.classList.add("hidden");
        return;
      }
      customerTimer = window.setTimeout(async () => {
        try {
          const response = await fetch(`${app.dataset.customerUrl}?q=${encodeURIComponent(term)}`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
          });
          const data = await response.json();
          if (!response.ok || !data.ok) return;
          if (!data.customers.length) {
            elements.customerResults.innerHTML = '<p class="muted small">No saved customer matches. Type the details below to add a new one.</p>';
          } else {
            elements.customerResults.innerHTML = data.customers.map((customer) => `
              <button type="button" role="option" data-customer='${escapeHtml(JSON.stringify(customer))}'>
                <b>${escapeHtml(customer.name)}</b>
                <span>${escapeHtml(customer.contact || "No contact")} · ${customer.sale_count} sale${customer.sale_count === 1 ? "" : "s"}</span>
              </button>`).join("");
          }
          elements.customerResults.classList.remove("hidden");
        } catch (error) {
          elements.customerResults.classList.add("hidden");
        }
      }, 250);
    });

    elements.customerResults.addEventListener("click", (event) => {
      const option = event.target.closest("button[data-customer]");
      if (!option) return;
      const customer = JSON.parse(option.dataset.customer);
      pickedCustomerId = customer.id;
      elements.customerName.value = customer.name;
      elements.customerContact.value = customer.contact || "";
      elements.customerAddress.value = customer.address || "";
      elements.pickedCustomerName.textContent = `Saved customer: ${customer.name}`;
      elements.pickedCustomer.classList.remove("hidden");
      elements.customerResults.classList.add("hidden");
      elements.saveCustomer.checked = false;
    });

    elements.pickedCustomerClear.addEventListener("click", clearPickedCustomer);
  }

  elements.scanOpen.addEventListener("click", () => openScanner(true));
  elements.manualOpen.addEventListener("click", () => openScanner(false));
  elements.scannerClose.addEventListener("click", closeScanner);
  elements.scannerNext.addEventListener("click", startCamera);

  elements.barcodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    await findProduct(elements.barcodeInput.value, false);
  });

  elements.cartList.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-action]");
    const card = event.target.closest("[data-product-id]");
    if (!button || !card) return;
    const id = Number(card.dataset.productId);
    const item = cart.find((product) => product.id === id);
    if (!item) return;
    if (button.dataset.action === "increase") item.quantity = Math.min(9999, item.quantity + 1);
    if (button.dataset.action === "decrease") item.quantity -= 1;
    if (button.dataset.action === "remove" || item.quantity < 1) cart = cart.filter((product) => product.id !== id);
    renderCart();
  });

  // Typing a number beats tapping "+" fifty times for a dozen-count order.
  elements.cartList.addEventListener("input", (event) => {
    const field = event.target.closest("[data-quantity-input]");
    if (!field) return;
    const card = field.closest("[data-product-id]");
    const item = cart.find((product) => product.id === Number(card.dataset.productId));
    if (!item) return;

    const typed = parseInt(field.value, 10);
    // An empty box mid-edit is not an error; treat it as 1 for the running total.
    item.quantity = Number.isNaN(typed) ? 1 : Math.min(9999, Math.max(1, typed));
    refreshTotalsInPlace();
  });

  // On blur or Enter, tidy what was typed. The cart is corrected in place
  // rather than rebuilt: moving from one quantity box straight to the next
  // would otherwise replace the field under the salesman's finger and lose
  // the second edit.
  elements.cartList.addEventListener("change", (event) => {
    const field = event.target.closest("[data-quantity-input]");
    if (!field) return;
    const card = field.closest("[data-product-id]");
    const item = cart.find((product) => product.id === Number(card.dataset.productId));
    if (!item) return;

    const typed = parseInt(field.value, 10);
    item.quantity = Number.isNaN(typed) ? 1 : Math.min(9999, Math.max(1, typed));
    field.value = item.quantity;
    refreshTotalsInPlace();
    renderCatalog();
  });

  elements.cartList.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && event.target.closest("[data-quantity-input]")) {
      event.preventDefault();
      event.target.blur();
    }
  });

  elements.cartClear.addEventListener("click", () => {
    if (window.confirm("Clear all products from this sale?")) {
      cart = [];
      renderCart();
    }
  });

  elements.checkoutOpen.addEventListener("click", () => {
    if (!cart.length) return;
    elements.checkoutTotal.textContent = formatMoney(cartTotal());
    elements.checkoutOverlay.classList.remove("hidden");
    document.body.style.overflow = "hidden";
  });

  elements.checkoutClose.addEventListener("click", () => {
    elements.checkoutOverlay.classList.add("hidden");
    document.body.style.overflow = "";
  });

  elements.saveSale.addEventListener("click", async () => {
    if (!cart.length || elements.saveSale.disabled) return;
    elements.saveSale.disabled = true;
    elements.saveSale.textContent = "SAVING…";
    try {
      const response = await fetch(app.dataset.saleUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-Token": app.dataset.csrf,
        },
        body: JSON.stringify({
          items: cart.map((item) => ({ id: item.id, quantity: item.quantity })),
          customer_id: pickedCustomerId,
          customer_name: elements.customerName.value.trim(),
          customer_contact: elements.customerContact.value.trim(),
          customer_address: elements.customerAddress.value.trim(),
          save_customer: !pickedCustomerId && elements.saveCustomer && elements.saveCustomer.checked,
          discount_type: discountType,
          discount_value: Number(elements.discountValue && elements.discountValue.value) || 0,
        }),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || "Sale could not be saved.");
      cart = [];
      renderCart();
      elements.checkoutOverlay.classList.add("hidden");
      elements.successReference.textContent = data.discount > 0
        ? `Draft Sale #${data.sale_id} · ${formatMoney(data.total)} after ${formatMoney(data.discount)} discount`
        : `Draft Sale #${data.sale_id} · ${formatMoney(data.total)}`;
      if (elements.successReceipt) elements.successReceipt.href = data.receipt_url;
      elements.success.classList.remove("hidden");
      document.body.style.overflow = "hidden";
    } catch (error) {
      showToast(error instanceof Error ? error.message : "Sale could not be saved.", true);
    } finally {
      elements.saveSale.disabled = false;
      elements.saveSale.textContent = "SAVE AS DRAFT SALE";
    }
  });

  elements.newSale.addEventListener("click", () => {
    elements.success.classList.add("hidden");
    elements.customerName.value = "";
    elements.customerContact.value = "";
    elements.customerAddress.value = "";
    if (elements.saveCustomer) elements.saveCustomer.checked = true;
    if (elements.customerSearch) clearPickedCustomer();
    if (elements.discountValue) {
      discountType = "none";
      elements.discountValue.value = "";
      elements.discountValue.disabled = true;
      elements.discountBlock.querySelectorAll("button[data-discount-type]").forEach((item) => {
        item.classList.toggle("active", item.dataset.discountType === "none");
      });
    }
    renderTotals();
    document.body.style.overflow = "";
    window.scrollTo({ top: 0, behavior: "smooth" });
  });

  elements.scannerOverlay.addEventListener("click", (event) => {
    if (event.target === elements.scannerOverlay) closeScanner();
  });
  elements.checkoutOverlay.addEventListener("click", (event) => {
    if (event.target === elements.checkoutOverlay) elements.checkoutClose.click();
  });

  window.addEventListener("offline", () => showToast("Connection lost. Your cart is safe on this device.", true));
  window.addEventListener("online", () => showToast("Connection restored."));
  document.addEventListener("visibilitychange", () => {
    if (document.hidden && scannerRunning) stopCamera();
  });

  renderCart();
  loadCatalog();
})();

/**
 * Product photo field on the admin form. The crop and the shrink both happen
 * here so a 6 MB phone picture never leaves the device at full size.
 */
(() => {
  "use strict";

  const field = document.getElementById("photo-field");
  if (!field) return;

  const input = document.getElementById("photo-input");
  const preview = document.getElementById("photo-preview");
  const value = document.getElementById("photo-value");
  const note = document.getElementById("photo-note");
  const removeButton = document.getElementById("photo-remove");
  const EDGE = 600;
  const MAX_BYTES = 102400;

  document.getElementById("photo-pick").addEventListener("click", () => input.click());

  function centreSquare(image) {
    const canvas = document.createElement("canvas");
    canvas.width = EDGE;
    canvas.height = EDGE;
    const context = canvas.getContext("2d");
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, EDGE, EDGE);
    const edge = Math.min(image.width, image.height);
    context.drawImage(
      image,
      (image.width - edge) / 2, (image.height - edge) / 2, edge, edge,
      0, 0, EDGE, EDGE
    );
    return canvas;
  }

  function toBlobUnderLimit(canvas) {
    // Walk the quality down until the file fits the shop's 100 KB budget.
    const steps = [0.82, 0.72, 0.62, 0.52, 0.42, 0.34];
    return new Promise((resolve, reject) => {
      let index = 0;
      const attempt = () => {
        canvas.toBlob((blob) => {
          if (!blob) { reject(new Error("This image could not be processed.")); return; }
          if (blob.size <= MAX_BYTES || index === steps.length - 1) { resolve(blob); return; }
          index += 1;
          attempt();
        }, "image/jpeg", steps[index]);
      };
      attempt();
    });
  }

  input.addEventListener("change", async () => {
    const file = input.files && input.files[0];
    if (!file) return;

    note.textContent = "Cropping and compressing…";

    try {
      const bitmap = await new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error("That file is not an image we can read."));
        image.src = URL.createObjectURL(file);
      });

      const blob = await toBlobUnderLimit(centreSquare(bitmap));
      URL.revokeObjectURL(bitmap.src);

      const body = new FormData();
      body.append("photo", blob, "product.jpg");
      if (value.value) body.append("previous", value.value);

      const response = await fetch(field.dataset.uploadUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json", "X-CSRF-Token": field.dataset.csrf },
        body,
      });

      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || "The photo could not be saved.");

      value.value = data.photo;
      preview.innerHTML = `<img src="${data.url}?v=${Date.now()}" alt="Product photo">`;
      removeButton.classList.remove("hidden");
      note.textContent = data.message;
    } catch (error) {
      note.textContent = error instanceof Error ? error.message : "The photo could not be saved.";
    } finally {
      input.value = "";
    }
  });

  removeButton.addEventListener("click", () => {
    value.value = "";
    preview.innerHTML = "<span>No photo</span>";
    removeButton.classList.add("hidden");
    note.textContent = "Photo will be removed when you save the product.";
  });
})();
