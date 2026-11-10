/* LaNotaMia · service worker
   La interfaz se sirve desde el equipo al instante (sin esperar a la red) y se actualiza
   por detrás: una versión nueva de la app se ve a partir de la siguiente vez que la abras.
   Nunca guarda las respuestas de api.php: las notas viven en el servidor y en el equipo.
   © 2026 Héctor Cruz Juárez · GPL-3.0-or-later */
const CACHE = 'lanotamia-0.4';
const BASE = ['./', 'index.html', 'manifest.webmanifest', 'icono-192.png', 'icono-512.png', 'icono-180.png', 'favicon-32.png', 'favicon-64.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(BASE)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.endsWith('api.php')) return;
  const pagina = e.request.mode === 'navigate';
  e.respondWith(caches.open(CACHE).then(async c => {
    const guardado = await c.match(pagina ? './' : e.request, { ignoreSearch: pagina });
    const red = fetch(e.request).then(r => {
      if (r.ok && r.type === 'basic') c.put(pagina ? './' : e.request, r.clone());
      return r;
    }).catch(() => null);
    if (guardado) { e.waitUntil(red); return guardado; }
    return (await red) || (await c.match('./')) || Response.error();
  }));
});
