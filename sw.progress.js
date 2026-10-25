/*
  Document    : sw.progress.js
  Description : Service worker Project Progress mobile. Letakkan di /rab-project/ (root scope).
                - Shell halaman + JS: network-first, fallback cache (aplikasi tetap terbuka saat offline)
                - php-crud/* TIDAK pernah di-cache (data selalu dari server; antrean offline ditangani IndexedDB di halaman)
                Naikkan VERSION setiap kali project.progress.m.js / halaman diubah.
*/
var VERSION = 'prg-v2';      // [DIUBAH] v1 -> v2: halaman mobile kini memakai alur klaim modul desktop
var SHELL = [
    '/rab-project/views/project.progress.m.php',
<<<<<<< Updated upstream
    '/rab-project/dev/js/project.progress.m.js',
=======
    '/rab-project/live/js/project.progress.m.js',
>>>>>>> Stashed changes
    '/rab-project/manifest.progress.json'
];

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(VERSION).then(function (c) {
        return Promise.all(SHELL.map(function (u) { return c.add(u).catch(function () {}); }));   // satu gagal tidak membatalkan semua
    }));
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
    }));
    self.clients.claim();
});

self.addEventListener('fetch', function (e) {
    var req = e.request, url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== location.origin) return;
    if (url.pathname.indexOf('/php-crud/') !== -1) return;                      // API & foto: langsung ke jaringan
    if (SHELL.indexOf(url.pathname) === -1) return;                             // hanya shell progress

    e.respondWith(
        fetch(req).then(function (res) {
            if (res && res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(req, copy); }); }
            return res;
        }).catch(function () { return caches.match(req); })
    );
});