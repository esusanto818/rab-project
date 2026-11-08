// sw.stockcard.js
// Minimal Service Worker untuk mencegah error MIME type

const CACHE_NAME = 'stock-card-mobile-v1';

// 1. Event saat Service Worker diinstall
self.addEventListener('install', function(event) {
    console.log('[SW] Stock Card Service Worker installed');
    self.skipWaiting(); // Aktifkan segera
});

// 2. Event saat Service Worker diaktifkan
self.addEventListener('activate', function(event) {
    console.log('[SW] Stock Card Service Worker activated');
    event.waitUntil(self.clients.claim());
});

// 3. Event saat ada request jaringan (fallback ke network jika tidak ada di cache)
self.addEventListener('fetch', function(event) {
    event.respondWith(
        fetch(event.request).catch(function() {
            // Jika offline, bisa tambahkan logika cache di sini nanti
            return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
        })
    );
});