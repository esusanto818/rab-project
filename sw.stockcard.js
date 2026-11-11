/*
Document : sw.stockcard.js
Letak    : ROOT aplikasi (mis. /rab-project/dev/sw.stockcard.js), sejajar folder views/ dan php-crud/.
           Harus dibuka lewat HTTPS (atau localhost) agar browser mau mengaktifkannya.

Tugas    : menyimpan halaman Kartu Stok Mobile + JS-nya supaya bisa dibuka tanpa sinyal.
Strategi : NETWORK-FIRST. Online -> selalu ambil terbaru dari server (update otomatis sampai ke HP).
           Offline / server lambat (> 4 detik) -> pakai salinan tersimpan.
Data     : TIDAK disimpan di sini. Data stok ada di IndexedDB (diatur stock.card.m.js).
           Request ke /php-crud/ tidak pernah disentuh service worker.
Aman     : hanya menangani halaman Kartu Stok Mobile dan file-file yang dimintanya.
           Halaman ERP lain tidak di-cache.
*/
var CACHE = 'stockcard-v2';
var PAGE_RE = /stock\.card\.m/i;
var TIMEOUT_MS = 4000;

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k.indexOf('stockcard-') === 0 && k !== CACHE; })
                .map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

// Apakah request ini milik halaman Kartu Stok Mobile?
function isStockPage(url) { return PAGE_RE.test(url); }
function fromStockClient(req) {
    var ref = req.referrer || '';
    if (isStockPage(ref)) return Promise.resolve(true);
    if (!ref) return Promise.resolve(false);
    // halaman bisa dibuka lewat router?id=NN: kenali dari daftar halaman yang sudah kita simpan
    return caches.open(CACHE).then(function (c) { return c.match(ref); }).then(function (hit) { return !!hit; });
}

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/php-crud/') >= 0) return; // data -> IndexedDB, bukan SW

    if (req.mode === 'navigate') {
        e.respondWith(
            caches.open(CACHE).then(function (c) { return c.match(req); }).then(function (exact) {
                // tangani bila URL-nya halaman stok, atau sudah pernah kita simpan persis (mis. router?id=164)
                if (isStockPage(url.pathname) || exact) return networkFirst(req, isStockPage(url.pathname));
                return fetch(req);
            })
        );
        return;
    }

    e.respondWith(fromStockClient(req).then(function (ok) {
        return ok ? networkFirst(req, true) : fetch(req);
    }));
});

// ignoreSearch hanya untuk file halaman stok / aset-nya; URL lain harus cocok persis
function networkFirst(req, allowIgnoreSearch) {
    return caches.open(CACHE).then(function (cache) {
        function fromCache() {
            return cache.match(req).then(function (hit) {
                return hit || (allowIgnoreSearch ? cache.match(req, { ignoreSearch: true }) : undefined);
            });
        }
        return new Promise(function (resolve) {
            var done = false;
            var timer = setTimeout(function () {
                fromCache().then(function (hit) { if (hit && !done) { done = true; resolve(hit); } });
            }, TIMEOUT_MS);

            fetch(req).then(function (res) {
                clearTimeout(timer);
                if (res && res.ok && !res.redirected) cache.put(req, res.clone());
                if (!done) { done = true; resolve(res); }
            }).catch(function () {
                clearTimeout(timer);
                if (done) return;
                fromCache().then(function (hit) { done = true; resolve(hit || Response.error()); });
            });
        });
    });
}

// Halaman mengirim daftar URL (halaman + JS + CSS) untuk disimpan sekali di awal
self.addEventListener('message', function (e) {
    var d = e.data || {};
    if (d.type !== 'precache' || !Array.isArray(d.urls)) return;
    var port = e.ports && e.ports[0];
    e.waitUntil(
        caches.open(CACHE).then(function (cache) {
            var n = 0;
            return Promise.all(d.urls.map(function (u) {
                return fetch(u, { credentials: 'same-origin', cache: 'reload' }).then(function (r) {
                    if (r.ok && !r.redirected) { n++; return cache.put(u, r); }
                }).catch(function () {});
            })).then(function () { if (port) port.postMessage({ ok: n > 0, cached: n }); });
        }).catch(function () { if (port) port.postMessage({ ok: false, cached: 0 }); })
    );
});