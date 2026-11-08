/*
  Service Worker Project Progress.
  Base URL mengikuti lokasi file Service Worker.
  API php-crud tidak pernah di-cache.
*/

var APP_BASE = new URL('.', self.location.href).pathname;

// Cache dipisahkan berdasarkan base path.
// Cache dev dan production tidak saling menghapus.
var CACHE_PREFIX = 'prg-v3-' + APP_BASE.replace(/[^a-zA-Z0-9_-]/g, '_');
var VERSION = CACHE_PREFIX;

var SHELL = [
    APP_BASE + 'views/project.progress.m.php',
    APP_BASE + 'js/project.progress.m.js',
    APP_BASE + 'manifest.progress.json'
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(VERSION).then(function (cache) {
            return Promise.all(
                SHELL.map(function (url) {
                    return cache.add(url).catch(function (error) {
                        console.warn('Gagal cache shell:', url, error);
                    });
                })
            );
        })
    );

    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) {
                    return key.indexOf(CACHE_PREFIX) === 0 &&
                           key !== VERSION;
                }).map(function (key) {
                    return caches.delete(key);
                })
            );
        })
    );

    self.clients.claim();
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    var url = new URL(req.url);

    if (req.method !== 'GET' || url.origin !== location.origin) {
        return;
    }

    // API, CRUD, dan foto selalu langsung ke jaringan.
    if (url.pathname.indexOf(APP_BASE + 'php-crud/') === 0) {
        return;
    }

    // Hanya file shell Project Progress yang dikelola.
    if (SHELL.indexOf(url.pathname) === -1) {
        return;
    }

    e.respondWith(
        fetch(req).then(function (res) {
            if (res && res.ok) {
                var copy = res.clone();

                caches.open(VERSION).then(function (cache) {
                    cache.put(req, copy);
                });
            }

            return res;
        }).catch(function () {
            return caches.open(VERSION).then(function (cache) {
                return cache.match(req);
            });
        })
    );
});
