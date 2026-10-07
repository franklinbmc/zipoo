/*!
 * Zipoo offline engine
 * ---------------------------------------------------------------------------
 * Makes the whole app usable without internet and syncs when it returns.
 *
 *  1. Read-through cache   Every successful GET to /api/*.php is stored in
 *                          IndexedDB. While offline the same request is served
 *                          from that copy, so every screen keeps its data.
 *  2. Pre-download         After login (and every few minutes while online) the
 *                          key data sets are downloaded in the background so
 *                          the app is ready even for screens never opened.
 *  3. Write queue          Writes made while offline are saved in an outbox,
 *                          answered instantly with an optimistic response and
 *                          reflected in every screen (stock, sales, shift,
 *                          customers, accounts...) until they are synced.
 *  4. Sync                 When the connection returns the outbox is replayed
 *                          in order. Temporary offline IDs are translated to the
 *                          real server IDs, and every write carries a unique
 *                          X-Zipoo-Op-Id so the server can never apply the same
 *                          change twice (see api/idempotency_lib.php).
 *  5. Status badge         Red OFFLINE badge while disconnected, green LIVE when
 *                          connected, blue SYNCING while the outbox is flushed.
 *
 * Load this file BEFORE app.js (it wraps window.fetch).
 */
(() => {
  "use strict";
  if (window.__zipooOffline) return;

  const nativeFetch = window.fetch.bind(window);
  const SCRIPT = document.currentScript;
  const BASE = (() => {
    try { return new URL("../../", SCRIPT.src).pathname; } catch { return "/"; }
  })();
  const API_PREFIX = `${BASE}api/`;

  /* ------------------------------------------------------------------ utils */
  const pad = (n) => String(n).padStart(2, "0");
  const sqlNow = (d = new Date()) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  const dateKey = (d = new Date()) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const num = (v) => {
    const n = parseFloat(String(v ?? "").replace(/[^0-9.\-]/g, ""));
    return Number.isFinite(n) ? n : 0;
  };
  const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  const trimNum = (n) => String(round2(n)).replace(/\.0+$/, "");
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`);
  const readJSON = (key, fallback) => {
    try { const v = JSON.parse(localStorage.getItem(key)); return v ?? fallback; } catch { return fallback; }
  };
  const clone = (v) => (v === undefined ? v : JSON.parse(JSON.stringify(v)));
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const jsonResponse = (status, body, headers = {}) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", ...headers },
    });

  const currentUser = () => readJSON("zipoo.user", {});
  const businessId = () => String(localStorage.getItem("zipoo.currentBusinessId") || currentUser().business_id || 0);
  const scope = () => `${currentUser().id || 0}:${businessId()}`;
  const isLoggedIn = () => Boolean(currentUser().id) && localStorage.getItem("zipoo.isLoggedIn") === "true";

  /* Temporary IDs for records created offline: 13-digit numbers that can never
     collide with real auto-increment IDs and survive parseInt()/> 0 checks. */
  const TEMP_BASE = 1000000000000;
  const TEMP_RE = /\b1000000\d{6}\b/g;
  const nextTempId = () => {
    const n = (Number(localStorage.getItem("zipoo.offline.tempSeq")) || 0) + 1;
    localStorage.setItem("zipoo.offline.tempSeq", String(n));
    return TEMP_BASE + n;
  };
  const nextReceiptSeq = () => {
    const key = `zipoo.offline.receiptSeq.${dateKey()}`;
    const n = (Number(localStorage.getItem(key)) || 0) + 1;
    localStorage.setItem(key, String(n));
    return n;
  };

  /* -------------------------------------------------------------- IndexedDB */
  const DB_NAME = "zipoo-offline";
  let dbPromise = null;
  let idbOk = true;
  const openDb = () => {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!window.indexedDB) { reject(new Error("IndexedDB unavailable")); return; }
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        db.createObjectStore("cache", { keyPath: "key" });
        db.createObjectStore("outbox", { keyPath: "id", autoIncrement: true });
        db.createObjectStore("meta", { keyPath: "key" });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    dbPromise.catch(() => { idbOk = false; });
    return dbPromise;
  };
  const dbReq = (store, mode, make) =>
    openDb().then((db) => new Promise((resolve, reject) => {
      const t = db.transaction(store, mode);
      const r = make(t.objectStore(store));
      t.oncomplete = () => resolve(r ? r.result : undefined);
      t.onerror = t.onabort = () => reject(t.error || new Error("IndexedDB error"));
    }));
  const dbGet = (store, key) => dbReq(store, "readonly", (s) => s.get(key));
  const dbPut = (store, val) => dbReq(store, "readwrite", (s) => s.put(val));
  const dbDel = (store, key) => dbReq(store, "readwrite", (s) => s.delete(key));
  const dbAll = (store) => dbReq(store, "readonly", (s) => s.getAll());

  /* ------------------------------------------------------------------ state */
  const state = {
    online: navigator.onLine !== false,
    syncing: false,
    authExpired: false,
    prefetching: false,
    lastSync: Number(localStorage.getItem("zipoo.offline.lastSync")) || 0,
  };
  let outbox = []; // in-memory mirror of the outbox store, ordered by id
  const outboxReady = openDb()
    .then(() => dbAll("outbox"))
    .then((rows) => { outbox = rows.sort((a, b) => a.id - b.id); })
    .catch(() => { outbox = []; });

  const pendingOps = (sc = scope()) => outbox.filter((o) => o.scope === sc && o.status === "pending");
  const failedOps = (sc = scope()) => outbox.filter((o) => o.scope === sc && o.status === "failed");
  const foreignOps = () => outbox.filter((o) => o.scope !== scope() && o.status === "pending");

  const saveOp = async (op) => {
    const id = await dbPut("outbox", op);
    op.id = id;
    if (!outbox.includes(op)) outbox.push(op);
    outbox.sort((a, b) => a.id - b.id);
    return op;
  };
  const removeOp = async (op) => {
    await dbDel("outbox", op.id);
    outbox = outbox.filter((o) => o !== op && o.id !== op.id);
  };

  /* -------------------------------------------------------------- endpoints */
  const apiInfo = (input) => {
    let u;
    try {
      u = new URL(typeof input === "string" ? input : input instanceof URL ? input.href : input.url, location.href);
    } catch { return null; }
    if (u.origin !== location.origin) return null;
    const m = u.pathname.match(/\/api\/([a-z0-9_-]+)\.php$/i);
    if (!m) return null;
    return { url: u, endpoint: m[1].toLowerCase() };
  };
  const endpointOf = (u) => (u.pathname.match(/\/api\/([a-z0-9_-]+)\.php$/i) || [])[1] || "";

  /* Never touched by the offline layer (auth, registration, SaaS admin). */
  const PASSTHROUGH = new Set([
    "login", "register", "check-registration", "verify-registration-otp", "ping",
    "saas-login", "saas-logout", "saas-password-reset", "saas-impersonate", "saas-businesses",
    "saas-locations", "saas-plans", "saas-settings", "saas-summary", "saas-users",
  ]);
  /* Writes that are safe to queue and replay later. */
  const QUEUEABLE = {
    sales: ["create_pos_sale", "create_invoice", "update_invoice", "update_status", "cancel_invoice", "uncancel_invoice", "delete_invoice"],
    shifts: ["open", "close"],
    customers: ["create", "update", "delete"],
    suppliers: ["create", "update", "delete"],
    items: ["create", "update", "delete", "adjust_stock"],
    accounts: ["create", "update", "set_default", "delete", "deposit", "withdraw", "expense", "transfer", "create_expense_category", "set_expense_category_status", "delete_expense_category"],
    purchasing: ["create_po", "receive_goods", "cancel_po", "uncancel_po"],
    warehouses: ["create", "update", "set_default", "delete", "transfer_stock"],
    realestate: ["*"],
  };
  const DEFAULT_ACTION = { customers: "create", suppliers: "create", items: "create" };
  const isQueueable = (ep, action) => {
    const list = QUEUEABLE[ep];
    return Boolean(list && (list.includes("*") || list.includes(action)));
  };
  const CRUD = {
    customers: { list: "customers", single: "customer", idKey: "customer_id", sortKey: "full_name" },
    suppliers: { list: "suppliers", single: "supplier", idKey: "supplier_id", sortKey: "name" },
    items: { list: "items", single: "item", idKey: "item_id", sortKey: "name" },
  };

  /* ------------------------------------------------------------ cache layer */
  const normUrl = (u) => {
    const p = new URLSearchParams(u.search);
    p.delete("_");
    const entries = [...p.entries()].sort((a, b) => a[0].localeCompare(b[0]));
    const q = new URLSearchParams(entries).toString();
    return u.pathname + (q ? `?${q}` : "");
  };
  const cacheKey = (u, sc) => `${sc}|${normUrl(u)}`;
  const storeCache = async (u, sc, text) => {
    if (!idbOk || sc.startsWith("0:")) return;
    try {
      await dbPut("cache", { key: cacheKey(u, sc), scope: sc, endpoint: endpointOf(u), query: u.search, body: text, ts: Date.now() });
    } catch { /* storage full or unavailable: stay online-only for this entry */ }
  };

  const textMatch = (rec, needle) => {
    const n = String(needle || "").toLowerCase();
    if (!n) return true;
    return Object.values(rec).some((v) => typeof v === "string" && v.toLowerCase().includes(n));
  };
  const filterRecords = (ep, u, list) => {
    const q = u.searchParams;
    return list.filter((r) => {
      if (q.get("q") && !textMatch(r, q.get("q"))) return false;
      if (ep === "items") {
        const type = q.get("type");
        if ((type === "product" || type === "service") && r.type !== type) return false;
        if (q.get("category") && r.category !== q.get("category")) return false;
        if (["1", "true"].includes(q.get("low_stock") || "") && !(r.type === "product" && num(r.current_stock) <= num(r.min_stock_alert))) return false;
      }
      return true;
    });
  };

  /* When the exact URL was never cached, build an answer from a broader cached
     list (e.g. any items list answers a filtered items list or one item). */
  const fallbackFor = async (u, sc, ep) => {
    const parse = (e) => { try { return JSON.parse(e.body); } catch { return null; } };
    let mine;
    try {
      mine = ((await dbAll("cache")) || []).filter((e) => e.scope === sc && e.endpoint === ep);
    } catch { return null; }
    if (!mine.length) return null;
    const q = u.searchParams;

    if (CRUD[ep]) {
      const cfg = CRUD[ep];
      let best = null;
      for (const e of mine) {
        if (new URLSearchParams(e.query).get("id")) continue;
        const d = parse(e);
        if (d && Array.isArray(d[cfg.list]) && (!best || d[cfg.list].length > best[cfg.list].length)) best = d;
      }
      if (!best) return null;
      const id = q.get("id");
      if (id) {
        const rec = best[cfg.list].find((r) => String(r.id) === String(id));
        if (!rec) return null;
        const out = { ok: true, [cfg.single]: rec };
        if (ep === "items") { out.movements = []; out.vat = best.vat || { enabled: false, rate: 0 }; }
        return out;
      }
      const filtered = filterRecords(ep, u, best[cfg.list]);
      const out = { ...best, [cfg.list]: filtered };
      if ("total" in best) out.total = filtered.length;
      return out;
    }

    if (ep === "sales" && q.get("history") === "pos") {
      let best = null;
      for (const e of mine) {
        const d = parse(e);
        if (d && Array.isArray(d.sales) && (!best || d.sales.length > best.sales.length)) best = d;
      }
      if (!best) return null;
      const from = q.get("from") || "";
      const to = q.get("to") || "";
      const needle = (q.get("q") || "").toLowerCase();
      const debtOnly = q.get("debt") === "pay_later";
      const sales = best.sales.filter((s) => {
        const day = String(s.created_at || "").slice(0, 10);
        if (from && day < from) return false;
        if (to && day > to) return false;
        if (needle && !`${s.receipt_number} ${s.customer_name}`.toLowerCase().includes(needle)) return false;
        if (debtOnly && !(s.payment_method === "pay_later" && s.status !== "cancelled" && num(s.amount_paid) < num(s.total_amount))) return false;
        return true;
      });
      const total = sales.reduce((sum, s) => (s.status === "cancelled" ? sum : sum + (debtOnly ? Math.max(0, num(s.total_amount) - num(s.amount_paid)) : num(s.total_amount))), 0);
      return { ...best, sales, stats: { count: sales.length, total: round2(total) } };
    }

    if (ep === "sales" && !q.get("id") && !q.get("history") && !q.get("action")) {
      let best = null;
      for (const e of mine) {
        if (new URLSearchParams(e.query).get("id") || new URLSearchParams(e.query).get("history")) continue;
        const d = parse(e);
        if (d && Array.isArray(d.invoices) && (!best || d.invoices.length > best.invoices.length)) best = d;
      }
      if (!best) return null;
      const status = q.get("status") || "all";
      const needle = (q.get("q") || "").toLowerCase();
      const invoices = best.invoices.filter((inv) => {
        if (status !== "all" && (inv.effective_status || inv.status) !== status) return false;
        if (needle && !`${inv.invoice_number} ${inv.customer_name}`.toLowerCase().includes(needle)) return false;
        return true;
      });
      return { ...best, invoices };
    }
    return null;
  };

  /* ------------------------------------------------- optimistic data patches */
  const pickFields = (op) => {
    const f = {};
    for (const [k, v] of op.entries) if (typeof v === "string") f[k] = v;
    return f;
  };
  const NUMERIC = new Set(["cost_price", "selling_price", "current_stock", "min_stock_alert", "opening_stock", "initial_stock", "quantity"]);
  const truthy = (v) => /^(1|on|true|yes)$/i.test(String(v ?? ""));
  const coerceFields = (ep, f) => {
    const out = {};
    for (const [k, v] of Object.entries(f)) {
      if (k === "action" || k === CRUD[ep]?.idKey || k === "id") continue;
      if (ep === "items" && NUMERIC.has(k)) out[k] = num(v);
      else if (ep === "items" && (k === "vat_applicable" || k === "tax_inclusive")) out[k] = truthy(v) ? 1 : 0;
      else out[k] = v;
    }
    return out;
  };
  const finishItem = (r) => {
    r.cost_price = num(r.cost_price);
    r.selling_price = num(r.selling_price);
    r.type = r.type || "product";
    r.current_stock = r.type === "service" ? 0 : num(r.current_stock);
    r.min_stock_alert = r.min_stock_alert === undefined ? 5 : num(r.min_stock_alert);
    r.margin_percent = r.selling_price > 0 ? Math.round(((r.selling_price - r.cost_price) / r.selling_price) * 1000) / 10 : 0;
    r.is_low_stock = r.type === "product" && r.current_stock <= r.min_stock_alert;
    return r;
  };
  const sortByKey = (list, key) => list.sort((a, b) => String(a[key] ?? "").localeCompare(String(b[key] ?? "")));
  const accountType = (method) => {
    const m = String(method || "cash").toLowerCase();
    if (m === "cash") return "cash";
    if (m === "bank" || m === "card") return "bank";
    return "mobile";
  };
  const bumpAccountBalance = (d, match, delta) => {
    if (!d || !Array.isArray(d.accounts) || !delta) return;
    const acc = typeof match === "function"
      ? d.accounts.find(match)
      : null;
    if (!acc) return;
    acc.balance = round2(num(acc.balance) + delta);
    const sum = d.summary || d.account_summary;
    if (sum) {
      if (acc.type in sum) sum[acc.type] = round2(num(sum[acc.type]) + delta);
      if ("net" in sum) sum.net = round2(num(sum.net) + delta);
    }
  };
  const bumpAccountByType = (d, type, delta) => {
    if (!d || !Array.isArray(d.accounts)) return;
    const ofType = d.accounts.filter((a) => a.type === type);
    const target = ofType.find((a) => Number(a.is_default) === 1) || ofType[0];
    if (target) bumpAccountBalance(d, (a) => a === target, delta);
  };

  const bumpStock = (d, pairs) => {
    if (!d) return d;
    const m = new Map(pairs.map(([id, q]) => [String(id), q]));
    const fix = (r, onLowChange) => {
      const q = m.get(String(r.id));
      if (q === undefined || r.type === "service") return;
      const wasLow = Boolean(r.is_low_stock);
      r.current_stock = round2(num(r.current_stock) + q);
      r.is_low_stock = r.type === "product" && r.current_stock <= num(r.min_stock_alert);
      if (onLowChange && wasLow !== r.is_low_stock && r.status !== "inactive") onLowChange(r.is_low_stock ? 1 : -1);
    };
    if (Array.isArray(d.items)) d.items.forEach((r) => fix(r, (delta) => { if (d.stats) d.stats.low_stock = Math.max(0, num(d.stats.low_stock) + delta); }));
    if (d.item) fix(d.item);
    return d;
  };

  const isHistoryRequest = (u) => u.searchParams.get("history") === "pos";
  const isInvoiceList = (u) => !u.searchParams.get("id") && !u.searchParams.get("history") && !u.searchParams.get("action");

  const PATCH = {};

  // Generic customers / suppliers / items CRUD.
  for (const [ep, cfg] of Object.entries(CRUD)) {
    PATCH[`${ep}.create`] = (d, op, u, gep) => {
      if (gep !== ep) return d;
      const rec = op.derived.record;
      const id = u.searchParams.get("id");
      if (id) {
        if (!d && String(id) === String(op.tempId)) {
          return { ok: true, [cfg.single]: clone(rec), ...(ep === "items" ? { movements: [], vat: { enabled: false, rate: 0 } } : {}) };
        }
        return d;
      }
      if (!d || !Array.isArray(d[cfg.list])) return d;
      if (d[cfg.list].some((r) => String(r.id) === String(rec.id))) return d;
      if (!filterRecords(ep, u, [rec]).length) return d;
      d[cfg.list] = sortByKey([...d[cfg.list], clone(rec)], cfg.sortKey);
      if ("total" in d) d.total = d[cfg.list].length;
      if (ep === "items" && d.stats) {
        d.stats.total = num(d.stats.total) + 1;
        d.stats[rec.type === "service" ? "services" : "products"] = num(d.stats[rec.type === "service" ? "services" : "products"]) + 1;
        if (rec.is_low_stock) d.stats.low_stock = num(d.stats.low_stock) + 1;
        if (Array.isArray(d.categories) && rec.category && !d.categories.includes(rec.category)) d.categories = [...d.categories, rec.category].sort();
      }
      return d;
    };
    PATCH[`${ep}.update`] = (d, op, u, gep) => {
      if (gep !== ep || !d) return d;
      const id = String(op.derived.id);
      const apply = (r) => {
        Object.assign(r, op.derived.changes);
        if (ep === "items") finishItem(r);
      };
      if (Array.isArray(d[cfg.list])) d[cfg.list].forEach((r) => { if (String(r.id) === id) apply(r); });
      if (d[cfg.single] && String(d[cfg.single].id) === id) apply(d[cfg.single]);
      return d;
    };
    PATCH[`${ep}.delete`] = (d, op, u, gep) => {
      if (gep !== ep || !d) return d;
      const id = String(op.derived.id);
      if (Array.isArray(d[cfg.list])) {
        d[cfg.list] = d[cfg.list].filter((r) => String(r.id) !== id);
        if ("total" in d) d.total = d[cfg.list].length;
      }
      return d;
    };
  }

  PATCH["items.adjust_stock"] = (d, op, u, gep) => {
    if (gep !== "items") return d;
    const f = pickFields(op);
    const qty = Math.abs(num(f.quantity));
    return bumpStock(d, [[f.item_id, f.adjustment_type === "subtract" ? -qty : qty]]);
  };

  PATCH["sales.create_pos_sale"] = (d, op, u, gep) => {
    const s = op.derived;
    if (gep === "items") return bumpStock(d, s.lines.filter((l) => l.type !== "service").map((l) => [l.item_id, -l.quantity]));
    if (gep === "customers" && s.customer_id > 0 && d) {
      const bump = (c) => { c.total_sales = round2(num(c.total_sales) + s.total_amount); c.sales_count = num(c.sales_count) + 1; };
      if (Array.isArray(d.customers)) d.customers.forEach((c) => { if (String(c.id) === String(s.customer_id)) bump(c); });
      if (d.customer && String(d.customer.id) === String(s.customer_id)) bump(d.customer);
      return d;
    }
    if (gep === "shifts" && d) {
      const action = u.searchParams.get("action") || "current";
      const bumpShift = (sh) => {
        if (!sh || sh.status !== "open") return;
        sh.sales_total = round2(num(sh.sales_total) + s.total_amount);
        sh.sales_count = num(sh.sales_count) + 1;
        if (accountType(s.payment_method) === "cash") sh.cash_sales = round2(num(sh.cash_sales) + s.settled);
        sh.expected_cash = round2(num(sh.opening_balance) + num(sh.cash_sales));
      };
      if (action === "current") {
        bumpShift(d.shift);
        if (s.settled > 0 && !s.pay_later) bumpAccountByType(d, accountType(s.payment_method), s.settled);
      }
      if (action === "list" && Array.isArray(d.shifts)) bumpShift(d.shifts.find((x) => x.status === "open"));
      return d;
    }
    if (gep === "accounts" && d && !u.searchParams.get("action") && s.settled > 0 && !s.pay_later) {
      bumpAccountByType(d, accountType(s.payment_method), s.settled);
      return d;
    }
    if (gep === "sales") {
      const id = u.searchParams.get("id");
      if (id) {
        if (!d && String(id) === String(s.sale_id)) {
          return {
            ok: true,
            invoice: {
              id: s.sale_id, sale_type: "pos", invoice_number: s.receipt_number, receipt_number: s.receipt_number,
              customer_id: s.customer_id || null, customer_name: s.customer_name, status: s.status,
              issue_date: dateKey(new Date(s.created_at.replace(" ", "T"))), created_at: s.created_at,
              subtotal: s.subtotal, tax_rate: s.tax_rate, tax_amount: s.tax_amount, discount: 0,
              total_amount: s.total_amount, amount_paid: s.settled, payment_method: s.payment_method,
              items_count: s.lines.length, notes: s.pay_later ? "POS sale (pay later)" : `POS sale (${s.payment_method})`,
              items: s.lines.map((l, i) => ({ id: i + 1, item_id: l.item_id, item_name: l.item_name, quantity: l.quantity, unit_price: l.unit_price, line_total: l.line_total })),
              customer: null, payments: [],
            },
          };
        }
        return d;
      }
      if (!d) return d;
      if (isHistoryRequest(u) && Array.isArray(d.sales)) {
        const q = u.searchParams;
        const from = q.get("from") || "";
        const to = q.get("to") || "";
        const day = s.created_at.slice(0, 10);
        if ((from && day < from) || (to && day > to)) return d;
        if (q.get("q") && !`${s.receipt_number} ${s.customer_name}`.toLowerCase().includes(q.get("q").toLowerCase())) return d;
        if (q.get("debt") === "pay_later" && !s.pay_later) return d;
        d.sales = [{
          id: s.sale_id, receipt_number: s.receipt_number, customer_name: s.customer_name, total_amount: s.total_amount,
          amount_paid: s.settled, amount_due: Math.max(0, round2(s.total_amount - s.settled)), payment_method: s.payment_method,
          status: s.status, cashier: currentUser().full_name || "", items_count: s.lines.reduce((n, l) => n + l.quantity, 0),
          created_at: s.created_at, pending_sync: true,
        }, ...d.sales];
        if (d.stats) {
          d.stats.count = d.sales.length;
          d.stats.total = round2(num(d.stats.total) + (q.get("debt") === "pay_later" ? s.total_amount - s.settled : s.total_amount));
        }
        return d;
      }
      if (isInvoiceList(u) && d.stats && s.pay_later) {
        d.stats.pay_later_due = round2(num(d.stats.pay_later_due) + s.total_amount);
        d.stats.pay_later_count = num(d.stats.pay_later_count) + 1;
        d.stats.amount_due = round2(num(d.stats.amount_due) + s.total_amount);
        d.stats.overdue = num(d.stats.overdue) + 1;
      }
    }
    return d;
  };

  PATCH["shifts.open"] = (d, op, u, gep) => {
    if (gep !== "shifts" || !d) return d;
    const shift = clone(op.derived.shift);
    const action = u.searchParams.get("action") || "current";
    if (action === "current") d.shift = shift;
    if (action === "list" && Array.isArray(d.shifts)) d.shifts = [{ ...shift, cashier: currentUser().full_name || "" }, ...d.shifts];
    return d;
  };
  PATCH["shifts.close"] = (d, op, u, gep) => {
    if (gep !== "shifts" || !d) return d;
    const closed = clone(op.derived.closedShift);
    const action = u.searchParams.get("action") || "current";
    if (action === "current") d.shift = null;
    if (action === "list" && Array.isArray(d.shifts)) {
      const i = d.shifts.findIndex((x) => String(x.id) === String(closed.id));
      if (i >= 0) d.shifts[i] = { ...d.shifts[i], ...closed };
      else d.shifts = [{ ...closed, cashier: currentUser().full_name || "" }, ...d.shifts];
    }
    return d;
  };

  const patchAccountMoney = (d, op, u, gep) => {
    const f = pickFields(op);
    const sign = op.action === "deposit" ? 1 : -1;
    const amount = num(f.amount);
    if (gep === "accounts" && d && !u.searchParams.get("action")) {
      bumpAccountBalance(d, (a) => String(a.id) === String(f.account_id), sign * amount);
    }
    if (gep === "shifts" && d && (u.searchParams.get("action") || "current") === "current") {
      bumpAccountBalance(d, (a) => String(a.id) === String(f.account_id), sign * amount);
    }
    if (op.action === "expense" && gep === "accounts" && u.searchParams.get("action") === "expenses" && d && Array.isArray(d.expenses)) {
      d.expenses = [clone(op.derived.record), ...d.expenses];
    }
    return d;
  };
  PATCH["accounts.deposit"] = patchAccountMoney;
  PATCH["accounts.withdraw"] = patchAccountMoney;
  PATCH["accounts.expense"] = patchAccountMoney;

  const applyOne = (op, u, d) => {
    const fn = PATCH[`${op.endpoint}.${op.action}`];
    if (!fn) return d;
    try {
      const r = fn(d, op, u, endpointOf(u));
      return r === undefined ? d : r;
    } catch (e) {
      console.warn("[zipoo-offline] patch failed", op.endpoint, op.action, e);
      return d;
    }
  };
  const applyPending = (u, data, sc) => {
    let d = data;
    for (const op of pendingOps(sc)) d = applyOne(op, u, d);
    return d;
  };

  const readLocal = async (u, sc) => {
    let data = null;
    try {
      const hit = await dbGet("cache", cacheKey(u, sc));
      if (hit) data = JSON.parse(hit.body);
    } catch { /* fall through */ }
    if (!data) data = await fallbackFor(u, sc, endpointOf(u));
    return applyPending(u, data, sc);
  };
  const peek = (path, sc) => readLocal(new URL(API_PREFIX + path, location.origin), sc);

  /* ------------------------------------------- optimistic builders (on queue) */
  const OFFLINE_MSG = "You are offline and this information has not been saved on this device yet. Open it once while online.";
  const NEEDS_NET_MSG = "This action needs an internet connection. Please try again when you are back online.";
  const fail = (status, message) => ({ error: { status, message } });

  const BUILDERS = {};

  for (const [ep, cfg] of Object.entries(CRUD)) {
    BUILDERS[`${ep}.create`] = async (op, f, sc) => {
      if (ep === "customers") {
        if (!String(f.full_name || "").trim()) return fail(422, "Customer name is required.");
        if (f.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email)) return fail(422, "Please provide a valid email address.");
      }
      if (ep === "items" && !String(f.name || "").trim()) return fail(422, "Item name is required.");
      const tempId = nextTempId();
      const now = sqlNow();
      let record = { id: tempId, business_id: Number(businessId()), ...coerceFields(ep, f), created_at: now, updated_at: now, pending_sync: true };
      if (ep === "customers") {
        record = { full_name: "", phone: "", email: "", address: "", tin: "", vrn: "", notes: "", total_sales: 0, sales_count: 0, ...record };
      }
      if (ep === "items") {
        record = {
          type: "product", sku: "", barcode: "", category: "General", unit: "pcs", description: "", status: "active", vat_applicable: 1, tax_inclusive: 0,
          ...record,
          current_stock: num(f.current_stock ?? f.opening_stock ?? f.initial_stock ?? 0),
        };
        finishItem(record);
      }
      const noun = cfg.single.charAt(0).toUpperCase() + cfg.single.slice(1);
      return {
        tempId, derived: { record }, status: 201, label: `New ${cfg.single}: ${record.full_name || record.name || ""}`.trim(),
        response: { ok: true, offline: true, message: `${noun} saved offline. It will sync when you are back online.`, [cfg.single]: record },
      };
    };
    BUILDERS[`${ep}.update`] = async (op, f, sc) => {
      const id = f[cfg.idKey] || f.id;
      const changes = coerceFields(ep, f);
      const existing = (await peek(`${ep}.php?id=${encodeURIComponent(id)}`, sc))?.[cfg.single] || { id: Number(id) };
      const record = { ...clone(existing), ...changes };
      if (ep === "items") finishItem(record);
      return {
        derived: { id, changes }, status: 200, label: `Update ${cfg.single}: ${record.full_name || record.name || id}`,
        response: { ok: true, offline: true, message: `${cfg.single.charAt(0).toUpperCase() + cfg.single.slice(1)} updated offline. It will sync when you are back online.`, [cfg.single]: record },
      };
    };
    BUILDERS[`${ep}.delete`] = async (op, f) => {
      const id = f[cfg.idKey] || f.id;
      return { derived: { id }, status: 200, label: `Delete ${cfg.single} #${id}`, response: { ok: true, offline: true, message: `${cfg.single.charAt(0).toUpperCase() + cfg.single.slice(1)} deleted offline. It will sync when you are back online.` } };
    };
  }

  BUILDERS["items.adjust_stock"] = async (op, f, sc) => {
    const qty = Math.abs(num(f.quantity));
    if (!(qty > 0)) return fail(422, "Enter a quantity greater than zero.");
    const view = await peek(`items.php?id=${encodeURIComponent(f.item_id)}`, sc);
    const item = clone(view?.item || { id: Number(f.item_id), type: "product", current_stock: 0, min_stock_alert: 5 });
    const delta = f.adjustment_type === "subtract" ? -qty : qty;
    if (item.current_stock + delta < 0) return fail(422, "Stock cannot go below zero.");
    item.current_stock = round2(num(item.current_stock) + delta);
    finishItem(item);
    return {
      derived: {}, status: 200, label: `Stock ${delta > 0 ? "+" : ""}${trimNum(delta)}: ${item.name || `#${f.item_id}`}`,
      response: { ok: true, offline: true, message: `Stock adjusted offline. New stock: ${trimNum(item.current_stock)}`, item },
    };
  };

  BUILDERS["sales.create_pos_sale"] = async (op, f, sc) => {
    let items;
    try { items = JSON.parse(f.items || "[]"); } catch { items = []; }
    if (!Array.isArray(items) || !items.length) return fail(422, "Add at least one product to the cart.");

    const itemsView = await peek("items.php", sc);
    const catalogue = new Map(((itemsView && itemsView.items) || []).map((i) => [String(i.id), i]));
    const vat = (itemsView && itemsView.vat) || { enabled: false, rate: 0 };
    const rate = vat.enabled ? num(vat.rate) : 0;

    const shiftView = await peek("shifts.php?action=current", sc);
    if (shiftView && !shiftView.shift) return fail(422, "Start a shift before making sales.");

    let subtotal = 0;
    let taxableNet = 0;
    const lines = [];
    for (const it of items) {
      const rec = catalogue.get(String(it.item_id));
      const qty = num(it.quantity);
      const price = num(it.unit_price);
      if (!rec || !(qty > 0)) continue;
      if (rec.type !== "service" && num(rec.current_stock) < qty) {
        return fail(422, `Not enough stock for "${rec.name}". Available: ${trimNum(rec.current_stock)}.`);
      }
      const gross = round2(qty * price);
      const lineVat = vat.enabled && Number(rec.vat_applicable ?? 1) === 1 ? 1 : 0;
      const incl = lineVat && Number(rec.tax_inclusive ?? 0) === 1 ? 1 : 0;
      const net = incl && rate > 0 ? gross / (1 + rate / 100) : gross;
      subtotal += net;
      if (lineVat) taxableNet += net;
      lines.push({ item_id: rec.id, item_name: rec.name, type: rec.type, quantity: qty, unit_price: price, line_total: gross });
    }
    if (!lines.length) return fail(422, "No valid products in the cart.");

    subtotal = round2(subtotal);
    const tax = round2((taxableNet * rate) / 100);
    const total = round2(subtotal + tax);
    const method = String(f.payment_method || "cash");
    const payLater = ["pay_later", "paylater", "credit"].includes(method.toLowerCase());
    const paidIn = f.amount_paid !== undefined ? num(f.amount_paid) : null;
    const amountPaid = payLater ? 0 : paidIn !== null && paidIn >= total ? paidIn : total;
    const settled = Math.min(amountPaid, total);
    const change = Math.max(0, round2(amountPaid - total));

    const customerId = Number(f.customer_id || 0) || 0;
    let customerName = "Walk-in Customer";
    if (customerId > 0) {
      const cview = await peek("customers.php", sc);
      const c = ((cview && cview.customers) || []).find((x) => String(x.id) === String(customerId));
      if (c) customerName = c.full_name;
    }

    const tempId = nextTempId();
    const now = new Date();
    const receipt = `OFF-${dateKey(now).replace(/-/g, "")}-${String(nextReceiptSeq()).padStart(3, "0")}`;
    const created = sqlNow(now);
    // The device clock is the source of truth for when the sale happened.
    op.entries.push(["client_created_at", created]);

    return {
      tempId, status: 201, label: `Sale ${receipt} · ${Math.round(total).toLocaleString()}`,
      derived: {
        sale_id: tempId, receipt_number: receipt, customer_id: customerId, customer_name: customerName, payment_method: method.toLowerCase() === "paylater" ? "pay_later" : method,
        pay_later: payLater, status: payLater ? "sent" : "paid", subtotal, tax_rate: rate, tax_amount: tax, total_amount: total, settled, lines, created_at: created,
      },
      response: {
        ok: true, offline: true, message: `Sale ${receipt} completed (saved offline).`, sale_id: tempId, receipt_number: receipt,
        subtotal, tax_rate: rate, tax_amount: tax, total_amount: total, amount_paid: amountPaid, change_due: change,
      },
    };
  };

  BUILDERS["shifts.open"] = async (op, f, sc) => {
    const view = await peek("shifts.php?action=current", sc);
    if (view && view.shift && view.shift.status === "open") {
      return fail(422, "You already have an open shift. Close it before starting a new one.");
    }
    const opening = Math.max(0, round2(num(f.opening_balance)));
    const tempId = nextTempId();
    const shift = {
      id: tempId, status: "open", opened_at: sqlNow(), opening_balance: opening, closed_at: null, closing_balance: null,
      expected_cash: opening, cash_sales: 0, sales_total: 0, sales_count: 0, variance: null, notes: "",
    };
    return { tempId, derived: { shift }, status: 201, label: "Start shift", response: { ok: true, offline: true, message: "Shift started (saved offline).", shift } };
  };

  BUILDERS["shifts.close"] = async (op, f, sc) => {
    const view = await peek("shifts.php?action=current", sc);
    if (!view || !view.shift || view.shift.status !== "open") return fail(422, "No open shift to close.");
    const sh = view.shift;
    const closing = round2(num(f.closing_balance));
    const expected = round2(num(sh.opening_balance) + num(sh.cash_sales));
    const variance = round2(closing - expected);
    const closedShift = { ...sh, status: "closed", closed_at: sqlNow(), closing_balance: closing, expected_cash: expected, variance, notes: (f.notes || "").trim() };
    const summary = {
      shift_id: sh.id, opening_balance: num(sh.opening_balance), cash_sales: num(sh.cash_sales), expected_cash: expected,
      closing_balance: closing, variance, sales_total: num(sh.sales_total), sales_count: num(sh.sales_count),
    };
    return {
      derived: { closedShift }, status: 200, label: "Close shift",
      response: { ok: true, offline: true, message: "Shift closed (saved offline).", summary, accounts: view.accounts || [], account_summary: view.summary || {} },
    };
  };

  const buildAccountMoney = async (op, f, sc) => {
    const amount = round2(num(f.amount));
    if (!(amount > 0)) return fail(422, "Enter an amount greater than zero.");
    const accountsView = await peek("accounts.php", sc);
    const account = ((accountsView && accountsView.accounts) || []).find((a) => String(a.id) === String(f.account_id));
    let record = null;
    if (op.action === "expense") {
      const catView = await peek("accounts.php?action=expense_categories", sc);
      const cat = ((catView && catView.categories) || []).find((c) => String(c.id) === String(f.category_id));
      record = {
        id: nextTempId(), type: "expense", direction: "out", amount, notes: f.notes || "", created_at: sqlNow(), account_id: Number(f.account_id),
        account_name: account ? account.name : "Account", account_type: account ? account.type : "cash", expense_category_id: Number(f.category_id) || null,
        category_name: cat ? cat.name : null, category_status: cat ? cat.status : "active", pending_sync: true,
      };
    }
    const derived = { record };
    const patched = accountsView ? applyOne({ endpoint: "accounts", action: op.action, entries: op.entries, derived }, new URL(`${API_PREFIX}accounts.php`, location.origin), clone(accountsView)) : { accounts: [], summary: {} };
    const labels = { deposit: "Deposit", withdraw: "Withdrawal", expense: "Expense" };
    return {
      derived, status: 200, label: `${labels[op.action]} · ${Math.round(amount).toLocaleString()}`,
      response: { ok: true, offline: true, message: "Saved offline. It will sync when you are back online.", accounts: patched.accounts || [], summary: patched.summary || {} },
    };
  };
  BUILDERS["accounts.deposit"] = buildAccountMoney;
  BUILDERS["accounts.withdraw"] = buildAccountMoney;
  BUILDERS["accounts.expense"] = buildAccountMoney;

  const humanAction = (ep, action) => `${action.replace(/_/g, " ")} (${ep})`;
  const buildOptimistic = async (op, sc) => {
    const f = pickFields(op);
    const builder = BUILDERS[`${op.endpoint}.${op.action}`];
    if (builder) return builder(op, f, sc);
    return {
      derived: {}, status: 200, label: humanAction(op.endpoint, op.action),
      response: { ok: true, offline: true, queued: true, message: "Saved offline. It will be applied when you are back online." },
    };
  };

  /* --------------------------------------------------------------- the wrapper */
  const toEntries = (body) => {
    if (body instanceof FormData) return [...body.entries()];
    if (body instanceof URLSearchParams) return [...body.entries()];
    if (typeof body === "string") return [...new URLSearchParams(body).entries()];
    return null;
  };
  const toFormData = (entries, subst) => {
    const fd = new FormData();
    for (const [k, v] of entries) {
      if (typeof v === "string") fd.append(k, subst(v));
      else fd.append(k, v, v.name || "file");
    }
    return fd;
  };
  const timedFetch = async (input, init, ms) => {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), ms);
    try {
      return await nativeFetch(input, { ...init, signal: ctl.signal });
    } finally { clearTimeout(t); }
  };
  const isNetworkError = (e) => e instanceof TypeError || (e && e.name === "AbortError");

  const handleGet = async (input, init, info) => {
    const sc = scope();
    const u = info.url;
    if (state.online || !idbOk) {
      try {
        const res = await timedFetch(input, init, 15000);
        if (res.ok && !sc.startsWith("0:") && /json/i.test(res.headers.get("Content-Type") || "")) {
          const text = await res.clone().text();
          let parsed = null;
          try { parsed = JSON.parse(text); } catch { /* not json */ }
          if (parsed && parsed.ok !== false) {
            await outboxReady;
            storeCache(u, sc, text);
            if (pendingOps(sc).length) {
              return jsonResponse(200, applyPending(u, parsed, sc), { "X-Zipoo-Source": "network+pending" });
            }
          }
        }
        markOnline();
        return res;
      } catch (e) {
        if (!isNetworkError(e)) throw e;
        markOffline();
      }
    }
    await outboxReady;
    const data = idbOk ? await readLocal(u, sc) : null;
    if (data) return jsonResponse(200, data, { "X-Zipoo-Source": "offline" });
    return jsonResponse(503, { ok: false, offline: true, message: OFFLINE_MSG });
  };

  const handleWrite = async (input, init, info) => {
    const { url: u, endpoint: ep } = info;
    const sc = scope();
    const entries = toEntries(init.body);
    if (!entries) return nativeFetch(input, init);
    const action = String((entries.find((e) => e[0] === "action") || [])[1] || DEFAULT_ACTION[ep] || "");

    if (!idbOk || sc.startsWith("0:") || !isQueueable(ep, action)) {
      if (state.online) {
        try { return await nativeFetch(input, init); } catch (e) { if (!isNetworkError(e)) throw e; markOffline(); }
      }
      return jsonResponse(503, { ok: false, offline: true, message: NEEDS_NET_MSG });
    }

    await outboxReady;
    // Keep strict ordering: flush anything already waiting before sending directly.
    if (state.online && pendingOps(sc).length) await syncNow();

    const opId = uuid();
    if (state.online && !pendingOps(sc).length) {
      try {
        const headers = new Headers(init.headers || {});
        headers.set("X-Zipoo-Op-Id", opId);
        const res = await timedFetch(input, { ...init, headers }, 30000);
        markOnline();
        return res;
      } catch (e) {
        if (!isNetworkError(e)) throw e;
        markOffline(); // the same opId is reused below, so a replay can never double-apply
      }
    }

    const op = {
      opId, scope: sc, endpoint: ep, action, url: u.pathname + u.search, method: "POST",
      entries: entries.map(([k, v]) => [k, v]), createdAt: Date.now(), status: "pending", attempts: 0, error: "",
    };
    const built = await buildOptimistic(op, sc);
    if (built.error) return jsonResponse(built.error.status, { ok: false, offline: true, message: built.error.message });
    op.tempId = built.tempId || null;
    op.derived = built.derived || {};
    op.label = built.label || humanAction(ep, action);
    try {
      await saveOp(op);
    } catch {
      return jsonResponse(507, { ok: false, offline: true, message: "Could not save this change on the device (storage full or blocked)." });
    }
    renderBadge();
    toast("Saved offline — it will sync automatically when you are back online.", "offline");
    return jsonResponse(built.status || 200, built.response);
  };

  window.fetch = function zipooFetch(input, init = {}) {
    try {
      if (typeof input !== "string" && !(input instanceof URL)) return nativeFetch(input, init);
      const info = apiInfo(input);
      if (!info || PASSTHROUGH.has(info.endpoint)) {
        if (info && !state.online && info.endpoint !== "ping" && !info.endpoint.startsWith("saas-")) {
          return nativeFetch(input, init).catch((e) => {
            if (!isNetworkError(e)) throw e;
            return jsonResponse(503, { ok: false, offline: true, message: "No internet connection. Please connect and try again." });
          });
        }
        return nativeFetch(input, init);
      }
      const method = String(init.method || "GET").toUpperCase();
      if (method === "GET") return handleGet(input, init, info);
      if (method === "POST") return handleWrite(input, init, info);
      return nativeFetch(input, init);
    } catch (e) {
      return nativeFetch(input, init);
    }
  };

  /* -------------------------------------------------------------------- sync */
  let syncPromise = null;
  let retryTimer = null;
  const substituteIds = async () => {
    const map = (await dbGet("meta", "idmap"))?.value || {};
    return { map, fn: (s) => String(s).replace(TEMP_RE, (m) => (map[m] !== undefined ? String(map[m]) : m)) };
  };
  const extractId = (json) => {
    if (!json || typeof json !== "object") return null;
    for (const k of ["sale_id", "invoice_id", "po_id", "id"]) if (Number(json[k]) > 0) return Number(json[k]);
    for (const v of Object.values(json)) if (v && typeof v === "object" && !Array.isArray(v) && Number(v.id) > 0) return Number(v.id);
    return null;
  };

  const syncNow = () => {
    if (syncPromise) return syncPromise;
    syncPromise = runSync().finally(() => { syncPromise = null; renderBadge(); });
    return syncPromise;
  };

  async function runSync() {
    await outboxReady;
    const sc = scope();
    if (!state.online || sc.startsWith("0:")) return;
    const queue = pendingOps(sc);
    if (!queue.length) return;

    state.syncing = true;
    state.authExpired = false;
    renderBadge();
    let applied = 0;
    let stoppedEarly = false;
    const { map, fn: subst } = await substituteIds();

    for (const op of queue) {
      const joined = op.url + op.entries.map(([, v]) => (typeof v === "string" ? v : "")).join(" ");
      const unresolved = (joined.match(TEMP_RE) || []).filter((m) => map[m] === undefined);
      if (unresolved.length) {
        op.status = "failed";
        op.error = "Depends on an earlier offline change that could not be synced.";
        await saveOp(op);
        continue;
      }
      try {
        const headers = new Headers({ "X-Zipoo-Op-Id": op.opId });
        const res = await timedFetch(subst(op.url), { method: "POST", body: toFormData(op.entries, subst), headers }, 45000);
        let payload = null;
        try { payload = await res.clone().json(); } catch { /* non-json */ }
        if (res.ok) {
          if (op.tempId) {
            const realId = extractId(payload);
            if (realId) {
              map[String(op.tempId)] = realId;
              await dbPut("meta", { key: "idmap", value: map });
            }
          }
          await removeOp(op);
          applied++;
        } else if (res.status === 401) {
          state.authExpired = true;
          stoppedEarly = true;
          break;
        } else if (res.status >= 500 || res.status === 408 || res.status === 429) {
          op.attempts = (op.attempts || 0) + 1;
          op.error = (payload && payload.message) || `Server error (${res.status})`;
          if (op.attempts >= 5) op.status = "failed";
          await saveOp(op);
          stoppedEarly = true;
          break;
        } else {
          op.status = "failed";
          op.error = (payload && payload.message) || `Rejected by the server (${res.status})`;
          await saveOp(op);
        }
      } catch (e) {
        if (isNetworkError(e)) { markOffline(); stoppedEarly = true; break; }
        op.attempts = (op.attempts || 0) + 1;
        op.error = String(e && e.message || e);
        await saveOp(op);
        stoppedEarly = true;
        break;
      }
      renderBadge();
    }

    state.syncing = false;
    if (applied) {
      state.lastSync = Date.now();
      localStorage.setItem("zipoo.offline.lastSync", String(state.lastSync));
    }
    renderBadge();

    if (state.authExpired) {
      toast("Your session expired. Log in again to finish syncing your offline work.", "error", { href: `${BASE}login`, label: "Log in" });
    }
    if (stoppedEarly && state.online && !state.authExpired) {
      clearTimeout(retryTimer);
      retryTimer = setTimeout(syncNow, 20000);
    }
    if (!stoppedEarly) {
      const failed = failedOps(sc).length;
      if (applied && !failed) toast(`All offline changes synced (${applied}).`, "ok");
      else if (failed) toast(`${failed} offline change${failed > 1 ? "s" : ""} need attention. Tap the status badge.`, "error");
      if (!pendingOps(sc).length) await prefetch(true);
      if (applied) offerRefresh();
    }
    window.dispatchEvent(new CustomEvent("zipoo:synced", { detail: { applied } }));
  }

  /* ---------------------------------------------------------------- prefetch */
  const PREFETCH = () => {
    const today = dateKey();
    const from = dateKey(new Date(Date.now() - 90 * 86400000));
    return [
      "account.php", "businesses.php", "locations.php", "settings.php",
      "items.php", "customers.php", "suppliers.php",
      "shifts.php?action=current", "shifts.php?action=list",
      "accounts.php", "accounts.php?action=expenses", "accounts.php?action=expense_categories", "accounts.php?action=activity",
      "sales.php", `sales.php?history=pos&from=${from}&to=${today}`, `sales.php?history=pos&from=${today}&to=${today}`,
      "warehouses.php", "purchasing.php", "stock-movements.php", "company-users.php",
    ];
  };
  async function prefetch(force = false) {
    if (!state.online || state.prefetching || !isLoggedIn() || !idbOk) return;
    await outboxReady;
    const sc = scope();
    if (pendingOps(sc).length) return;
    const key = `zipoo.offline.prefetch.${sc}`;
    if (!force && Date.now() - (Number(localStorage.getItem(key)) || 0) < 5 * 60000) return;
    state.prefetching = true;
    renderBadge();
    let ok = 0;
    try {
      for (const path of PREFETCH()) {
        if (!state.online) break;
        try {
          const u = new URL(API_PREFIX + path, location.origin);
          const res = await timedFetch(u.href, { credentials: "same-origin" }, 20000);
          if (res.status === 401) break;
          if (res.ok && /json/i.test(res.headers.get("Content-Type") || "")) {
            const text = await res.text();
            const parsed = JSON.parse(text);
            if (parsed && parsed.ok !== false) { await storeCache(u, sc, text); ok++; }
          }
        } catch (e) {
          if (isNetworkError(e)) { markOffline(); break; }
        }
      }
      if (ok) localStorage.setItem(key, String(Date.now()));
    } finally {
      state.prefetching = false;
      renderBadge();
    }
  }

  /* ------------------------------------------------------------ connectivity */
  let hbTimer = null;
  const probe = async () => {
    try {
      const res = await timedFetch(`${BASE}api/ping.php?_=${Date.now()}`, { cache: "no-store" }, 6000);
      return res.ok;
    } catch { return false; }
  };
  const scheduleHeartbeat = () => {
    clearTimeout(hbTimer);
    hbTimer = setTimeout(async () => {
      (await probe()) ? markOnline() : markOffline();
      scheduleHeartbeat();
    }, state.online ? 25000 : 4000);
  };
  function markOffline() {
    if (!state.online) return;
    state.online = false;
    document.documentElement.classList.add("zipoo-is-offline");
    renderBadge();
    toast("You are offline. You can keep working — changes are saved on this device.", "offline");
    window.dispatchEvent(new CustomEvent("zipoo:connection", { detail: { online: false } }));
    scheduleHeartbeat();
  }
  function markOnline() {
    if (state.online) return;
    state.online = true;
    document.documentElement.classList.remove("zipoo-is-offline");
    renderBadge();
    window.dispatchEvent(new CustomEvent("zipoo:connection", { detail: { online: true } }));
    scheduleHeartbeat();
    const n = pendingOps().length;
    toast(n ? `Back online — syncing ${n} change${n > 1 ? "s" : ""}…` : "You are back online.", "ok");
    syncNow().then(() => prefetch());
  }

  /* ---------------------------------------------------------------------- UI */
  const STYLE = `
  .zc-badge{position:fixed;top:calc(env(safe-area-inset-top,0px) + 3px);left:50%;transform:translateX(-50%);z-index:100000;display:inline-flex;align-items:center;gap:6px;height:20px;padding:0 9px;border:0;border-radius:999px;font:800 10.5px/1 "SF Pro Display",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;letter-spacing:.06em;color:#fff;cursor:pointer;box-shadow:0 2px 10px rgba(13,43,91,.22);transition:background .25s ease,box-shadow .25s ease,transform .25s ease;-webkit-tap-highlight-color:transparent}
  .zc-badge:active{transform:translateX(-50%) scale(.96)}
  .zc-badge[data-state="live"]{background:#0BBF9A;box-shadow:0 2px 10px rgba(11,191,154,.4)}
  .zc-badge[data-state="offline"]{background:#DC2626;box-shadow:0 2px 12px rgba(220,38,38,.45)}
  .zc-badge[data-state="syncing"]{background:#1477FF;box-shadow:0 2px 12px rgba(20,119,255,.4)}
  .zc-dot{width:7px;height:7px;border-radius:50%;background:#fff;position:relative;flex:none}
  .zc-badge[data-state="live"] .zc-dot::after,.zc-badge[data-state="offline"] .zc-dot::after{content:"";position:absolute;inset:-4px;border-radius:50%;border:2px solid #fff;opacity:0;animation:zc-pulse 2s ease-out infinite}
  .zc-badge[data-state="offline"] .zc-dot::after{animation-duration:1.2s}
  .zc-badge[data-state="syncing"] .zc-dot{border:2px solid rgba(255,255,255,.45);border-top-color:#fff;background:transparent;animation:zc-spin .8s linear infinite}
  @keyframes zc-pulse{0%{transform:scale(.5);opacity:.8}100%{transform:scale(1.5);opacity:0}}
  @keyframes zc-spin{to{transform:rotate(360deg)}}
  .zc-count{display:inline-grid;place-items:center;min-width:15px;height:15px;padding:0 4px;border-radius:999px;background:rgba(255,255,255,.28);font-size:9.5px}
  .zc-panel{position:fixed;top:calc(env(safe-area-inset-top,0px) + 30px);left:50%;transform:translateX(-50%);z-index:100000;width:min(360px,calc(100vw - 20px));max-height:min(70vh,520px);overflow:auto;background:#fff;color:#12233F;border-radius:16px;box-shadow:0 18px 50px rgba(13,43,91,.28);border:1px solid #DBE3EF;font:500 13px/1.4 "SF Pro Display",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .zc-panel[hidden]{display:none}
  .zc-panel header{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid #EEF2F8}
  .zc-panel header strong{font-size:14px;color:#0D2B5B}
  .zc-panel header small{display:block;color:#6B7280;font-weight:500;margin-top:2px}
  .zc-pill{width:10px;height:10px;border-radius:50%;flex:none}
  .zc-section{padding:12px 16px;border-bottom:1px solid #EEF2F8}
  .zc-section:last-child{border-bottom:0}
  .zc-section h4{margin:0 0 8px;font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:#6B7280}
  .zc-row{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:7px 0}
  .zc-row+.zc-row{border-top:1px dashed #E6ECF5}
  .zc-row span{min-width:0;overflow-wrap:anywhere}
  .zc-row em{display:block;font-style:normal;color:#DC2626;font-size:12px;margin-top:2px}
  .zc-row time{color:#6B7280;font-size:11.5px;white-space:nowrap}
  .zc-actions{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px}
  .zc-btn{appearance:none;border:1px solid #DBE3EF;background:#F6F8FC;color:#0D2B5B;border-radius:10px;padding:8px 12px;font:700 12px/1 inherit;cursor:pointer}
  .zc-btn.primary{background:#1477FF;border-color:#1477FF;color:#fff}
  .zc-btn.danger{color:#DC2626}
  .zc-btn:disabled{opacity:.5;cursor:not-allowed}
  .zc-mini{display:flex;gap:6px;margin-top:6px}
  .zc-mini .zc-btn{padding:6px 9px;font-size:11px}
  .zc-toasts{position:fixed;left:50%;bottom:calc(env(safe-area-inset-bottom,0px) + 84px);transform:translateX(-50%);z-index:100001;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none;width:min(420px,calc(100vw - 24px))}
  .zc-toast{pointer-events:auto;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;color:#fff;font:600 12.5px/1.35 "SF Pro Display",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;box-shadow:0 10px 30px rgba(13,43,91,.3);animation:zc-in .25s ease both;max-width:100%}
  .zc-toast.ok{background:#0B8F75}.zc-toast.offline{background:#B91C1C}.zc-toast.error{background:#92400E}
  .zc-toast a,.zc-toast button{color:#fff;font-weight:800;text-decoration:underline;background:none;border:0;padding:0;font:inherit;cursor:pointer;white-space:nowrap}
  @keyframes zc-in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.zc-badge *,.zc-toast{animation:none!important}}
  `;

  let badge = null;
  let panel = null;
  let toastsEl = null;
  const ensureUi = () => {
    if (badge || !document.body) return;
    const style = document.createElement("style");
    style.id = "zipoo-offline-style";
    style.textContent = STYLE;
    document.head.appendChild(style);

    badge = document.createElement("button");
    badge.type = "button";
    badge.id = "zipoo-connection-badge";
    badge.className = "zc-badge";
    badge.setAttribute("aria-live", "polite");
    badge.addEventListener("click", () => { panel.hidden = !panel.hidden; if (!panel.hidden) renderPanel(); });
    document.body.appendChild(badge);

    panel = document.createElement("div");
    panel.id = "zipoo-sync-panel";
    panel.className = "zc-panel";
    panel.hidden = true;
    document.body.appendChild(panel);
    document.addEventListener("click", (e) => {
      if (!panel.hidden && !panel.contains(e.target) && !badge.contains(e.target)) panel.hidden = true;
    });

    toastsEl = document.createElement("div");
    toastsEl.className = "zc-toasts";
    document.body.appendChild(toastsEl);
  };

  function renderBadge() {
    if (!document.body) return;
    ensureUi();
    const pending = pendingOps().length;
    const failed = failedOps().length;
    let mode = "live";
    let label = "LIVE";
    if (!state.online) { mode = "offline"; label = "OFFLINE"; }
    else if (state.syncing) { mode = "syncing"; label = "SYNCING"; }
    badge.dataset.state = mode;
    const count = pending + failed;
    badge.innerHTML = `<span class="zc-dot"></span><span>${label}</span>${count ? `<span class="zc-count">${count}</span>` : ""}`;
    badge.setAttribute("aria-label", `${label}${count ? `, ${count} change${count > 1 ? "s" : ""} waiting` : ""}`);
    if (panel && !panel.hidden) renderPanel();
  }

  const timeAgo = (ts) => {
    if (!ts) return "never";
    const s = Math.max(0, Math.round((Date.now() - ts) / 1000));
    if (s < 60) return "just now";
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return new Date(ts).toLocaleDateString();
  };

  function renderPanel() {
    const pending = pendingOps();
    const failed = failedOps();
    const foreign = foreignOps();
    const color = !state.online ? "#DC2626" : state.syncing ? "#1477FF" : "#0BBF9A";
    const title = !state.online ? "Offline — working from this device" : state.syncing ? "Syncing your changes…" : "Live — connected";
    const sub = !state.online
      ? "Everything you do is saved here and sent automatically when the internet returns."
      : `Last synced: ${timeAgo(state.lastSync)}`;
    const rows = (list, withActions) => list.slice(0, 12).map((op) => `
      <div class="zc-row" data-op="${op.id}">
        <span>${esc(op.label)}${withActions ? `<em>${esc(op.error || "")}</em>` : ""}${withActions ? `<div class="zc-mini"><button class="zc-btn" data-retry="${op.id}">Retry</button><button class="zc-btn danger" data-discard="${op.id}">Discard</button></div>` : ""}</span>
        <time>${esc(timeAgo(op.createdAt))}</time>
      </div>`).join("");
    panel.innerHTML = `
      <header><span class="zc-pill" style="background:${color}"></span><div><strong>${title}</strong><small>${esc(sub)}</small></div></header>
      ${state.authExpired ? `<div class="zc-section"><h4>Action needed</h4><div class="zc-row"><span>Your session expired. <a href="${BASE}login">Log in</a> to finish syncing.</span></div></div>` : ""}
      <div class="zc-section"><h4>Waiting to sync (${pending.length})</h4>${pending.length ? rows(pending, false) : '<div class="zc-row"><span>Nothing waiting — all changes are saved online.</span></div>'}</div>
      ${failed.length ? `<div class="zc-section"><h4>Needs attention (${failed.length})</h4>${rows(failed, true)}</div>` : ""}
      ${foreign.length ? `<div class="zc-section"><h4>Other account</h4><div class="zc-row"><span>${foreign.length} change${foreign.length > 1 ? "s" : ""} from another login are waiting. Log in with that account to sync them.</span></div></div>` : ""}
      <div class="zc-actions">
        <button class="zc-btn primary" data-zc-sync ${state.online && pending.length && !state.syncing ? "" : "disabled"}>Sync now</button>
        <button class="zc-btn" data-zc-refresh ${state.online && !state.prefetching ? "" : "disabled"}>${state.prefetching ? "Downloading…" : "Update offline data"}</button>
      </div>`;
    panel.querySelector("[data-zc-sync]")?.addEventListener("click", () => syncNow());
    panel.querySelector("[data-zc-refresh]")?.addEventListener("click", () => prefetch(true));
    panel.querySelectorAll("[data-retry]").forEach((b) => b.addEventListener("click", async () => {
      const op = outbox.find((o) => String(o.id) === b.dataset.retry);
      if (op) { op.status = "pending"; op.attempts = 0; op.error = ""; await saveOp(op); renderBadge(); syncNow(); }
    }));
    panel.querySelectorAll("[data-discard]").forEach((b) => b.addEventListener("click", async () => {
      const op = outbox.find((o) => String(o.id) === b.dataset.discard);
      if (op && window.confirm("Discard this offline change? It will never be sent to the server.")) { await removeOp(op); renderBadge(); }
    }));
  }

  function toast(message, kind = "ok", link = null) {
    if (!document.body) return;
    ensureUi();
    const el = document.createElement("div");
    el.className = `zc-toast ${kind}`;
    el.setAttribute("role", "status");
    el.innerHTML = `<span>${esc(message)}</span>${link ? `<a href="${esc(link.href)}">${esc(link.label)}</a>` : ""}`;
    toastsEl.appendChild(el);
    while (toastsEl.children.length > 3) toastsEl.firstChild.remove();
    setTimeout(() => { el.style.transition = "opacity .3s"; el.style.opacity = "0"; setTimeout(() => el.remove(), 320); }, 4500);
  }

  /* After a sync, refresh the visible screen — but never yank it away from
     someone who is in the middle of typing or tapping. */
  let lastInteraction = Date.now();
  ["pointerdown", "keydown", "touchstart"].forEach((evt) => window.addEventListener(evt, () => { lastInteraction = Date.now(); }, { passive: true, capture: true }));
  function offerRefresh() {
    if (!document.body) return;
    const el = document.createElement("div");
    el.className = "zc-toast ok";
    el.innerHTML = `<span>Data updated from the server.</span><button type="button">Refresh</button>`;
    el.querySelector("button").addEventListener("click", () => location.reload());
    ensureUi();
    toastsEl.appendChild(el);
    setTimeout(() => el.remove(), 12000);
    setTimeout(() => {
      const a = document.activeElement;
      const typing = a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName);
      const modalOpen = document.querySelector("[role='dialog']:not([hidden]), .modal:not([hidden]), .app-modal:not([hidden]), .pos-workspace:not([hidden])");
      if (state.online && !typing && !modalOpen && Date.now() - lastInteraction > 8000 && document.visibilityState === "visible") location.reload();
    }, 9000);
  }

  /* --------------------------------------------------------- privacy on logout */
  const purgeUserCache = async (uid) => {
    if (!uid || !idbOk) return;
    try {
      const all = await dbAll("cache");
      await Promise.all(all.filter((e) => e.scope.startsWith(`${uid}:`)).map((e) => dbDel("cache", e.key)));
      Object.keys(localStorage).filter((k) => k.startsWith("zipoo.offline.prefetch.")).forEach((k) => localStorage.removeItem(k));
    } catch { /* ignore */ }
  };
  const nativeRemoveItem = Storage.prototype.removeItem;
  Storage.prototype.removeItem = function patchedRemoveItem(key) {
    if (this === window.localStorage && key === "zipoo.isLoggedIn") purgeUserCache(currentUser().id);
    return nativeRemoveItem.apply(this, arguments);
  };

  /* -------------------------------------------------------------------- boot */
  window.addEventListener("offline", () => markOffline());
  window.addEventListener("online", async () => { (await probe()) ? markOnline() : markOffline(); });
  document.addEventListener("visibilitychange", async () => {
    if (document.visibilityState === "visible") { (await probe()) ? markOnline() : markOffline(); }
  });

  window.__zipooOffline = true;
  window.ZipooOffline = {
    get online() { return state.online; },
    state,
    pending: () => pendingOps().length,
    sync: syncNow,
    prefetch,
    isTempId: (id) => String(id).length === 13 && Number(id) >= TEMP_BASE,
  };

  const boot = async () => {
    renderBadge();
    if (!state.online) document.documentElement.classList.add("zipoo-is-offline");
    (await probe()) ? markOnline() : markOffline();
    scheduleHeartbeat();
    await outboxReady;
    renderBadge();
    if (state.online) {
      await syncNow();
      prefetch();
    }
  };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot, { once: true });
  else boot();
})();
