/* Caches the app so it opens instantly and works with a weak connection.
   Tickets waiting to be sent are kept in IndexedDB by app.js, not here. */
const CACHE = 'ticket-scanner-v1';
const FILES = ['./', 'index.html', 'app.css', 'app.js', 'recognizer.js', 'template.js', 'config.js', 'manifest.webmanifest', 'icon.svg', 'icon-192.png', 'icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) return;
    if (request.url.includes('ping=1')) return; // connection tests always go to the server
    // Network first (so updates arrive), falling back to the cached copy offline.
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            })
            .catch(() => caches.match(request))
    );
});
