const CACHE = "zas-sales-static-v2";

const STATIC_FILES = [
  "./assets/app.css",
  "./assets/app.js",
  "./vendor/html5-qrcode.min.js",
  "./manifest.json",
  "./icons/icon-192.png",
  "./icons/icon-512.png"
];

const STATIC_URLS = new Set(
  STATIC_FILES.map((file) =>
    new URL(file, self.registration.scope).href
  )
);

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      cache.addAll(STATIC_FILES)
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

self.addEventListener("fetch", (event) => {
  const request = event.request;

  if (
    request.method !== "GET" ||
    request.mode === "navigate" ||
    !STATIC_URLS.has(request.url)
  ) {
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(request).then((response) => {
        if (response.ok) {
          caches.open(CACHE).then((cache) => {
            cache.put(request, response.clone());
          });
        }

        return response;
      });
    })
  );
});