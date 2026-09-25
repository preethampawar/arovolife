// Retires the previous arovolife.com site's PWA service worker.
// Browsers that visited the old site keep serving it from cache until the
// worker at this URL changes; this replacement clears every cache,
// unregisters itself and reloads open tabs onto the live site.
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.map((key) => caches.delete(key)));
        await self.registration.unregister();
        const clients = await self.clients.matchAll({ type: 'window' });
        clients.forEach((client) => client.navigate(client.url));
    })());
});
