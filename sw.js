// Bump VERSION on every release. The old cache is dropped on activate.
const VERSION = "v3";
const CACHE = `zas-sales-static-${VERSION}`;

const PRECACHE = [
  "./assets/app.css",
  "./assets/app.js",
  "./assets/receipt.js",
  "./vendor/html5-qrcode.min.js",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png"
];

self.addEventListener("install", (event) => {
  // A single missing file must not abort the whole install.
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      Promise.all(PRECACHE.map((file) => cache.add(file).catch(() => undefined)))
    )
  );

  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE)
          .map((key) => caches.delete(key))
      )
    )
  );

  self.clients.claim();
});

function isStaticAsset(url) {
  return (
    url.pathname.includes("/assets/") ||
    url.pathname.includes("/vendor/") ||
    url.pathname.includes("/icons/") ||
    url.pathname.endsWith("manifest.json")
  );
}

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (request.method !== "GET" || request.mode === "navigate") {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin || !isStaticAsset(url)) {
    return;
  }

  // Stale-while-revalidate: the counter still loads instantly and offline,
  // but a stylesheet or script updated on the server lands on the next
  // load instead of being pinned to whatever was cached first.
  event.respondWith(
    caches.open(CACHE).then((cache) =>
      cache.match(request).then((cached) => {
        const network = fetch(request)
          .then((response) => {
            if (response && response.ok) {
              cache.put(request, response.clone());
            }
            return response;
          })
          .catch(() => cached);

        return cached || network;
      })
    )
  );
});
