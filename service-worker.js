const CACHE_NAME = "zipoo-phase-1-v175";
const APP_SHELL = [
  "./",
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
  "./saas/dashboard.html",
  "./saas/businesses.html",
  "./saas/users.html",
  "./saas/settings.html",
  "./assets/css/styles.css",
  "./assets/css/styles.css?v=3",
  "./assets/css/styles.css?v=4",
  "./assets/css/styles.css?v=5",
  "./assets/css/styles.css?v=6",
  "./assets/css/styles.css?v=7",
  "./assets/css/styles.css?v=8",
  "./assets/css/styles.css?v=9",
  "./assets/css/styles.css?v=10",
  "./assets/css/styles.css?v=11",
  "./assets/css/styles.css?v=12",
  "./assets/css/styles.css?v=13",
  "./assets/css/styles.css?v=15",
  "./assets/css/styles.css?v=16",
  "./assets/css/styles.css?v=17",
  "./assets/css/styles.css?v=18",
  "./assets/css/styles.css?v=19",
  "./assets/css/styles.css?v=20",
  "./assets/css/styles.css?v=21",
  "./assets/css/styles.css?v=29",
  "./assets/css/styles.css?v=30",
  "./assets/css/styles.css?v=31",
  "./assets/css/styles.css?v=32",
  "./assets/css/styles.css?v=34",
  "./assets/css/styles.css?v=35",
  "./assets/css/styles.css?v=36",
  "./assets/css/styles.css?v=37",
  "./assets/css/styles.css?v=38",
  "./assets/css/styles.css?v=39",
  "./assets/css/styles.css?v=40",
  "./assets/css/styles.css?v=41",
  "./assets/css/styles.css?v=42",
  "./assets/css/styles.css?v=43",
  "./assets/css/styles.css?v=44",
  "./assets/css/styles.css?v=45",
  "./assets/css/styles.css?v=46",
  "./assets/css/styles.css?v=47",
  "./assets/css/styles.css?v=48",
  "./assets/css/styles.css?v=49",
  "./assets/css/styles.css?v=50",
  "./assets/css/styles.css?v=51",
  "./assets/css/styles.css?v=52",
  "./assets/css/styles.css?v=53",
  "./assets/css/styles.css?v=54",
  "./assets/css/styles.css?v=55",
  "./assets/css/styles.css?v=56",
  "./assets/css/styles.css?v=57",
  "./assets/css/styles.css?v=58",
  "./assets/css/styles.css?v=59",
  "./assets/css/styles.css?v=60",
  "./assets/css/styles.css?v=61",
  "./assets/css/styles.css?v=62",
  "./assets/css/styles.css?v=63",
  "./assets/css/styles.css?v=64",
  "./assets/css/styles.css?v=65",
  "./assets/css/styles.css?v=66",
  "./assets/css/styles.css?v=67",
  "./assets/css/styles.css?v=68",
  "./assets/css/styles.css?v=69",
  "./assets/css/styles.css?v=70",
  "./assets/css/styles.css?v=73",
  "./assets/css/styles.css?v=74",
  "./assets/css/styles.css?v=75",
  "./assets/css/styles.css?v=94",
  "./assets/css/styles.css?v=95",
  "./assets/css/styles.css?v=96",
  "./assets/css/styles.css?v=97",
  "./assets/css/styles.css?v=98",
  "./assets/css/styles.css?v=99",
  "./assets/css/styles.css?v=100",
  "./assets/css/styles.css?v=101",
  "./assets/css/styles.css?v=102",
  "./assets/css/styles.css?v=103",
  "./assets/css/styles.css?v=104",
  "./assets/css/styles.css?v=105",
  "./assets/css/styles.css?v=106",
  "./assets/css/styles.css?v=107",
  "./assets/css/styles.css?v=108",
  "./assets/css/styles.css?v=109",
  "./assets/css/styles.css?v=110",
  "./assets/css/styles.css?v=111",
  "./assets/css/styles.css?v=112",
  "./assets/css/styles.css?v=113",
  "./assets/js/app.js",
  "./assets/js/app.js?v=3",
  "./assets/js/app.js?v=4",
  "./assets/js/app.js?v=5",
  "./assets/js/app.js?v=6",
  "./assets/js/app.js?v=7",
  "./assets/js/app.js?v=8",
  "./assets/js/app.js?v=9",
  "./assets/js/app.js?v=10",
  "./assets/js/app.js?v=11",
  "./assets/js/app.js?v=12",
  "./assets/js/app.js?v=13",
  "./assets/js/app.js?v=15",
  "./assets/js/app.js?v=16",
  "./assets/js/app.js?v=17",
  "./assets/js/app.js?v=18",
  "./assets/js/app.js?v=19",
  "./assets/js/app.js?v=20",
  "./assets/js/app.js?v=21",
  "./assets/js/app.js?v=22",
  "./assets/js/app.js?v=23",
  "./assets/js/app.js?v=24",
  "./assets/js/app.js?v=25",
  "./assets/js/app.js?v=26",
  "./assets/js/app.js?v=27",
  "./assets/js/app.js?v=28",
  "./assets/js/app.js?v=31",
  "./assets/js/app.js?v=32",
  "./assets/js/app.js?v=33",
  "./assets/js/app.js?v=34",
  "./assets/js/app.js?v=35",
  "./assets/js/app.js?v=36",
  "./assets/js/app.js?v=37",
  "./assets/js/app.js?v=38",
  "./assets/js/app.js?v=39",
  "./assets/js/app.js?v=40",
  "./assets/js/app.js?v=45",
  "./assets/js/app.js?v=46",
  "./assets/js/app.js?v=47",
  "./assets/js/app.js?v=48",
  "./assets/js/app.js?v=49",
  "./assets/js/app.js?v=50",
  "./assets/js/app.js?v=51",
  "./assets/js/app.js?v=52",
  "./assets/js/app.js?v=53",
  "./assets/js/app.js?v=54",
  "./assets/js/app.js?v=55",
  "./assets/js/app.js?v=56",
  "./assets/js/app.js?v=57",
  "./assets/js/app.js?v=58",
  "./assets/js/app.js?v=59",
  "./assets/js/app.js?v=60",
  "./assets/js/app.js?v=61",
  "./assets/js/app.js?v=62",
  "./assets/js/app.js?v=63",
  "./assets/js/app.js?v=64",
  "./assets/js/app.js?v=65",
  "./assets/js/app.js?v=66",
  "./assets/js/app.js?v=67",
  "./assets/js/app.js?v=68",
  "./assets/js/app.js?v=69",
  "./assets/js/app.js?v=70",
  "./assets/js/app.js?v=71",
  "./assets/js/app.js?v=72",
  "./assets/js/app.js?v=73",
  "./assets/js/app.js?v=74",
  "./assets/js/app.js?v=75",
  "./assets/js/app.js?v=76",
  "./assets/js/app.js?v=77",
  "./assets/js/app.js?v=78",
  "./assets/js/app.js?v=79",
  "./assets/js/app.js?v=80",
  "./assets/js/app.js?v=81",
  "./assets/js/app.js?v=82",
  "./assets/js/app.js?v=83",
  "./assets/js/app.js?v=84",
  "./assets/js/app.js?v=85",
  "./assets/js/app.js?v=86",
  "./assets/js/app.js?v=87",
  "./assets/js/app.js?v=88",
  "./assets/js/app.js?v=89",
  "./assets/js/app.js?v=90",
  "./assets/js/app.js?v=93",
  "./assets/js/app.js?v=94",
  "./assets/js/app.js?v=110",
  "./assets/js/app.js?v=111",
  "./assets/js/app.js?v=112",
  "./assets/js/app.js?v=113",
  "./assets/js/app.js?v=114",
  "./assets/js/app.js?v=115",
  "./assets/js/app.js?v=116",
  "./assets/js/app.js?v=117",
  "./assets/js/app.js?v=118",
  "./assets/js/app.js?v=119",
  "./assets/js/app.js?v=120",
  "./assets/js/app.js?v=121",
  "./assets/js/app.js?v=122",
  "./assets/js/app.js?v=123",
  "./assets/js/app.js?v=124",
  "./assets/js/app.js?v=125",
  "./assets/js/app.js?v=126",
  "./assets/js/app.js?v=127",
  "./assets/js/app.js?v=128",
  "./assets/js/app.js?v=129",
  "./assets/js/app.js?v=29",
  "./assets/js/app.js?v=30",
  "./assets/js/saas.js",
  "./assets/js/saas.js?v=1",
  "./assets/js/saas.js?v=2",
  "./assets/js/saas.js?v=3",
  "./assets/js/saas.js?v=4",
  "./assets/js/saas.js?v=5",
  "./assets/js/saas.js?v=6",
  "./assets/js/saas.js?v=7",
  "./assets/js/saas.js?v=8",
  "./assets/js/saas.js?v=9",
  "./assets/js/saas.js?v=10",
  "./assets/js/saas.js?v=11",
  "./assets/js/saas.js?v=12",
  "./assets/js/saas.js?v=13",
  "./assets/js/saas.js?v=14",
  "./assets/js/saas.js?v=15",
  "./assets/js/saas.js?v=16",
  "./assets/js/saas.js?v=17",
  "./assets/js/saas.js?v=18",
  "./sf-pro-display/SFPRODISPLAYREGULAR.OTF",
  "./sf-pro-display/SFPRODISPLAYMEDIUM.OTF",
  "./sf-pro-display/SFPRODISPLAYBOLD.OTF",
  "./assets/icons/icon.svg",
  "./assets/icons/icon-192.svg",
  "./assets/icons/icon-512.svg",
  "./locales/en.json",
  "./locales/en.json?v=6",
  "./locales/en.json?v=7",
  "./locales/en.json?v=8",
  "./locales/en.json?v=9",
  "./locales/en.json?v=10",
  "./locales/en.json?v=11",
  "./locales/en.json?v=12",
  "./locales/en.json?v=13",
  "./locales/en.json?v=14",
  "./locales/en.json?v=15",
  "./locales/en.json?v=16",
  "./locales/en.json?v=17",
  "./locales/sw.json",
  "./locales/sw.json?v=6",
  "./locales/sw.json?v=7",
  "./locales/sw.json?v=8",
  "./locales/sw.json?v=9",
  "./locales/sw.json?v=10",
  "./locales/sw.json?v=11",
  "./locales/sw.json?v=12",
  "./locales/sw.json?v=13",
  "./locales/sw.json?v=14",
  "./locales/sw.json?v=15",
  "./locales/sw.json?v=16",
  "./locales/sw.json?v=17",
  "./manifest.json"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") {
    return;
  }

  if (new URL(event.request.url).pathname.includes("/api/")) {
    event.respondWith(fetch(event.request));
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith(
      fetch(event.request).catch(async () => {
        const url = new URL(event.request.url);
        const fallback = url.pathname.includes("dashboard")
          ? "./pages/dashboard.html"
          : url.pathname.includes("settings")
            ? "./pages/settings.html"
            : url.pathname.includes("sales")
            ? "./pages/sales.html"
            : url.pathname.includes("stock")
            ? "./pages/stock.html"
            : url.pathname.includes("bank")
            ? "./pages/bank.html"
            : url.pathname.includes("expenses")
            ? "./pages/expenses.html"
            : url.pathname.includes("realestate") || url.pathname.includes("real-estate")
            ? "./pages/realestate.html"
            : url.pathname.includes("register")
            ? "./pages/register.html"
            : url.pathname.includes("login")
              ? "./pages/login.html"
              : "./index.html";

        return (await caches.match(event.request)) || caches.match(fallback) || caches.match("./index.html");
      })
    );
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cachedResponse) => cachedResponse || fetch(event.request).catch(() => (
      new Response("", { status: 503, statusText: "Offline" })
    )))
  );
});





