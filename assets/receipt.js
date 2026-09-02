(() => {
  "use strict";

  const printButton = document.getElementById("receipt-print");
  if (printButton) printButton.addEventListener("click", () => window.print());

  const actions = document.getElementById("receipt-actions");
  if (!actions) return;

  const receipt = JSON.parse(actions.dataset.receipt);
  const canvas = document.getElementById("receipt-canvas");
  const shareButton = document.getElementById("receipt-share");
  const saveButton = document.getElementById("receipt-save");
  const copyButton = document.getElementById("receipt-copy");
  const linkField = document.getElementById("receipt-link");

  const SCALE = 8;                       // px per mm — roughly a 203 dpi roll
  const WIDTH = receipt.width * SCALE;
  const PAD = 24;
  const money = (value) =>
    `Rs. ${new Intl.NumberFormat("en-PK", { maximumFractionDigits: 0 }).format(value)}`;

  /**
   * The receipt is redrawn on a canvas rather than screenshotted, so the
   * saved picture is crisp and identical on every phone.
   */
  function drawReceipt() {
    const context = canvas.getContext("2d");
    let y = PAD;

    // First pass measures, second pass paints, so the canvas is sized exactly.
    const lines = [];
    const push = (text, options = {}) => lines.push(Object.assign({ text }, options));

    push(receipt.shop.name, { size: 30, weight: "700", align: "center", after: 6 });
    if (receipt.shop.address) push(receipt.shop.address, { size: 17, align: "center", after: 2 });
    if (receipt.shop.phone) push(receipt.shop.phone, { size: 17, align: "center", after: 4 });
    push(null, { rule: true });

    push(`Receipt #${receipt.sale.id}`, { size: 18 });
    push(`${receipt.sale.date}  ${receipt.sale.time}`, { size: 18 });
    push(`Salesman: ${receipt.sale.staff}`, { size: 18 });
    push(`Customer: ${receipt.sale.customer}`, { size: 18 });
    if (receipt.sale.contact) push(`Contact: ${receipt.sale.contact}`, { size: 18 });
    push(null, { rule: true });

    receipt.items.forEach((item) => {
      push(item.name, { size: 19, weight: "600" });
      push(`${item.qty} × ${money(item.rate)}`, { size: 18, right: money(item.total) });
    });

    push(null, { rule: true });
    push("Subtotal", { size: 19, right: money(receipt.sale.subtotal) });
    if (receipt.sale.discount > 0) {
      push(receipt.sale.discount_label || "Discount", { size: 19, right: `- ${money(receipt.sale.discount)}` });
    }
    push("TOTAL", { size: 26, weight: "700", right: money(receipt.sale.total), before: 6 });
    push(null, { rule: true });
    if (receipt.shop.footer) push(receipt.shop.footer, { size: 18, align: "center" });

    const lineHeight = (line) => (line.rule ? 22 : Math.round(line.size * 1.45) + (line.after || 0) + (line.before || 0));
    const height = lines.reduce((total, line) => total + lineHeight(line), PAD * 2);

    canvas.width = WIDTH;
    canvas.height = height;

    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, WIDTH, height);
    context.fillStyle = "#111111";
    context.textBaseline = "top";

    lines.forEach((line) => {
      if (line.rule) {
        context.strokeStyle = "#bbbbbb";
        context.lineWidth = 2;
        context.beginPath();
        context.moveTo(PAD, y + 10);
        context.lineTo(WIDTH - PAD, y + 10);
        context.stroke();
        y += 22;
        return;
      }

      y += line.before || 0;
      context.font = `${line.weight || "400"} ${line.size}px "Helvetica Neue", Arial, sans-serif`;

      if (line.align === "center") {
        context.textAlign = "center";
        context.fillText(line.text, WIDTH / 2, y, WIDTH - PAD * 2);
      } else {
        context.textAlign = "left";
        // Leave room for the amount printed hard against the right edge.
        const room = line.right ? WIDTH - PAD * 2 - context.measureText(line.right).width - 16 : WIDTH - PAD * 2;
        context.fillText(line.text, PAD, y, room);

        if (line.right) {
          context.textAlign = "right";
          context.fillText(line.right, WIDTH - PAD, y);
        }
      }

      y += Math.round(line.size * 1.45) + (line.after || 0);
    });

    return canvas;
  }

  function toBlob() {
    return new Promise((resolve, reject) => {
      drawReceipt().toBlob(
        (blob) => (blob ? resolve(blob) : reject(new Error("The image could not be created."))),
        "image/jpeg",
        0.92
      );
    });
  }

  function flash(button, message) {
    const original = button.textContent;
    button.textContent = message;
    window.setTimeout(() => { button.textContent = original; }, 2200);
  }

  if (saveButton) {
    saveButton.addEventListener("click", async () => {
      try {
        const blob = await toBlob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `receipt-${receipt.sale.id}.jpg`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 4000);
        flash(saveButton, "Saved");
      } catch (error) {
        flash(saveButton, "Could not save");
      }
    });
  }

  if (shareButton) {
    shareButton.addEventListener("click", async () => {
      const caption = `Receipt #${receipt.sale.id} from ${receipt.shop.name} — ${money(receipt.sale.total)}`;

      // Sharing the picture itself needs the Web Share API with file support,
      // which phones have and desktop browsers mostly do not.
      try {
        const blob = await toBlob();
        const file = new File([blob], `receipt-${receipt.sale.id}.jpg`, { type: "image/jpeg" });

        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          await navigator.share({ files: [file], text: caption });
          return;
        }
      } catch (error) {
        if (error && error.name === "AbortError") return;
      }

      // Otherwise hand WhatsApp the caption plus the receipt link.
      const text = receipt.share_url ? `${caption}\n${receipt.share_url}` : caption;
      window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, "_blank", "noopener");
    });
  }

  if (copyButton && linkField) {
    copyButton.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(linkField.value);
        flash(copyButton, "Copied");
      } catch (error) {
        linkField.select();
        flash(copyButton, "Press copy");
      }
    });
  }
})();
