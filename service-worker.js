/* Zipoo service worker — app shell for full offline use.
 *
 *  - Pages (navigations): network first (3.5s), then the cached copy, so the app
 *    always opens, online or not, and always gets updates when online.
 *  - Static assets (css/js/fonts/icons/locales): cache first, filled the first
 *    time they are used; version query strings (?v=) are honoured online and
 *    ignored as a last resort offline.
 *  - /api/ requests are NOT handled here: assets/js/offline.js caches API data
 *    in IndexedDB and queues offline writes.
 */
const CACHE_NAME = "zipoo-offline-v2";

const PAGES = [
  "./index.html",
  "./pages/dashboard.html",
  "./pages/settings.html",
  "./pages/customers.html",
  "./pages/suppliers.html",
  "./pages/stock.html",
  "./pages/sales.html",
  "./pages/bank.html",
  "./pages/expenses.html",
  "./pages/realestate.html",
  "./pages/users.html",
  "./pages/login.html",
  "./pages/register.html",
  "./saas/index.html",
  "./saas/login.html",
  "./saas/forgot-password.html",
  "./saas/reset-password.html",
  "./saas/dashboard.html",
  "./saas/businesses.html",
  "./saas/users.html",
  "./saas/plans.html",
  "./saas/settings.html",
];

const ASSETS = [
  "./assets/css/styles.css",
  "./assets/js/app.js",
  "./assets/js/offline.js",
  "./assets/js/saas.js",
  "./sf-pro-display/SFPRODISPLAYREGULAR.OTF",
  "./sf-pro-display/SFPRODISPLAYMEDIUM.OTF",
  "./sf-pro-display/SFPRODISPLAYBOLD.OTF",
  "./assets/icons/icon.svg",
  "./assets/icons/icon-192.svg",
  "./assets/icons/icon-512.svg",
  "./apple-icon-57x57.png",
  "./apple-icon-60x60.png",
  "./apple-icon-72x72.png",
  "./apple-icon-76x76.png",
  "./apple-icon-114x114.png",
  "./apple-icon-120x120.png",
  "./apple-icon-144x144.png",
  "./apple-icon-152x152.png",
  "./apple-icon-180x180.png",
  "./android-icon-36x36.png",
  "./android-icon-48x48.png",
  "./android-icon-72x72.png",
  "./android-icon-96x96.png",
  "./android-icon-144x144.png",
  "./android-icon-192x192.png",
  "./android-icon-512x512.png",
  "./favicon-16x16.png",
  "./favicon-32x32.png",
  "./favicon-96x96.png",
  "./favicon.ico",
  "./ms-icon-70x70.png",
  "./ms-icon-144x144.png",
  "./ms-icon-150x150.png",
  "./ms-icon-310x310.png",
  "./browserconfig.xml",
  "./locales/en.json",
  "./locales/sw.json",
  "./manifest.json",
];

// Clean URL -> page file (mirrors .htaccess).
const ROUTES = [
  [/\/saas\/forgot-password\/?$/, "./saas/forgot-password.html"],
  [/\/saas\/reset-password\/?$/, "./saas/reset-password.html"],
  [/\/saas\/dashboard\/?$/, "./saas/dashboard.html"],
  [/\/saas\/businesses\/?$/, "./saas/businesses.html"],
  [/\/saas\/users\/?$/, "./saas/users.html"],
  [/\/saas\/plans\/?$/, "./saas/plans.html"],
  [/\/saas\/settings\/?$/, "./saas/settings.html"],
  [/\/saas\/login\/?$/, "./saas/login.html"],
  [/\/saas\/?$/, "./saas/index.html"],
  [/\/dashboard(\.html)?\/?$/, "./pages/dashboard.html"],
  [/\/settings(\.html)?\/?$/, "./pages/settings.html"],
  [/\/customers(\.html)?\/?$/, "./pages/customers.html"],
  [/\/suppliers(\.html)?\/?$/, "./pages/suppliers.html"],
  [/\/users(\.html)?\/?$/, "./pages/users.html"],
  [/\/stock(\.html)?\/?$/, "./pages/stock.html"],
  [/\/sales(\.html)?\/?$/, "./pages/sales.html"],
  [/\/bank(\.html)?\/?$/, "./pages/bank.html"],
  [/\/expenses(\.html)?\/?$/, "./pages/expenses.html"],
  [/\/real-?estate(\.html)?\/?$/, "./pages/realestate.html"],
  [/\/register(\.html)?\/?$/, "./pages/register.html"],
  [/\/login(\.html)?\/?$/, "./pages/login.html"],
];

const routeFor = (pathname) => {
  for (const [re, file] of ROUTES) if (re.test(pathname)) return file;
  return "./index.html";
};

const offlineApiPage = () => new Response(
  "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>Offline</title>" +
  "<body style='font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#F6F8FC;color:#12233F'>" +
  "<div style='max-width:340px;padding:24px;text-align:center'><h2 style='color:#DC2626'>You are offline</h2>" +
  "<p>Exports, PDFs and printouts need an internet connection. Everything else keeps working — go back and continue.</p>" +
  "<button onclick='history.back()' style='padding:10px 18px;border:0;border-radius:10px;background:#1477FF;color:#fff;font-weight:700'>Go back</button></div>",
  { status: 503, headers: { "Content-Type": "text/html; charset=utf-8" } },
);

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    // One missing file must not abort the whole install.
    await Promise.allSettled([...PAGES, ...ASSETS].map((url) => cache.add(new Request(url, { cache: "reload" }))));
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener("message", (event) => {
  if (event.data === "SKIP_WAITING") self.skipWaiting();
});

const fetchWithTimeout = (request, ms) => new Promise((resolve, reject) => {
  const timer = setTimeout(() => reject(new Error("timeout")), ms);
  fetch(request).then((res) => { clearTimeout(timer); resolve(res); }, (err) => { clearTimeout(timer); reject(err); });
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // API data is handled by assets/js/offline.js. Only give offline downloads a friendly page.
  if (url.pathname.includes("/api/")) {
    if (request.mode === "navigate") {
      event.respondWith(fetch(request).catch(() => offlineApiPage()));
    }
    return;
  }

  if (request.mode === "navigate") {
    event.respondWith((async () => {
      const page = routeFor(url.pathname);
      try {
        const res = await fetchWithTimeout(request, 3500);
        if (res.ok) {
          const cache = await caches.open(CACHE_NAME);
          cache.put(new Request(page), res.clone());
        }
        return res;
      } catch {
        return (await caches.match(page, { ignoreSearch: true }))
          || (await caches.match("./index.html", { ignoreSearch: true }))
          || new Response("Offline", { status: 503 });
      }
    })());
    return;
  }

  event.respondWith((async () => {
    const exact = await caches.match(request);
    if (exact) return exact;
    try {
      const res = await fetch(request);
      if (res.ok) {
        const cache = await caches.open(CACHE_NAME);
        cache.put(request, res.clone());
      }
      return res;
    } catch {
      const loose = await caches.match(request, { ignoreSearch: true });
      return loose || new Response("", { status: 503, statusText: "Offline" });
    }
  })());
});
