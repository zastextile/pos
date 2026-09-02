(() => {
  "use strict";

  const banner = document.getElementById("install-banner");
  if (!banner) return;

  const button = document.getElementById("install-go");
  const hint = document.getElementById("install-hint");
  const dismiss = document.getElementById("install-dismiss");
  const HIDDEN_KEY = "zas-install-dismissed";

  const installed = () =>
    window.matchMedia("(display-mode: standalone)").matches ||
    window.navigator.standalone === true;

  // iOS has no install prompt: Safari only offers Share > Add to Home Screen.
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isSafari = isIOS && !/crios|fxios|edgios/i.test(navigator.userAgent);

  let deferred = null;

  function show(mode) {
    if (installed()) return;
    try {
      if (localStorage.getItem(HIDDEN_KEY) === "1") return;
    } catch (error) { /* private mode: just show it */ }

    banner.classList.remove("hidden");
    banner.dataset.mode = mode;

    if (mode === "ios") {
      button.classList.add("hidden");
      hint.textContent = "Tap Share, then “Add to Home Screen”.";
    } else {
      button.classList.remove("hidden");
      hint.textContent = "Works offline and opens like a normal app.";
    }
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault();
    deferred = event;
    show("prompt");
  });

  if (isSafari) show("ios");

  if (button) {
    button.addEventListener("click", async () => {
      if (!deferred) return;
      button.disabled = true;
      deferred.prompt();

      try {
        const choice = await deferred.userChoice;
        if (choice.outcome === "accepted") banner.classList.add("hidden");
      } finally {
        deferred = null;
        button.disabled = false;
      }
    });
  }

  if (dismiss) {
    dismiss.addEventListener("click", () => {
      banner.classList.add("hidden");
      try { localStorage.setItem(HIDDEN_KEY, "1"); } catch (error) { /* ignore */ }
    });
  }

  window.addEventListener("appinstalled", () => banner.classList.add("hidden"));
})();
