const DEFAULT_LANGUAGE = "en";
const LOCALE_VERSION = "17";
const SUPPORTED_LANGUAGES = ["en", "sw"];
const TRANSLATION_CACHE = {};
const REGISTER_DRAFT_COOKIE = "zipoo_register_draft";

const getBasePath = () => {
  const script = document.currentScript || document.querySelector('script[src*="assets/js/app.js"]');
  if (!script) {
    return "/";
  }

  return new URL("../../", script.src).pathname;
};

// Inline monochrome SVG icons (used in place of emoji throughout the app).
const ICONS = {
  box: "M64 160l192-96 192 96-192 96zM64 160v192l192 96 192-96V160M256 256v192",
  bolt: "M288 48L112 304h112l-32 160 176-256H256z",
  clipboard: "M192 72h128v56H192zM320 100h56v340H136V100h56M184 240h144M184 312h144",
  chartColumn: "M96 416V272M224 416V160M352 416V224M448 416H64",
  chartLine: "M64 96v320h384M96 352l112-112 80 80 160-176",
  warehouse: "M64 448V224l192-96 192 96v224M160 448V312h192v136",
  building: "M128 448V80h256v368M192 160h40M280 160h40M192 240h40M280 240h40M192 320h40M280 320h40",
  receipt: "M144 48v416l40-28 40 28 40-28 40 28 40-28 40 28V48zM200 176h112M200 256h112",
  moneyBill: "M48 144h416v224H48zM256 200a56 56 0 100 112 56 56 0 000-112M120 176v160M392 176v160",
  worker: "M256 96a56 56 0 100 112 56 56 0 000-112M120 424c0-75 61-120 136-120s136 45 136 120",
  cart: "M80 112h336l-44 192H160L112 80H48M184 416a28 28 0 100 56 28 28 0 000-56m176 0a28 28 0 100 56 28 28 0 000-56",
  truck: "M48 160h256v160H48zM304 208h80l48 48v64h-128zM160 384a32 32 0 100 64 32 32 0 000-64m192 0a32 32 0 100 64 32 32 0 000-64",
  people: "M176 232a68 68 0 100-136 68 68 0 000 136M56 440c14-76 64-128 120-128s106 52 120 128M344 216a52 52 0 100-104M328 312c44 8 80 48 92 120",
  bank: "M64 192L256 80l192 112M96 192v160M416 192v160M176 192v160M336 192v160M56 416h400",
  mobile: "M160 48h192v416H160zM232 416h48",
  cash: "M48 144h416v224H48zM256 200a56 56 0 100 112 56 56 0 000-112",
  user: "M256 96a64 64 0 100 128 64 64 0 000-128M96 432c0-82 72-128 160-128s160 46 160 128",
  check: "M112 268l96 96 192-224",
  close: "M112 112l288 288M400 112L112 400",
};

const svgMarkup = (name, { size = 0, cls = "", style = "" } = {}) => {
  const d = ICONS[name] || ICONS.box;
  const dim = size ? `width:${size}px;height:${size}px;` : "";
  return `<svg class="svg-ico ${cls}" viewBox="0 0 512 512" aria-hidden="true" style="${dim}${style}"><path d="${d}" /></svg>`;
};

// Format a typed numeric amount with thousand separators, keeping an optional decimal part.
const groupThousands = (raw) => {
  let s = String(raw).replace(/[^0-9.]/g, "");
  const dot = s.indexOf(".");
  if (dot !== -1) {
    s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, "");
  }
  let [intPart = "", decPart] = s.split(".");
  intPart = intPart.replace(/^0+(?=\d)/, "");
  const grouped = intPart === "" ? "" : Number(intPart).toLocaleString("en-US");
  if (s.includes(".")) return `${grouped || "0"}.${(decPart || "").slice(0, 2)}`;
  return grouped;
};

// Attach live thousand-separator formatting to a numeric <input>.
const attachThousandsFormatting = (input) => {
  if (!input || input.dataset.thousandsBound === "true") return;
  input.dataset.thousandsBound = "true";
  input.addEventListener("input", () => {
    const start = input.selectionStart;
    const before = input.value;
    input.value = groupThousands(before);
    // Best-effort caret keep when formatting didn't shift length much.
    if (typeof start === "number") {
      const delta = input.value.length - before.length;
      try { input.setSelectionRange(start + delta, start + delta); } catch { /* ignore */ }
    }
  });
};

// Dynamic amount sizing: shrink the font of prominent amounts as the number grows,
// so long TZS values still fit. Driven by the --amount-scale CSS variable.
const AMOUNT_FIT_SELECTOR = ".kpi-val, .amount-fit, [data-amount-fit]";
const fitAmountEl = (el) => {
  const len = (el.textContent || "").trim().length;
  let scale = 1;
  if (len >= 17) scale = 0.58;
  else if (len >= 15) scale = 0.66;
  else if (len >= 13) scale = 0.74;
  else if (len >= 11) scale = 0.82;
  else if (len >= 9) scale = 0.9;
  el.style.setProperty("--amount-scale", String(scale));
};
const fitAmounts = (root) => {
  try { (root || document).querySelectorAll(AMOUNT_FIT_SELECTOR).forEach(fitAmountEl); } catch { /* ignore */ }
};
const initAmountAutosize = () => {
  fitAmounts(document);
  try {
    const obs = new MutationObserver((muts) => {
      for (const m of muts) {
        const node = m.target;
        const el = node && node.nodeType === 3 ? node.parentElement : node;
        const match = el && el.closest ? el.closest(AMOUNT_FIT_SELECTOR) : null;
        if (match) fitAmountEl(match);
        if (m.addedNodes && m.addedNodes.length) {
          m.addedNodes.forEach((n) => { if (n.nodeType === 1) fitAmounts(n); });
        }
      }
    });
    obs.observe(document.body, { subtree: true, childList: true, characterData: true });
  } catch { /* observer unsupported */ }
};

const getSavedLanguage = () => {
  const saved = localStorage.getItem("zipoo.language");
  return SUPPORTED_LANGUAGES.includes(saved) ? saved : DEFAULT_LANGUAGE;
};

const loadTranslations = async (language) => {
  if (TRANSLATION_CACHE[language]) {
    return TRANSLATION_CACHE[language];
  }

  const response = await fetch(`${getBasePath()}locales/${language}.json?v=${LOCALE_VERSION}`);
  if (!response.ok) {
    throw new Error(`Unable to load ${language} translations`);
  }

  const translations = await response.json();
  TRANSLATION_CACHE[language] = translations;
  return translations;
};

const applyTranslations = (translations, language) => {
  document.documentElement.lang = language;

  document.querySelectorAll("[data-i18n]").forEach((element) => {
    const key = element.dataset.i18n;
    if (translations[key]) {
      element.textContent = translations[key];
    }
  });

  document.querySelectorAll("[data-lang]").forEach((button) => {
    button.classList.toggle("active", button.dataset.lang === language);
    button.setAttribute("aria-pressed", String(button.dataset.lang === language));
  });

  document.querySelectorAll("[data-i18n-placeholder]").forEach((element) => {
    const key = element.dataset.i18nPlaceholder;
    if (translations[key]) {
      element.setAttribute("placeholder", translations[key]);
    }
  });

  document.querySelectorAll("[data-search-select]").forEach((select) => {
    const value = select.querySelector("[data-search-select-value]")?.value;
    const option = value ? select.querySelector(`[data-search-select-option][data-value="${value}"]`) : null;
    const label = select.querySelector("[data-search-select-label]");
    if (option && label) {
      label.textContent = translations[option.dataset.labelKey] || option.textContent.trim();
    }
  });
};

const setLanguage = async (language) => {
  const nextLanguage = SUPPORTED_LANGUAGES.includes(language) ? language : DEFAULT_LANGUAGE;
  const translations = await loadTranslations(nextLanguage);
  localStorage.setItem("zipoo.language", nextLanguage);
  applyTranslations(translations, nextLanguage);
};

const updateConnectionStatus = () => {
  const isOnline = navigator.onLine;
  document.body.classList.toggle("offline", !isOnline);
  const statusLabel = document.querySelector(".status-pill [data-i18n]");
  if (statusLabel) {
    statusLabel.dataset.i18n = isOnline ? "status.online" : "status.offline";
    setLanguage(getSavedLanguage());
  }
};

const registerServiceWorker = () => {
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register(`${getBasePath()}service-worker.js`, { scope: getBasePath() }).catch((error) => {
        console.warn("Service worker registration failed", error);
      });
    });
  }
};

const readCookie = (name) => {
  const cookie = document.cookie
    .split("; ")
    .find((item) => item.startsWith(`${name}=`));
  return cookie ? decodeURIComponent(cookie.split("=").slice(1).join("=")) : "";
};

const writeCookie = (name, value, maxAgeDays = 7) => {
  const maxAge = maxAgeDays * 24 * 60 * 60;
  document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
};

const clearCookie = (name) => {
  document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax`;
};

const readRegisterDraft = () => {
  const raw = readCookie(REGISTER_DRAFT_COOKIE);
  if (!raw) {
    return {};
  }

  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
};

const writeRegisterDraft = (form) => {
  const draft = {};
  form.querySelectorAll("input[name], select[name]").forEach((field) => {
    if (field.type !== "password") {
      draft[field.name] = field.value;
    }
  });
  writeCookie(REGISTER_DRAFT_COOKIE, JSON.stringify(draft));
};

const isValidEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
const isValidPassword = (value) => /^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(value);
const availabilityChecks = new WeakMap();

const setAvailabilitySpinner = (input, isChecking) => {
  input?.closest(".input-check")?.querySelector("[data-input-spinner]")?.toggleAttribute("hidden", !isChecking);
};

const checkRegistrationAvailability = async (input) => {
  if (!["phone", "email"].includes(input?.name) || !input.value.trim()) {
    setAvailabilitySpinner(input, false);
    return "";
  }

  const value = input.value.trim();
  const checkToken = (availabilityChecks.get(input)?.token || 0) + 1;
  availabilityChecks.set(input, { token: checkToken, value, error: "" });
  setAvailabilitySpinner(input, true);

  const params = new URLSearchParams();
  params.set(input.name, value);

  try {
    const response = await fetch(`${getBasePath()}api/check-registration.php?${params.toString()}`);
    const payload = await response.json();
    const current = availabilityChecks.get(input);

    if (!current || current.token !== checkToken || input.value.trim() !== value) {
      return "";
    }

    if (!response.ok || !payload.ok) {
      current.error = payload.message || "Unable to check account details.";
      return current.error;
    }

    if (input.name === "phone" && payload.phoneExists) {
      current.error = "This phone number is already registered.";
      return current.error;
    }

    if (input.name === "email" && payload.emailExists) {
      current.error = "This email is already registered.";
      return current.error;
    }

    current.error = "";
    return "";
  } catch {
    const current = availabilityChecks.get(input);

    if (current && current.token === checkToken && input.value.trim() === value) {
      current.error = "Unable to check account details.";
      return current.error;
    }

    return "";
  } finally {
    const current = availabilityChecks.get(input);

    if (current?.token === checkToken) {
      setAvailabilitySpinner(input, false);
    }
  }
};

const setupBottomSheet = () => {
  const menu = document.querySelector("[data-menu]");
  const openButton = document.querySelector("[data-menu-open]");
  const closeButton = document.querySelector("[data-menu-close]");

  if (!menu || !openButton || !closeButton) {
    return;
  }

  const openMenu = () => {
    menu.hidden = false;
    openButton.setAttribute("aria-expanded", "true");
    closeButton.focus();
  };

  const closeMenu = () => {
    menu.hidden = true;
    openButton.setAttribute("aria-expanded", "false");
    openButton.focus();
  };

  openButton.addEventListener("click", openMenu);
  closeButton.addEventListener("click", closeMenu);
  menu.addEventListener("click", (event) => {
    if (event.target === menu) {
      closeMenu();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !menu.hidden) {
      closeMenu();
    }
  });
};

const showAppModal = (title, message) => new Promise((resolve) => {
  const modal = document.querySelector("[data-app-modal]");
  const titleEl = modal?.querySelector("[data-app-modal-title]");
  const messageEl = modal?.querySelector("[data-app-modal-message]");
  const closeButton = modal?.querySelector("[data-app-modal-close]");

  if (!modal || !titleEl || !messageEl || !closeButton) {
    console.warn(`${title}: ${message}`);
    resolve();
    return;
  }

  const close = () => {
    modal.hidden = true;
    closeButton.removeEventListener("click", close);
    resolve();
  };

  titleEl.textContent = title;
  messageEl.textContent = message;
  closeButton.textContent = "OK";
  closeButton.className = "btn btn-primary full";
  modal.querySelectorAll("[data-app-modal-extra]").forEach((node) => node.remove());
  modal.hidden = false;
  closeButton.addEventListener("click", close);
  closeButton.focus();
});

const showConfirmModal = ({ title, message, confirmLabel = "Confirm", cancelLabel = "Cancel", danger = false }) => new Promise((resolve) => {
  const modal = document.querySelector("[data-app-modal]");
  const titleEl = modal?.querySelector("[data-app-modal-title]");
  const messageEl = modal?.querySelector("[data-app-modal-message]");
  const closeButton = modal?.querySelector("[data-app-modal-close]");
  const dialog = modal?.querySelector(".app-modal");

  if (!modal || !titleEl || !messageEl || !closeButton || !dialog) {
    resolve(false);
    return;
  }

  modal.querySelectorAll("[data-app-modal-extra]").forEach((node) => node.remove());

  const actions = document.createElement("div");
  actions.className = "modal-actions";
  actions.style.display = "grid";
  actions.style.gridTemplateColumns = "1fr 1fr";
  actions.style.gap = "10px";
  actions.style.marginTop = "8px";
  actions.style.width = "100%";
  actions.dataset.appModalExtra = "";

  const cancelButton = document.createElement("button");
  cancelButton.type = "button";
  cancelButton.className = "btn btn-secondary full";
  cancelButton.textContent = cancelLabel;

  closeButton.textContent = confirmLabel;
  closeButton.className = danger ? "btn btn-danger full" : "btn btn-primary full";
  actions.append(cancelButton, closeButton);
  dialog.append(actions);

  let finished = false;
  const finish = (value) => {
    if (finished) return;
    finished = true;
    modal.hidden = true;
    closeButton.removeEventListener("click", confirm);
    cancelButton.removeEventListener("click", cancel);
    document.removeEventListener("keydown", onKeydown);
    actions.remove();
    dialog.append(closeButton);
    closeButton.textContent = "OK";
    closeButton.className = "btn btn-primary full";
    resolve(value);
  };
  const confirm = (e) => {
    e?.preventDefault();
    finish(true);
  };
  const cancel = (e) => {
    e?.preventDefault();
    finish(false);
  };
  const onKeydown = (e) => {
    if (e.key === "Escape") {
      finish(false);
    }
  };

  titleEl.textContent = title;
  messageEl.textContent = message;
  modal.hidden = false;
  document.addEventListener("keydown", onKeydown);
  closeButton.addEventListener("click", confirm);
  cancelButton.addEventListener("click", cancel);
  cancelButton.focus();
});

const showPromptModal = ({
  title,
  message = "",
  placeholder = "",
  defaultValue = "",
  confirmLabel = "OK",
  cancelLabel = "Cancel",
  inputType = "text",
}) => new Promise((resolve) => {
  const modal = document.querySelector("[data-app-modal]");
  const titleEl = modal?.querySelector("[data-app-modal-title]");
  const messageEl = modal?.querySelector("[data-app-modal-message]");
  const closeButton = modal?.querySelector("[data-app-modal-close]");
  const dialog = modal?.querySelector(".app-modal");

  if (!modal || !titleEl || !messageEl || !closeButton || !dialog) {
    resolve(null);
    return;
  }

  modal.querySelectorAll("[data-app-modal-extra]").forEach((node) => node.remove());

  const input = document.createElement("input");
  input.type = inputType;
  input.className = "app-modal-input";
  input.placeholder = placeholder;
  input.value = defaultValue || "";
  input.dataset.appModalExtra = "";
  input.style.marginTop = "4px";

  const actions = document.createElement("div");
  actions.className = "modal-actions";
  actions.style.display = "grid";
  actions.style.gridTemplateColumns = "1fr 1fr";
  actions.style.gap = "10px";
  actions.style.marginTop = "12px";
  actions.style.width = "100%";
  actions.dataset.appModalExtra = "";

  const cancelButton = document.createElement("button");
  cancelButton.type = "button";
  cancelButton.className = "btn btn-secondary full";
  cancelButton.textContent = cancelLabel;

  closeButton.textContent = confirmLabel;
  closeButton.className = "btn btn-primary full";

  messageEl.insertAdjacentElement("afterend", input);
  actions.append(cancelButton, closeButton);
  dialog.append(actions);

  let finished = false;
  const finish = (value) => {
    if (finished) return;
    finished = true;
    modal.hidden = true;
    closeButton.removeEventListener("click", confirm);
    cancelButton.removeEventListener("click", cancel);
    input.removeEventListener("keydown", onInputKeydown);
    document.removeEventListener("keydown", onKeydown);
    actions.remove();
    input.remove();
    dialog.append(closeButton);
    closeButton.textContent = "OK";
    closeButton.className = "btn btn-primary full";
    resolve(value);
  };
  const confirm = (e) => {
    e?.preventDefault();
    finish(input.value.trim() || null);
  };
  const cancel = (e) => {
    e?.preventDefault();
    finish(null);
  };
  const onKeydown = (e) => {
    if (e.key === "Escape") {
      finish(null);
    }
  };
  const onInputKeydown = (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      confirm();
    }
  };

  titleEl.textContent = title;
  messageEl.textContent = message;
  modal.hidden = false;
  document.addEventListener("keydown", onKeydown);
  input.addEventListener("keydown", onInputKeydown);
  closeButton.addEventListener("click", confirm);
  cancelButton.addEventListener("click", cancel);
  input.focus();
  input.select();
});
const readStoredJson = (key, fallback) => {
  try {
    return JSON.parse(localStorage.getItem(key) || "");
  } catch {
    return fallback;
  }
};

const getStoredBusinessState = () => {
  const user = readStoredJson("zipoo.user", {});
  const businesses = readStoredJson("zipoo.businesses", []);
  const knownBusinesses = Array.isArray(businesses) && businesses.length
    ? businesses
    : user.business_id
      ? [{ id: user.business_id, business_name: user.business_name || `Business ${user.business_id}` }]
      : [];
  const savedBusinessId = localStorage.getItem("zipoo.currentBusinessId") || String(user.business_id || knownBusinesses[0]?.id || "");
  const selectedBusiness = knownBusinesses.find((business) => String(business.id) === savedBusinessId) || knownBusinesses[0] || null;

  return { user, knownBusinesses, selectedBusiness };
};

const refreshStoredBusinesses = async () => {
  try {
    const response = await fetch(`${getBasePath()}api/businesses.php`);
    const payload = await response.json();

    if (!response.ok || !payload.ok || !Array.isArray(payload.businesses)) {
      return null;
    }

    localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses));
    if (payload.current_business_id) {
      localStorage.setItem("zipoo.currentBusinessId", String(payload.current_business_id));
      const selectedBusiness = payload.businesses.find((business) => String(business.id) === String(payload.current_business_id));
      if (selectedBusiness) {
        const savedUser = readStoredJson("zipoo.user", {});
        localStorage.setItem("zipoo.user", JSON.stringify({
          ...savedUser,
          business_id: selectedBusiness.id,
          business_name: selectedBusiness.business_name,
        }));
      }
    }
    return payload;
  } catch {
    // Keep the locally saved business list when offline or logged out.
  }
  return null;
};

const refreshStoredAccount = async () => {
  try {
    const response = await fetch(`${getBasePath()}api/account.php`);
    if (response.status === 401) {
      if (navigator.onLine && (document.querySelector("[data-settings-page]") || document.querySelector("[data-quick-panel]"))) {
        localStorage.removeItem("zipoo.isLoggedIn");
        window.location.replace(`${getBasePath()}login`);
      }
      return null;
    }
    const payload = await response.json();
    if (response.ok && payload.ok && payload.user) {
      const savedUser = readStoredJson("zipoo.user", {});
      localStorage.setItem("zipoo.user", JSON.stringify({ ...savedUser, ...payload.user }));
      return payload.user;
    }
  } catch {
    // Keep existing local account details for offline rendering.
  }
  return null;
};
const switchStoredBusiness = async (businessId) => {
  const body = new FormData();
  body.set("action", "switch");
  body.set("business_id", businessId);

  const response = await fetch(`${getBasePath()}api/businesses.php`, {
    method: "POST",
    body,
  });
  const payload = await response.json();

  if (!response.ok || !payload.ok) {
    throw new Error(payload.message || "Unable to switch business.");
  }

  localStorage.setItem("zipoo.currentBusinessId", String(businessId));
  if (payload.business) {
    const { knownBusinesses } = getStoredBusinessState();
    const nextBusinesses = knownBusinesses.map((business) => (
      String(business.id) === String(payload.business.id) ? payload.business : business
    ));
    localStorage.setItem("zipoo.businesses", JSON.stringify(nextBusinesses));
  }

  return payload.business;
};

const setupQuickPanel = () => {
  const panel = document.querySelector("[data-quick-panel]");
  const openButton = document.querySelector("[data-quick-open]");
  const closeButton = document.querySelector("[data-quick-close]");
  const logoutButton = document.querySelector("[data-logout]");
  const currentBusiness = document.querySelector("[data-current-business]");
  const companySwitcher = document.querySelector("[data-company-switcher]");
  const companyCurrent = document.querySelector(".company-current");
  const companySelectWrap = document.querySelector("[data-company-select-wrap]");
  const companyOptions = document.querySelector("[data-company-select-options]");

  const closeCompanyMenu = () => {
    if (companySelectWrap) companySelectWrap.hidden = true;
    companySwitcher?.classList.remove("is-open");
    companyCurrent?.setAttribute("aria-expanded", "false");
  };

  const syncQuickBusinessUi = () => {
    const { knownBusinesses, selectedBusiness } = getStoredBusinessState();
    if (currentBusiness) {
      currentBusiness.textContent = selectedBusiness?.business_name || "Business";
    }
    if (companySelectWrap && companyOptions) {
      companyOptions.replaceChildren(...knownBusinesses.map((business) => {
        const isSelected = String(business.id) === String(selectedBusiness?.id || "");
        const button = document.createElement("button");
        button.type = "button";
        button.className = `company-option${isSelected ? " active" : ""}`;
        button.dataset.businessId = String(business.id);
        button.textContent = business.business_name || `Business ${business.id}`;
        button.setAttribute("aria-pressed", isSelected ? "true" : "false");
        return button;
      }));
      companySelectWrap.hidden = true;
      companySwitcher?.classList.toggle("can-switch", knownBusinesses.length > 1);
      if (companyCurrent) {
        companyCurrent.tabIndex = knownBusinesses.length > 1 ? 0 : -1;
        companyCurrent.setAttribute("role", knownBusinesses.length > 1 ? "button" : "presentation");
        companyCurrent.setAttribute("aria-expanded", "false");
      }
    }
  };

  syncQuickBusinessUi();
  refreshStoredAccount().then(syncQuickBusinessUi);
  refreshStoredBusinesses().then(syncQuickBusinessUi);

  const openButtons = document.querySelectorAll("[data-quick-open]");

  if (!panel || !closeButton) {
    return;
  }

  const openPanel = () => {
    panel.hidden = false;
    openButtons.forEach((b) => b.setAttribute("aria-expanded", "true"));
    closeButton.focus();
  };

  const closePanel = () => {
    panel.hidden = true;
    openButtons.forEach((b) => b.setAttribute("aria-expanded", "false"));
    closeCompanyMenu();
  };

  const toggleCompanyMenu = () => {
    const { knownBusinesses } = getStoredBusinessState();
    if (knownBusinesses.length <= 1 || !companySelectWrap) return;
    const willOpen = companySelectWrap.hidden;
    companySelectWrap.hidden = !willOpen;
    companySwitcher?.classList.toggle("is-open", willOpen);
    companyCurrent?.setAttribute("aria-expanded", willOpen ? "true" : "false");
  };

  openButtons.forEach((btn) => btn.addEventListener("click", openPanel));
  closeButton.addEventListener("click", closePanel);
  panel.addEventListener("click", (event) => {
    if (event.target === panel) {
      closePanel();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      if (companySelectWrap && !companySelectWrap.hidden) {
        closeCompanyMenu();
        companyCurrent?.focus();
        return;
      }
      if (!panel.hidden) {
        closePanel();
      }
    }
  });

  companyCurrent?.addEventListener("click", toggleCompanyMenu);
  companyCurrent?.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      toggleCompanyMenu();
    }
  });

  companyOptions?.addEventListener("click", async (event) => {
    const option = event.target.closest("[data-business-id]");
    if (!option || !companyOptions.contains(option)) return;
    const previousValue = String(getStoredBusinessState().selectedBusiness?.id || "");
    const nextValue = option.dataset.businessId;
    if (!nextValue || nextValue === previousValue) {
      closeCompanyMenu();
      return;
    }

    companyOptions.querySelectorAll("button").forEach((button) => {
      button.disabled = true;
    });
    try {
      await switchStoredBusiness(nextValue);
      await refreshStoredBusinesses();
      syncQuickBusinessUi();
      closePanel();
    } catch (error) {
      await showAppModal("Unable to switch business", error.message || "Please try again.");
      syncQuickBusinessUi();
    }
  });

  document.addEventListener("click", (event) => {
    if (companySwitcher && !companySwitcher.contains(event.target)) {
      closeCompanyMenu();
    }
  });

  logoutButton?.addEventListener("click", () => {
    localStorage.removeItem("zipoo.isLoggedIn");
    window.location.href = `${getBasePath()}login`;
  });
};
const initSearchSelect = (select) => {
  if (!select || select.dataset.searchSelectInitialized === "true") return;
  select.dataset.searchSelectInitialized = "true";

  const trigger = select.querySelector("[data-search-select-trigger]");
  const panel = select.querySelector("[data-search-select-panel]");
  const search = select.querySelector("[data-search-select-search]");
  const valueInput = select.querySelector("[data-search-select-value]");
  const label = select.querySelector("[data-search-select-label]");
  if (!trigger || !panel || !search || !valueInput || !label) return;

  const getOptions = () => [...select.querySelectorAll("[data-search-select-option]")];

  const close = () => {
    panel.hidden = true;
    trigger.setAttribute("aria-expanded", "false");
    select.classList.remove("is-open");
  };

  const open = () => {
    document.querySelectorAll("[data-search-select-panel]:not([hidden])").forEach((otherPanel) => {
      if (otherPanel !== panel) {
        otherPanel.hidden = true;
        const otherSelect = otherPanel.closest("[data-search-select]");
        otherSelect?.classList.remove("is-open");
        otherSelect?.querySelector("[data-search-select-trigger]")?.setAttribute("aria-expanded", "false");
      }
    });
    panel.hidden = false;
    trigger.setAttribute("aria-expanded", "true");
    select.classList.add("is-open");
    search.value = "";
    getOptions().forEach((option) => {
      option.hidden = false;
    });
    search.focus();
  };

  const choose = (option) => {
    valueInput.value = option.dataset.value;
    valueInput.setAttribute("value", option.dataset.value);
    label.textContent = option.textContent.trim();
    label.removeAttribute("data-i18n");
    getOptions().forEach((item) => item.classList.toggle("active", item === option));
    valueInput.dispatchEvent(new Event("change", { bubbles: true }));
    close();
    trigger.focus();
  };

  trigger.addEventListener("click", (e) => {
    e.preventDefault();
    panel.hidden ? open() : close();
  });

  search.addEventListener("input", () => {
    const query = search.value.trim().toLowerCase();
    getOptions().forEach((option) => {
      const haystack = `${option.textContent} ${option.dataset.value} ${option.dataset.labelKey || ""}`.toLowerCase();
      option.hidden = Boolean(query) && !haystack.includes(query);
    });
  });

  search.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      e.preventDefault();
      close();
      trigger.focus();
    }
  });

  select.addEventListener("click", (event) => {
    const option = event.target.closest("[data-search-select-option]");
    if (option && select.contains(option)) {
      choose(option);
    }
  });

  valueInput.addEventListener("change", () => {
    const option = getOptions().find((item) => item.dataset.value === valueInput.value);
    if (option) {
      label.textContent = option.textContent.trim();
      label.removeAttribute("data-i18n");
      getOptions().forEach((item) => item.classList.toggle("active", item === option));
    }
  });

  document.addEventListener("click", (event) => {
    if (!select.contains(event.target)) {
      close();
    }
  });
};

const setupSearchSelects = (container = document) => {
  container.querySelectorAll("[data-search-select]").forEach(initSearchSelect);
};

const createSearchSelectElement = ({
  name,
  value = "",
  placeholder = "Select...",
  options = [],
  required = false,
  extraClass = "",
}) => {
  const wrapper = document.createElement("div");
  wrapper.className = `search-select ${extraClass}`.trim();
  wrapper.dataset.searchSelect = "";

  const hiddenInput = document.createElement("input");
  hiddenInput.type = "hidden";
  hiddenInput.name = name;
  hiddenInput.value = value || "";
  if (value) hiddenInput.setAttribute("value", value);
  if (required) hiddenInput.required = true;
  hiddenInput.dataset.searchSelectValue = "";

  const trigger = document.createElement("button");
  trigger.className = "search-select-trigger";
  trigger.type = "button";
  trigger.dataset.searchSelectTrigger = "";
  trigger.setAttribute("aria-expanded", "false");

  const labelSpan = document.createElement("span");
  labelSpan.dataset.searchSelectLabel = "";
  const selectedOpt = options.find((o) => String(o.value) === String(value));
  labelSpan.textContent = selectedOpt ? selectedOpt.label : placeholder;

  const chevronSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  chevronSvg.setAttribute("viewBox", "0 0 512 512");
  chevronSvg.setAttribute("aria-hidden", "true");
  const chevronPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
  chevronPath.setAttribute("d", "M112 184l144 144 144-144");
  chevronSvg.append(chevronPath);
  trigger.append(labelSpan, chevronSvg);

  const panel = document.createElement("div");
  panel.className = "search-select-panel";
  panel.hidden = true;
  panel.dataset.searchSelectPanel = "";

  const searchInput = document.createElement("input");
  searchInput.type = "search";
  searchInput.dataset.searchSelectSearch = "";
  searchInput.placeholder = "Search";

  const optionsContainer = document.createElement("div");
  optionsContainer.className = "search-select-options";
  optionsContainer.dataset.searchSelectOptions = "";

  options.forEach((opt) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.dataset.searchSelectOption = "";
    btn.dataset.value = opt.value;
    btn.textContent = opt.label;
    if (opt.labelKey) btn.dataset.labelKey = opt.labelKey;
    if (String(opt.value) === String(value)) {
      btn.classList.add("active");
    }
    optionsContainer.append(btn);
  });

  panel.append(searchInput, optionsContainer);
  wrapper.append(hiddenInput, trigger, panel);

  initSearchSelect(wrapper);
  return wrapper;
};

const setSearchSelectOptions = (select, options, placeholderKey, selectedValue = null) => {
  const optionsNode = select?.querySelector("[data-search-select-options]");
  const valueInput = select?.querySelector("[data-search-select-value]");
  const label = select?.querySelector("[data-search-select-label]");
  const trigger = select?.querySelector("[data-search-select-trigger]");
  if (!select || !optionsNode || !valueInput || !label || !trigger) {
    return;
  }

  optionsNode.replaceChildren(...options.map((option) => {
    const button = document.createElement("button");
    button.type = "button";
    button.dataset.searchSelectOption = "";
    button.dataset.value = option.value;
    button.textContent = option.label;
    if (option.labelKey) button.dataset.labelKey = option.labelKey;
    return button;
  }));

  trigger.disabled = options.length === 0;

  const targetValue = selectedValue !== null && selectedValue !== undefined ? selectedValue : valueInput.value;
  const selected = options.find((option) => String(option.value) === String(targetValue));
  if (selected) {
    valueInput.value = selected.value;
    valueInput.setAttribute("value", selected.value);
    label.textContent = selected.label;
    label.removeAttribute("data-i18n");
    optionsNode.querySelectorAll("[data-search-select-option]").forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.value === String(selected.value));
    });
  } else {
    valueInput.value = "";
    valueInput.removeAttribute("value");
    if (placeholderKey && (placeholderKey.startsWith("register.") || placeholderKey.startsWith("common."))) {
      label.dataset.i18n = placeholderKey;
      setLanguage(getSavedLanguage());
    } else {
      label.textContent = placeholderKey || "Select...";
      label.removeAttribute("data-i18n");
    }
  }
};

const setupSettingsPage = () => {
  const page = document.querySelector("[data-settings-page]");
  if (!page) {
    return;
  }

  page.querySelectorAll("details.settings-accordion").forEach((details) => {
    details.open = false;
  });

  const ownerAvatar = document.querySelector("[data-owner-avatar]");
  const ownerProfileName = document.querySelector("[data-owner-profile-name]");
  const ownerProfileMeta = document.querySelector("[data-owner-profile-meta]");
  const settingsName = document.querySelector("[data-settings-name]");
  const settingsPhone = document.querySelector("[data-settings-phone]");
  const settingsEmail = document.querySelector("[data-settings-email]");
  const settingsCurrentBusiness = document.querySelector("[data-settings-current-business]");
  const settingsBusinessList = document.querySelector("[data-settings-business-list]");
  const businessCount = document.querySelector("[data-business-count]");
  const accountEditButtons = document.querySelectorAll("[data-account-edit]");
  const accountModal = document.querySelector("[data-account-edit-modal]");
  const accountForm = document.querySelector("[data-account-edit-form]");
  const accountClose = document.querySelector("[data-account-edit-close]");
  const accountTitle = document.querySelector("[data-account-edit-title]");
  const accountLabel = document.querySelector("[data-account-edit-label]");
  const accountValue = document.querySelector("[data-account-edit-value]");
  const accountOtpFields = document.querySelector("[data-account-otp-fields]");
  const accountError = document.querySelector("[data-account-edit-error]");
  const accountSubmit = document.querySelector("[data-account-edit-submit]");
  const businessOpen = document.querySelector("[data-business-manager-open]");
  const businessModal = document.querySelector("[data-business-manager-modal]");
  const businessClose = document.querySelector("[data-business-manager-close]");
  const businessManagerList = document.querySelector("[data-business-manager-list]");
  const openCreateBusinessBtn = document.querySelector("[data-open-create-business]");
  const createBusinessModal = document.querySelector("[data-business-create-modal]");
  const closeCreateBusinessBtn = document.querySelector("[data-business-create-close]");
  const createFlowForm = document.querySelector("[data-business-create-flow]");
  const createRegionSelect = document.querySelector('[data-create-location="region"]');
  const createDistrictSelect = document.querySelector('[data-create-location="district"]');
  const createError = document.querySelector("[data-create-flow-error]");
  const generalModal = document.querySelector("[data-general-settings-modal]");
  const generalTitle = document.querySelector("[data-general-settings-title]");
  const generalCloseBtn = document.querySelector("[data-general-settings-close]");
  const generalForm = document.querySelector("[data-general-settings-form]");
  const generalIdInput = document.querySelector("[data-business-pref-id]");
  const generalError = document.querySelector("[data-general-settings-error]");
  const generalSubmit = document.querySelector("[data-general-settings-submit]");

  const openBusinessPreferencesModal = (business) => {
    if (!business) return;
    if (generalError) generalError.hidden = true;
    if (generalTitle) {
      generalTitle.textContent = `${business.business_name || "Business"} Preferences`;
    }
    if (generalIdInput) {
      generalIdInput.value = business.id || "";
    }
    if (generalForm) {
      if (generalForm.elements["currency"]) {
        generalForm.elements["currency"].value = business.currency || "TZS";
      }
      if (generalForm.elements["timezone"]) {
        generalForm.elements["timezone"].value = business.timezone || "Africa/Dar_es_Salaam";
      }
      if (generalForm.elements["tax_rate"]) {
        generalForm.elements["tax_rate"].value = business.tax_rate ?? "18.00";
      }
      if (generalForm.elements["vat_enabled"]) {
        generalForm.elements["vat_enabled"].checked = Number(business.vat_enabled) === 1;
      }
      if (generalForm.elements["tin"]) {
        generalForm.elements["tin"].value = business.tin || "";
      }
      if (generalForm.elements["vrn"]) {
        generalForm.elements["vrn"].value = business.vrn || "";
      }
      if (generalForm.elements["receipt_footer"]) {
        generalForm.elements["receipt_footer"].value = business.receipt_footer || "";
      }
      syncVatRateField();
    }
    if (generalModal) generalModal.hidden = false;
  };

  const vatEnabledToggle = document.querySelector("[data-vat-enabled-toggle]");
  const vatRateField = document.querySelector("[data-vat-rate-field]");
  function syncVatRateField() {
    if (vatRateField) vatRateField.hidden = !(vatEnabledToggle && vatEnabledToggle.checked);
  }
  vatEnabledToggle?.addEventListener("change", syncVatRateField);

  let editField = "";
  let editStep = "request";
  let regions = [];

  const ensureBusinessLocations = async () => {
    if (regions.length) {
      return regions;
    }
    try {
      const response = await fetch(`${getBasePath()}api/locations.php`);
      const payload = await response.json();
      regions = Array.isArray(payload.regions) ? payload.regions : [];
    } catch {
      regions = [];
    }
    return regions;
  };

  const labels = {
    full_name: "Name",
    phone: "Phone",
    email: "Email",
  };

  const storeAccount = (user) => {
    if (!user) return;
    const savedUser = readStoredJson("zipoo.user", {});
    localStorage.setItem("zipoo.user", JSON.stringify({ ...savedUser, ...user }));
  };

  const refreshAccount = async () => {
    const user = await refreshStoredAccount();
    if (user) {
      render();
    }
  };

  const businessDetailText = (business, isCurrent = false) => {
    const location = [business.region_name, business.district_name].filter(Boolean).join(" / ");
    const details = [business.business_type, location, business.plan_name, business.account_status].filter(Boolean);
    return isCurrent ? (details.length ? `Current - ${details.join(" - ")}` : "Current") : (details.join(" - ") || "Registered");
  };
  const renderBusinessRows = () => {
    const { knownBusinesses, selectedBusiness } = getStoredBusinessState();
    if (businessCount) businessCount.textContent = `${knownBusinesses.length} registered`;

    settingsBusinessList?.replaceChildren(...knownBusinesses.map((business) => {
      const isCurrent = String(business.id) === String(selectedBusiness?.id || "");
      const row = document.createElement("button");
      row.type = "button";
      row.className = `settings-list-row business-settings-row${isCurrent ? " current" : ""}`;
      const name = document.createElement("span");
      const value = document.createElement("strong");
      name.textContent = business.business_name || `Business ${business.id}`;
      value.textContent = businessDetailText(business, isCurrent);
      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);
      row.append(name, value, chevron);

      row.addEventListener("click", async () => {
        if (businessModal) businessModal.hidden = false;
        await ensureBusinessLocations();
        renderBusinessManager(business.id);
        await loadBusinessLocations();
      });

      return row;
    }));
  };

  const renderBusinessManager = (openBusinessId = null) => {
    const { knownBusinesses, selectedBusiness } = getStoredBusinessState();
    businessManagerList?.replaceChildren(...knownBusinesses.map((business) => {
      const isCurrent = String(business.id) === String(selectedBusiness?.id || "");
      const isOpen = openBusinessId !== null && String(business.id) === String(openBusinessId);

      const item = document.createElement("div");
      item.className = `business-accordion-item${isOpen ? " is-open" : ""}${isCurrent ? " current" : ""}`;
      item.dataset.businessItemId = String(business.id);

      // Accordion header: shows only business name on the left and chevron on the right
      const header = document.createElement("button");
      header.type = "button";
      header.className = "business-accordion-header";
      header.setAttribute("aria-expanded", isOpen ? "true" : "false");

      const headerLeft = document.createElement("div");
      headerLeft.className = "business-accordion-header-left";

      const name = document.createElement("span");
      name.className = "business-accordion-name";
      name.textContent = business.business_name || `Business ${business.id}`;
      headerLeft.append(name);

      if (isCurrent) {
        const servingPill = document.createElement("span");
        servingPill.className = "business-serving-pill";
        servingPill.textContent = "Serving";
        headerLeft.append(servingPill);
      }

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("class", "business-accordion-chevron");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      const chevronPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
      chevronPath.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(chevronPath);

      header.append(headerLeft, chevron);

      // Accordion panel: contains editable business details
      const panel = document.createElement("div");
      panel.className = "business-accordion-panel";
      panel.hidden = !isOpen;

      const form = document.createElement("form");
      form.className = "business-accordion-form";

      // Hidden business id
      const idInput = document.createElement("input");
      idInput.type = "hidden";
      idInput.name = "business_id";
      idInput.value = String(business.id);

      // Business name field
      const nameRow = document.createElement("label");
      nameRow.className = "business-accordion-field";
      const nameLabel = document.createElement("span");
      nameLabel.className = "business-field-label";
      nameLabel.textContent = "Business name";
      const nameInput = document.createElement("input");
      nameInput.type = "text";
      nameInput.name = "business_name";
      nameInput.className = "business-field-input";
      nameInput.value = business.business_name || "";
      nameInput.required = true;
      nameRow.append(nameLabel, nameInput);

      // Business type field (css formatted select with search)
      const typeRow = document.createElement("div");
      typeRow.className = "business-accordion-field";
      const typeLabel = document.createElement("span");
      typeLabel.className = "business-field-label";
      typeLabel.textContent = "Business type";
      const typeSelect = createSearchSelectElement({
        name: "business_type",
        value: business.business_type || "service",
        options: [
          { value: "retail", label: "Retail" },
          { value: "service", label: "Service" },
          { value: "wholesale", label: "Wholesale" },
        ],
        required: true,
      });
      typeRow.append(typeLabel, typeSelect);

      // Region field (css formatted select with search)
      const regionRow = document.createElement("div");
      regionRow.className = "business-accordion-field";
      const regionLabel = document.createElement("span");
      regionLabel.className = "business-field-label";
      regionLabel.textContent = "Region";
      const regionSelect = createSearchSelectElement({
        name: "region",
        value: business.region_code || "",
        placeholder: "Select region",
        options: regions.map((r) => ({ value: r.value, label: r.label })),
        required: true,
      });
      regionRow.append(regionLabel, regionSelect);

      // District field (css formatted select with search)
      const districtRow = document.createElement("div");
      districtRow.className = "business-accordion-field";
      const districtLabel = document.createElement("span");
      districtLabel.className = "business-field-label";
      districtLabel.textContent = "District";
      const currentReg = regions.find((r) => r.value === (business.region_code || ""));
      const districtOptions = currentReg?.districts || [];
      const districtSelect = createSearchSelectElement({
        name: "district",
        value: business.district_code || "",
        placeholder: "Select district",
        options: districtOptions.map((d) => ({ value: d.value, label: d.label })),
        required: true,
      });
      districtRow.append(districtLabel, districtSelect);

      const regionValInput = regionSelect.querySelector("[data-search-select-value]");
      regionValInput?.addEventListener("change", () => {
        const found = regions.find((r) => r.value === regionValInput.value);
        setSearchSelectOptions(districtSelect, found?.districts || [], "Select district");
      });

      if (!regions.length) {
        ensureBusinessLocations().then((loadedRegions) => {
          setSearchSelectOptions(
            regionSelect,
            loadedRegions.map((r) => ({ value: r.value, label: r.label })),
            "Select region",
            business.region_code
          );
          const currentLoadedReg = loadedRegions.find((r) => r.value === (business.region_code || regionValInput?.value));
          setSearchSelectOptions(
            districtSelect,
            currentLoadedReg?.districts || [],
            "Select district",
            business.district_code
          );
        });
      }

      // Meta info rows: Plan & Status
      const planRow = document.createElement("div");
      planRow.className = "settings-list-row field-row read-only-row";
      const planLabel = document.createElement("span");
      planLabel.textContent = "Subscription plan";
      const planValue = document.createElement("strong");
      planValue.textContent = business.plan_name || "Starter";
      planRow.append(planLabel, planValue);

      const statusRow = document.createElement("div");
      statusRow.className = "settings-list-row field-row read-only-row";
      const statusLabel = document.createElement("span");
      statusLabel.textContent = "Account status";
      const statusValue = document.createElement("strong");
      const status = (business.account_status || "active").toLowerCase();
      statusValue.className = `status-tag ${status}`;
      statusValue.textContent = status.toUpperCase();
      statusRow.append(statusLabel, statusValue);

      // Business General Preferences button row
      const prefRow = document.createElement("button");
      prefRow.type = "button";
      prefRow.className = "settings-list-row";
      prefRow.style.borderRadius = "var(--radius)";
      prefRow.style.border = "1px solid var(--color-line)";
      prefRow.style.background = "#fff";
      prefRow.style.marginBottom = "8px";

      const prefLabel = document.createElement("span");
      prefLabel.textContent = "General preferences";

      const prefValue = document.createElement("strong");
      const bCur = business.currency || "TZS";
      const bTz = (business.timezone || "Africa/Dar_es_Salaam").split("/").pop();
      prefValue.textContent = `${bCur} • ${bTz}`;

      const prefSvg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      prefSvg.setAttribute("viewBox", "0 0 512 512");
      prefSvg.setAttribute("aria-hidden", "true");
      const prefPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
      prefPath.setAttribute("d", "M184 112l144 144-144 144");
      prefSvg.append(prefPath);

      prefRow.append(prefLabel, prefValue, prefSvg);
      prefRow.addEventListener("click", () => {
        openBusinessPreferencesModal(business);
      });

      // Inline error message
      const errorMsg = document.createElement("p");
      errorMsg.className = "inline-error";
      errorMsg.hidden = true;

      // Actions section
      const actions = document.createElement("div");
      actions.className = "business-accordion-actions";

      const saveBtn = document.createElement("button");
      saveBtn.className = "btn btn-primary full";
      saveBtn.type = "submit";
      saveBtn.textContent = "Save changes";
      actions.append(saveBtn);

      if (!isCurrent) {
        const secondaryActions = document.createElement("div");
        secondaryActions.className = "business-accordion-secondary-actions";

        const setDefault = document.createElement("button");
        setDefault.type = "button";
        setDefault.className = "btn btn-outline btn-sm";
        setDefault.textContent = "Set serving";
        setDefault.addEventListener("click", async () => {
          setDefault.disabled = true;
          try {
            await switchStoredBusiness(String(business.id));
            render();
            renderBusinessManager(business.id);
            await showAppModal("Business changed", `${business.business_name || "Business"} is now serving.`);
          } catch (error) {
            await showAppModal("Unable to switch business", error.message || "Please try again.");
          } finally {
            setDefault.disabled = false;
          }
        });

        const deleteButton = document.createElement("button");
        deleteButton.type = "button";
        deleteButton.className = "btn btn-danger-outline btn-sm";
        deleteButton.textContent = "Delete business";
        deleteButton.addEventListener("click", async () => {
          const confirmed = window.confirm(`Are you sure you want to delete ${business.business_name || "this business"}?`);
          if (!confirmed) return;

          const body = new FormData();
          body.set("action", "delete");
          body.set("business_id", business.id);
          deleteButton.disabled = true;
          try {
            const response = await fetch(`${getBasePath()}api/businesses.php`, { method: "POST", body });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
              throw new Error(payload.message || "Unable to delete business.");
            }
            if (Array.isArray(payload.businesses)) {
              localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses));
            }
            render();
            renderBusinessManager();
            await showAppModal("Business deleted", `${business.business_name || "Business"} was deleted.`);
          } catch (error) {
            await showAppModal("Unable to delete business", error.message || "Please try again.");
          } finally {
            deleteButton.disabled = false;
          }
        });

        secondaryActions.append(setDefault, deleteButton);
        actions.append(secondaryActions);
      } else {
        const servingNotice = document.createElement("div");
        servingNotice.className = "business-serving-notice";
        servingNotice.textContent = "Currently serving business";
        actions.append(servingNotice);
      }

      form.append(idInput, nameRow, typeRow, regionRow, districtRow, planRow, statusRow, prefRow, errorMsg, actions);
      panel.append(form);

      // Accordion toggle behavior
      header.addEventListener("click", () => {
        const currentlyOpen = !panel.hidden;

        // Close other accordion items
        businessManagerList?.querySelectorAll(".business-accordion-item").forEach((otherItem) => {
          if (otherItem !== item) {
            otherItem.classList.remove("is-open");
            const otherHeader = otherItem.querySelector(".business-accordion-header");
            const otherPanel = otherItem.querySelector(".business-accordion-panel");
            if (otherHeader) otherHeader.setAttribute("aria-expanded", "false");
            if (otherPanel) otherPanel.hidden = true;
          }
        });

        if (currentlyOpen) {
          item.classList.remove("is-open");
          header.setAttribute("aria-expanded", "false");
          panel.hidden = true;
        } else {
          item.classList.add("is-open");
          header.setAttribute("aria-expanded", "true");
          panel.hidden = false;
        }
      });

      // Save form submit
      form.addEventListener("submit", async (event) => {
        event.preventDefault();
        errorMsg.hidden = true;
        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";

        const body = new FormData(form);
        body.set("action", "update");

        try {
          const response = await fetch(`${getBasePath()}api/businesses.php`, {
            method: "POST",
            body,
          });
          const payload = await response.json();
          if (!response.ok || !payload.ok) {
            throw new Error(payload.message || "Unable to update business.");
          }

          if (Array.isArray(payload.businesses)) {
            localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses));
          }

          const { selectedBusiness: currentSelected } = getStoredBusinessState();
          const isThisCurrent = String(business.id) === String(currentSelected?.id || "");
          if (isThisCurrent && payload.business) {
            const savedUser = readStoredJson("zipoo.user", {});
            localStorage.setItem("zipoo.user", JSON.stringify({
              ...savedUser,
              business_name: payload.business.business_name,
              business_type: payload.business.business_type,
              region_code: payload.business.region_code,
              district_code: payload.business.district_code,
            }));
          }

          render();
          renderBusinessManager(business.id);
          await showAppModal("Business updated", `${payload.business?.business_name || "Business"} details were updated.`);
        } catch (err) {
          errorMsg.textContent = err.message || "Unable to update business.";
          errorMsg.hidden = false;
        } finally {
          saveBtn.disabled = false;
          saveBtn.textContent = "Save changes";
        }
      });

      item.append(header, panel);
      return item;
    }));
  };

  const render = () => {
    const { user, selectedBusiness } = getStoredBusinessState();
    const ownerName = user.full_name || "Owner profile";
    const businessName = selectedBusiness?.business_name || user.business_name || "Business";
    if (ownerAvatar) ownerAvatar.textContent = (user.full_name ? user.full_name.trim().charAt(0).toUpperCase() : "U");
    if (ownerProfileName) ownerProfileName.textContent = ownerName;
    if (ownerProfileMeta) ownerProfileMeta.textContent = [user.phone, user.email].filter(Boolean).join(" - ") || "-";
    if (settingsName) settingsName.textContent = user.full_name || "-";
    if (settingsPhone) settingsPhone.textContent = user.phone || "-";
    if (settingsEmail) settingsEmail.textContent = user.email || "-";
    if (settingsCurrentBusiness) settingsCurrentBusiness.textContent = businessName;
    renderBusinessRows();
  };

  const closeAccountModal = () => {
    if (accountModal) accountModal.hidden = true;
  };

  const openAccountModal = (field) => {
    const { user } = getStoredBusinessState();
    editField = field;
    editStep = "request";
    if (accountTitle) accountTitle.textContent = `Edit ${labels[field] || "account"}`;
    if (accountLabel) accountLabel.textContent = labels[field] || "Value";
    if (accountValue) {
      accountValue.type = field === "email" ? "email" : field === "phone" ? "tel" : "text";
      accountValue.value = user[field] || "";
      accountValue.readOnly = false;
    }
    if (accountOtpFields) accountOtpFields.hidden = true;
    accountForm?.reset();
    if (accountValue) accountValue.value = user[field] || "";
    if (accountError) accountError.hidden = true;
    if (accountSubmit) accountSubmit.textContent = field === "full_name" ? "Save" : "Send verification";
    if (accountModal) accountModal.hidden = false;
    accountValue?.focus();
  };

  const loadBusinessLocations = async () => {
    await ensureBusinessLocations();
    if (businessRegion && businessDistrict) {
      populateLocationSelects(businessRegion, businessDistrict, businessRegion.value, businessDistrict.value);
    }
  };

  accountEditButtons.forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      openAccountModal(button.dataset.accountEdit);
    });
  });

  accountClose?.addEventListener("click", closeAccountModal);

  accountForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!editField) return;
    if (accountError) accountError.hidden = true;
    const body = new FormData(accountForm);
    body.set("action", editStep === "verify" ? "verify_change" : "request_change");
    body.set("field", editField);
    if (editStep === "verify") {
      body.delete("value");
    }
    if (accountSubmit) accountSubmit.disabled = true;

    try {
      const response = await fetch(`${getBasePath()}api/account.php`, { method: "POST", body });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || "Unable to update account.");
      }

      if (payload.user) {
        storeAccount(payload.user);
        render();
        closeAccountModal();
        await showAppModal("Account updated", payload.message || "Your account was updated.");
        return;
      }

      editStep = "verify";
      if (accountOtpFields) accountOtpFields.hidden = false;
      if (accountValue) accountValue.readOnly = true;
      if (accountSubmit) accountSubmit.textContent = "Verify and save";
      await showAppModal("Verification sent", payload.message || "Enter both verification codes to continue.");
    } catch (error) {
      if (accountError) {
        accountError.textContent = error.message || "Unable to update account.";
        accountError.hidden = false;
      }
    } finally {
      if (accountSubmit) accountSubmit.disabled = false;
    }
  });

  // Create Business One-by-One Flow Setup
  const setupCreateBusinessFlow = () => {
    if (!createFlowForm) return null;

    const fields = [...createFlowForm.querySelectorAll("[data-create-flow-field]")];
    const dots = [...createFlowForm.querySelectorAll(".flow-dot")];
    const counter = createFlowForm.querySelector("[data-create-flow-count]");
    const eyebrow = createFlowForm.querySelector("[data-create-flow-eyebrow]");
    const title = createFlowForm.querySelector("[data-create-flow-title]");
    const helper = createFlowForm.querySelector("[data-create-flow-helper]");
    const backBtn = createFlowForm.querySelector("[data-create-flow-back]");
    const nextBtn = createFlowForm.querySelector("[data-create-flow-next]");
    const nextLabel = createFlowForm.querySelector("[data-create-flow-next-label]");
    const actions = createFlowForm.querySelector(".flow-actions");

    const stepsMeta = [
      {
        eyebrow: "Business Information",
        title: "Name your business",
        helper: "Enter the official trading name of your business.",
      },
      {
        eyebrow: "Business Information",
        title: "Select business type",
        helper: "Choose whether your business is retail, service, or wholesale.",
      },
      {
        eyebrow: "Business Location",
        title: "Choose your region",
        helper: "Select the region in Tanzania where your business operates.",
      },
      {
        eyebrow: "Business Location",
        title: "Choose your district",
        helper: "Select the specific district for this business.",
      },
    ];

    let currentStep = 0;

    const showStep = (step) => {
      currentStep = step;
      fields.forEach((field, i) => {
        const isActive = i === step;
        field.hidden = !isActive;
        field.classList.toggle("active", isActive);
      });

      dots.forEach((dot, i) => {
        dot.classList.toggle("active", i <= step);
      });

      if (counter) {
        counter.textContent = `${step + 1} / ${fields.length}`;
      }

      const meta = stepsMeta[step] || stepsMeta[0];
      if (eyebrow) eyebrow.textContent = meta.eyebrow;
      if (title) title.textContent = meta.title;
      if (helper) helper.textContent = meta.helper;

      if (backBtn) {
        backBtn.hidden = step === 0;
      }
      actions?.classList.toggle("first-step", step === 0);

      if (nextLabel) {
        nextLabel.textContent = step === fields.length - 1 ? "Create business" : "Next";
      }

      if (createError) {
        createError.hidden = true;
      }

      const targetInput = fields[step]?.querySelector("input:not([type=hidden]), [data-search-select-trigger]");
      setTimeout(() => targetInput?.focus(), 50);
    };

    const validateActiveField = () => {
      const activeField = fields[currentStep];
      if (!activeField) return true;

      const input = activeField.querySelector("[data-search-select-value], input:not([type=hidden])");
      const val = input ? input.value.trim() : "";

      if (currentStep === 0) {
        if (!val) {
          if (createError) {
            createError.textContent = "Please enter your business name.";
            createError.hidden = false;
          }
          activeField.querySelector("input")?.focus();
          return false;
        }
      } else if (currentStep === 1) {
        if (!val) {
          if (createError) {
            createError.textContent = "Please select a business type.";
            createError.hidden = false;
          }
          activeField.querySelector("[data-search-select-trigger]")?.focus();
          return false;
        }
      } else if (currentStep === 2) {
        if (!val) {
          if (createError) {
            createError.textContent = "Please select a region.";
            createError.hidden = false;
          }
          activeField.querySelector("[data-search-select-trigger]")?.focus();
          return false;
        }
      } else if (currentStep === 3) {
        if (!val) {
          if (createError) {
            createError.textContent = "Please select a district.";
            createError.hidden = false;
          }
          activeField.querySelector("[data-search-select-trigger]")?.focus();
          return false;
        }
      }

      if (createError) createError.hidden = true;
      return true;
    };

    backBtn?.addEventListener("click", () => {
      if (currentStep > 0) {
        showStep(currentStep - 1);
      }
    });

    const submitCreateForm = async () => {
      if (nextBtn) nextBtn.disabled = true;
      if (nextLabel) nextLabel.textContent = "Creating...";

      const body = new FormData(createFlowForm);
      body.set("action", "create");

      try {
        const response = await fetch(`${getBasePath()}api/businesses.php`, { method: "POST", body });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || "Unable to create business.");
        }

        const { knownBusinesses } = getStoredBusinessState();
        const nextBusinesses = Array.isArray(payload.businesses)
          ? payload.businesses
          : [...knownBusinesses, payload.business].filter(Boolean);
        localStorage.setItem("zipoo.businesses", JSON.stringify(nextBusinesses));
        if (payload.business?.id) {
          localStorage.setItem("zipoo.currentBusinessId", String(payload.business.id));
        }

        createFlowForm.reset();
        if (createBusinessModal) createBusinessModal.hidden = true;
        render();
        renderBusinessManager(payload.business?.id);
        await showAppModal("Business created", `${payload.business?.business_name || "New business"} is now serving.`);
      } catch (err) {
        if (createError) {
          createError.textContent = err.message || "Unable to create business.";
          createError.hidden = false;
        }
      } finally {
        if (nextBtn) nextBtn.disabled = false;
        if (nextLabel) nextLabel.textContent = currentStep === fields.length - 1 ? "Create business" : "Next";
      }
    };

    const advanceOrSubmit = async () => {
      if (!validateActiveField()) return;

      if (currentStep < fields.length - 1) {
        showStep(currentStep + 1);
      } else {
        await submitCreateForm();
      }
    };

    nextBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      advanceOrSubmit();
    });

    createFlowForm.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.target.matches("textarea") && !e.target.matches("[data-search-select-search]")) {
        e.preventDefault();
        advanceOrSubmit();
      }
    });

    // Handle Region & District search-selects in Create Flow
    const regionValInput = createRegionSelect?.querySelector("[data-search-select-value]");
    const districtValInput = createDistrictSelect?.querySelector("[data-search-select-value]");

    const updateCreateDistricts = () => {
      if (!createDistrictSelect || !regionValInput) return;
      const foundReg = regions.find((r) => r.value === regionValInput.value);
      const districts = foundReg?.districts || [];
      setSearchSelectOptions(createDistrictSelect, districts, "Select District");
    };

    regionValInput?.addEventListener("change", () => {
      if (districtValInput) {
        districtValInput.value = "";
        districtValInput.removeAttribute("value");
      }
      updateCreateDistricts();
    });

    return {
      reset: async () => {
        createFlowForm.reset();
        await ensureBusinessLocations();
        if (createRegionSelect) {
          setSearchSelectOptions(
            createRegionSelect,
            regions.map((r) => ({ value: r.value, label: r.label })),
            "Select Region"
          );
        }
        if (createDistrictSelect) {
          setSearchSelectOptions(createDistrictSelect, [], "Select District");
        }
        showStep(0);
      },
    };
  };

  const createFlowController = setupCreateBusinessFlow();

  openCreateBusinessBtn?.addEventListener("click", async () => {
    await createFlowController?.reset();
    if (createBusinessModal) createBusinessModal.hidden = false;
  });

  closeCreateBusinessBtn?.addEventListener("click", () => {
    if (createBusinessModal) createBusinessModal.hidden = true;
  });

  businessOpen?.addEventListener("click", async () => {
    if (businessModal) businessModal.hidden = false;
    await ensureBusinessLocations();
    renderBusinessManager();
  });

  businessClose?.addEventListener("click", () => {
    if (businessModal) businessModal.hidden = true;
  });

  generalCloseBtn?.addEventListener("click", () => {
    if (generalModal) generalModal.hidden = true;
  });

  generalForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (generalError) generalError.hidden = true;
    if (generalSubmit) generalSubmit.disabled = true;

    try {
      const formData = new FormData(generalForm);
      formData.set("action", "save_preferences");

      const res = await fetch(`${getBasePath()}api/businesses.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to save preferences.");
      }

      if (Array.isArray(data.businesses)) {
        localStorage.setItem("zipoo.businesses", JSON.stringify(data.businesses));
      }

      const updatedId = data.business?.id || generalIdInput?.value;
      const { selectedBusiness } = getStoredBusinessState();
      if (selectedBusiness && String(selectedBusiness.id) === String(updatedId)) {
        localStorage.setItem("zipoo.selected_business", JSON.stringify(data.business));
      }

      render();
      renderBusinessManager(updatedId);
      if (generalModal) generalModal.hidden = true;
      await showAppModal("Preferences Saved", `Preferences for ${data.business?.business_name || "business"} have been updated.`);
    } catch (err) {
      if (generalError) {
        generalError.textContent = err.message || "Failed to save preferences.";
        generalError.hidden = false;
      }
    } finally {
      if (generalSubmit) generalSubmit.disabled = false;
    }
  });

  render();
  refreshAccount().then(render);
  refreshStoredBusinesses().then(() => {
    render();
    renderBusinessManager();
  });
  setupSystemSettings();
};

const setupSystemSettings = () => {
  const page = document.querySelector("[data-settings-page]");
  if (!page) return;

  // Indicators
  const smtpStatusLabel = document.querySelector("[data-settings-smtp-status]");
  const smsSenderIdLabel = document.querySelector("[data-settings-sms-sender-id]");
  const senderIdCountLabel = document.querySelector("[data-settings-sender-id-count]");
  const languageLabel = document.querySelector("[data-settings-language-label]");

  // Triggers
  const openSmtpBtn = document.querySelector("[data-open-smtp-settings]");
  const openSmsBtn = document.querySelector("[data-open-sms-settings]");
  const openSenderIdBtn = document.querySelector("[data-open-sender-id-modal]");
  const openSenderIdFromSmsBtn = document.querySelector("[data-open-sender-id-modal-from-sms]");
  const openLanguageBtn = document.querySelector("[data-open-language-modal]");

  // Modals & Forms
  const smtpModal = document.querySelector("[data-smtp-modal]");
  const smtpCloseBtn = document.querySelector("[data-smtp-close]");
  const smtpForm = document.querySelector("[data-smtp-form]");
  const smtpError = document.querySelector("[data-smtp-error]");
  const smtpSubmit = document.querySelector("[data-smtp-submit]");
  const smtpTestForm = document.querySelector("[data-smtp-test-form]");
  const smtpTestError = document.querySelector("[data-smtp-test-error]");
  const smtpTestSubmit = document.querySelector("[data-smtp-test-submit]");

  const smsModal = document.querySelector("[data-sms-modal]");
  const smsCloseBtn = document.querySelector("[data-sms-close]");
  const smsForm = document.querySelector("[data-sms-form]");
  const smsError = document.querySelector("[data-sms-error]");
  const smsSubmit = document.querySelector("[data-sms-submit]");
  const smsTestForm = document.querySelector("[data-sms-test-form]");
  const smsTestError = document.querySelector("[data-sms-test-error]");
  const smsTestSubmit = document.querySelector("[data-sms-test-submit]");

  const senderIdModal = document.querySelector("[data-sender-id-modal]");
  const senderIdCloseBtn = document.querySelector("[data-sender-id-close]");
  const senderIdForm = document.querySelector("[data-sender-id-form]");
  const senderIdError = document.querySelector("[data-sender-id-error]");
  const senderIdSubmit = document.querySelector("[data-sender-id-submit]");
  const senderIdRequestsList = document.querySelector("[data-sender-id-requests-list]");

  const languageModal = document.querySelector("[data-language-modal]");
  const languageCloseBtn = document.querySelector("[data-language-close]");
  const langChoiceButtons = document.querySelectorAll("[data-lang-choice]");

  let cachedSettings = { smtp: {}, sms: {}, general: {}, sender_id_requests: [] };

  const updateLanguageLabel = () => {
    const lang = getSavedLanguage();
    if (languageLabel) {
      languageLabel.textContent = lang === "sw" ? "Kiswahili" : "English";
    }
    langChoiceButtons.forEach((btn) => {
      const isActive = btn.dataset.langChoice === lang;
      btn.classList.toggle("active", isActive);
      const icon = btn.querySelector(".lang-check-icon");
      if (icon) icon.style.opacity = isActive ? "1" : "0";
    });
  };

  const renderSenderIdRequests = (requests = []) => {
    if (!senderIdRequestsList) return;
    if (!requests || !requests.length) {
      senderIdRequestsList.innerHTML = `
        <div style="padding: 16px; text-align: center; color: var(--color-muted); font-size: 0.88rem;">
          No Sender ID requests submitted yet.
        </div>`;
      return;
    }

    senderIdRequestsList.innerHTML = requests.map((req) => {
      const statusClass = req.status === "approved" ? "approved" : (req.status === "rejected" ? "rejected" : "pending");
      const statusText = req.status === "approved" ? "Approved" : (req.status === "rejected" ? "Rejected" : "Pending Approval");
      const dateText = req.created_at ? req.created_at.split(" ")[0] : "";
      return `
        <div class="settings-list-row field-display-row" style="display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 12px 14px;">
          <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <strong style="color: var(--color-navy); font-size: 1rem; letter-spacing: 0.04em;">${req.sender_id}</strong>
            <span class="badge-status ${statusClass}">${statusText}</span>
          </div>
          <div style="font-size: 0.82rem; color: var(--color-text);">${req.company_name} &bull; ${req.purpose}</div>
          ${dateText ? `<div style="font-size: 0.75rem; color: var(--color-muted);">Submitted: ${dateText}</div>` : ""}
          ${req.admin_notes ? `<div style="font-size: 0.78rem; color: var(--color-danger); background: rgba(239, 68, 68, 0.08); padding: 4px 8px; border-radius: 4px;">Note: ${req.admin_notes}</div>` : ""}
        </div>
      `;
    }).join("");
  };

  const loadSettings = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/settings.php`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      cachedSettings = data;

      // Update SMTP label
      if (smtpStatusLabel) {
        smtpStatusLabel.textContent = data.smtp?.smtp_host ? "Configured" : "Configure";
      }

      // Update SMS label
      if (smsSenderIdLabel) {
        smsSenderIdLabel.textContent = data.sms?.sender_id || "MEGASMS";
      }

      // Update Sender ID count
      if (senderIdCountLabel) {
        const pendingCount = (data.sender_id_requests || []).filter(r => r.status === "pending").length;
        if (pendingCount > 0) {
          senderIdCountLabel.textContent = `${pendingCount} pending`;
        } else if ((data.sender_id_requests || []).length > 0) {
          senderIdCountLabel.textContent = `${data.sender_id_requests.length} requests`;
        } else {
          senderIdCountLabel.textContent = "Request";
        }
      }

      renderSenderIdRequests(data.sender_id_requests || []);
    } catch {
      // Quiet fail if network/api is offline
    }
  };

  // Open modals & populate
  openSmtpBtn?.addEventListener("click", () => {
    if (smtpError) smtpError.hidden = true;
    if (smtpForm) {
      smtpForm.elements["smtp_host"].value = cachedSettings.smtp?.smtp_host || "";
      smtpForm.elements["smtp_port"].value = cachedSettings.smtp?.smtp_port || "587";
      smtpForm.elements["smtp_username"].value = cachedSettings.smtp?.smtp_username || "";
      smtpForm.elements["smtp_password"].value = "";
      smtpForm.elements["from_email"].value = cachedSettings.smtp?.from_email || "";
      smtpForm.elements["smtp_encryption"].value = cachedSettings.smtp?.smtp_encryption || "tls";
    }
    if (smtpModal) smtpModal.hidden = false;
  });

  smtpCloseBtn?.addEventListener("click", () => {
    if (smtpModal) smtpModal.hidden = true;
  });

  smtpForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (smtpError) smtpError.hidden = true;
    if (smtpSubmit) smtpSubmit.disabled = true;

    try {
      const formData = new FormData(smtpForm);
      formData.set("action", "save_smtp");

      const res = await fetch(`${getBasePath()}api/settings.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to save SMTP settings.");
      }

      await loadSettings();
      if (smtpModal) smtpModal.hidden = true;
      await showAppModal("SMTP Settings Saved", "Your SMTP server settings have been updated.");
    } catch (err) {
      if (smtpError) {
        smtpError.textContent = err.message || "Failed to save SMTP settings.";
        smtpError.hidden = false;
      }
    } finally {
      if (smtpSubmit) smtpSubmit.disabled = false;
    }
  });

  smtpTestForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (smtpTestError) smtpTestError.hidden = true;
    if (smtpTestSubmit) smtpTestSubmit.disabled = true;
    const origText = smtpTestSubmit?.textContent;
    if (smtpTestSubmit) smtpTestSubmit.textContent = "Sending test email...";

    try {
      const formData = new FormData(smtpTestForm);
      formData.set("action", "test_smtp");

      const res = await fetch(`${getBasePath()}api/settings.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Test email failed.");
      }

      await showAppModal("Test Email Sent", data.message || "Check your recipient inbox to confirm delivery.");
    } catch (err) {
      if (smtpTestError) {
        smtpTestError.textContent = err.message || "Test email failed.";
        smtpTestError.hidden = false;
      }
    } finally {
      if (smtpTestSubmit) {
        smtpTestSubmit.disabled = false;
        smtpTestSubmit.textContent = origText;
      }
    }
  });

  // SMS Modal
  openSmsBtn?.addEventListener("click", () => {
    if (smsError) smsError.hidden = true;
    if (smsForm) {
      smsForm.elements["api_url"].value = cachedSettings.sms?.api_url || "https://megasms.co.tz/api/v1";
      smsForm.elements["api_key"].value = "";
      smsForm.elements["sender_id"].value = cachedSettings.sms?.sender_id || "MEGASMS";
    }
    if (smsModal) smsModal.hidden = false;
  });

  smsCloseBtn?.addEventListener("click", () => {
    if (smsModal) smsModal.hidden = true;
  });

  smsForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (smsError) smsError.hidden = true;
    if (smsSubmit) smsSubmit.disabled = true;

    try {
      const formData = new FormData(smsForm);
      formData.set("action", "save_sms");

      const res = await fetch(`${getBasePath()}api/settings.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to save SMS settings.");
      }

      await loadSettings();
      if (smsModal) smsModal.hidden = true;
      await showAppModal("SMS Settings Saved", "MegaSMS gateway settings have been updated.");
    } catch (err) {
      if (smsError) {
        smsError.textContent = err.message || "Failed to save SMS settings.";
        smsError.hidden = false;
      }
    } finally {
      if (smsSubmit) smsSubmit.disabled = false;
    }
  });

  smsTestForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (smsTestError) smsTestError.hidden = true;
    if (smsTestSubmit) smsTestSubmit.disabled = true;
    const origText = smsTestSubmit?.textContent;
    if (smsTestSubmit) smsTestSubmit.textContent = "Sending test SMS...";

    try {
      const formData = new FormData(smsTestForm);
      formData.set("action", "test_sms");

      const res = await fetch(`${getBasePath()}api/settings.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Test SMS failed.");
      }

      await showAppModal("Test SMS Sent", data.message || "Check the mobile handset for delivery.");
    } catch (err) {
      if (smsTestError) {
        smsTestError.textContent = err.message || "Test SMS failed.";
        smsTestError.hidden = false;
      }
    } finally {
      if (smsTestSubmit) {
        smsTestSubmit.disabled = false;
        smsTestSubmit.textContent = origText;
      }
    }
  });

  // Sender ID Modal
  const openSenderIdModal = () => {
    if (senderIdError) senderIdError.hidden = true;
    senderIdForm?.reset();
    renderSenderIdRequests(cachedSettings.sender_id_requests || []);
    if (senderIdModal) senderIdModal.hidden = false;
  };

  openSenderIdBtn?.addEventListener("click", openSenderIdModal);
  openSenderIdFromSmsBtn?.addEventListener("click", () => {
    if (smsModal) smsModal.hidden = true;
    openSenderIdModal();
  });

  senderIdCloseBtn?.addEventListener("click", () => {
    if (senderIdModal) senderIdModal.hidden = true;
  });

  senderIdForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (senderIdError) senderIdError.hidden = true;
    if (senderIdSubmit) senderIdSubmit.disabled = true;

    try {
      const formData = new FormData(senderIdForm);
      formData.set("action", "request_sender_id");

      const res = await fetch(`${getBasePath()}api/settings.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to submit Sender ID request.");
      }

      senderIdForm.reset();
      await loadSettings();
      await showAppModal("Request Submitted", data.message || "Your Sender ID request was submitted to MegaSMS for telecom operator approval.");
    } catch (err) {
      if (senderIdError) {
        senderIdError.textContent = err.message || "Failed to submit request.";
        senderIdError.hidden = false;
      }
    } finally {
      if (senderIdSubmit) senderIdSubmit.disabled = false;
    }
  });

  // Language Modal
  openLanguageBtn?.addEventListener("click", () => {
    updateLanguageLabel();
    if (languageModal) languageModal.hidden = false;
  });

  languageCloseBtn?.addEventListener("click", () => {
    if (languageModal) languageModal.hidden = true;
  });

  langChoiceButtons.forEach((btn) => {
    btn.addEventListener("click", async () => {
      const lang = btn.dataset.langChoice;
      if (!lang) return;
      await setLanguage(lang);
      updateLanguageLabel();
      if (languageModal) languageModal.hidden = true;
      const langTitle = lang === "sw" ? "Lugha Imebadilishwa" : "Language Changed";
      const langDesc = lang === "sw" ? "Mfumo sasa unatumia Kiswahili." : "Application language updated to English.";
      await showAppModal(langTitle, langDesc);
    });
  });

  // Initial load
  updateLanguageLabel();
  loadSettings();
};

const setupCompanyUsersPage = () => {
  const page = document.querySelector("[data-company-users-page]");
  if (!page) return;

  const usersCountLabel = document.querySelector("[data-system-users-count]");
  const usersList = document.querySelector("[data-company-users-list]");
  const usersEmpty = document.querySelector("[data-company-users-empty]");
  const searchInput = document.querySelector("[data-company-users-search]");
  const openCreateButtons = document.querySelectorAll("[data-open-create-user]");

  // Details Modal
  const detailModal = document.querySelector("[data-user-detail-modal]");
  const detailCloseBtn = document.querySelector("[data-user-detail-close]");
  const detailAvatar = document.querySelector("[data-user-detail-avatar]");
  const detailName = document.querySelector("[data-user-detail-name]");
  const detailMeta = document.querySelector("[data-user-detail-meta]");
  const detailRole = document.querySelector("[data-user-detail-role]");
  const detailStatus = document.querySelector("[data-user-detail-status]");
  const detailPhone = document.querySelector("[data-user-detail-phone]");
  const detailEmail = document.querySelector("[data-user-detail-email]");
  const detailCreatedAt = document.querySelector("[data-user-detail-created-at]");
  const detailEditBtn = document.querySelector("[data-user-detail-edit-btn]");
  const detailDeleteBtn = document.querySelector("[data-user-detail-delete-btn]");

  // Create Modal
  const createModal = document.querySelector("[data-user-create-modal]");
  const createCloseBtn = document.querySelector("[data-user-create-close]");
  const createForm = document.querySelector("[data-user-create-form]");
  const createError = document.querySelector("[data-user-create-error]");
  const createSubmit = document.querySelector("[data-user-create-submit]");

  // Edit Modal
  const editModal = document.querySelector("[data-user-edit-modal]");
  const editCloseBtn = document.querySelector("[data-user-edit-close]");
  const editForm = document.querySelector("[data-user-edit-form]");
  const editError = document.querySelector("[data-user-edit-error]");
  const editSubmit = document.querySelector("[data-user-edit-submit]");
  const editId = document.querySelector("[data-edit-user-id]");
  const editFullName = document.querySelector("[data-edit-user-fullname]");
  const editPhone = document.querySelector("[data-edit-user-phone]");
  const editEmail = document.querySelector("[data-edit-user-email]");
  const editRole = document.querySelector("[data-edit-user-role]");
  const editStatus = document.querySelector("[data-edit-user-status]");
  const editPassword = document.querySelector("[data-edit-user-password]");

  let cachedUsers = [];
  let currentUser = null;

  const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    try {
      const d = new Date(dateStr.replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch {
      return dateStr;
    }
  };

  const renderUsersList = (users = []) => {
    if (usersCountLabel) {
      usersCountLabel.textContent = `${users.length} ${users.length === 1 ? "user" : "users"}`;
    }

    if (!users || !users.length) {
      if (usersList) usersList.replaceChildren();
      if (usersEmpty) usersEmpty.hidden = false;
      return;
    }

    if (usersEmpty) usersEmpty.hidden = true;

    usersList?.replaceChildren(...users.map((user) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row user-row";
      row.dataset.userId = String(user.id);
      row.style.display = "flex";
      row.style.alignItems = "center";
      row.style.justifyContent = "space-between";
      row.style.width = "100%";
      row.style.padding = "12px 14px";
      row.style.background = "#fff";
      row.style.border = "1px solid var(--color-line)";
      row.style.borderRadius = "10px";
      row.style.cursor = "pointer";

      const left = document.createElement("div");
      left.style.display = "flex";
      left.style.alignItems = "center";
      left.style.gap = "10px";
      left.style.textAlign = "left";

      const avatar = document.createElement("div");
      avatar.className = "customer-avatar";
      avatar.style.width = "36px";
      avatar.style.height = "36px";
      avatar.style.fontSize = "0.9rem";
      avatar.textContent = (user.full_name ? user.full_name.trim().charAt(0).toUpperCase() : "U");

      const info = document.createElement("div");
      const name = document.createElement("div");
      name.style.fontWeight = "800";
      name.style.color = "var(--color-navy)";
      name.style.fontSize = "0.92rem";
      name.textContent = user.full_name;

      const sub = document.createElement("div");
      sub.style.fontSize = "0.78rem";
      sub.style.color = "var(--color-muted)";
      const roleCapitalized = user.role.charAt(0).toUpperCase() + user.role.slice(1);
      sub.textContent = `${user.phone} • ${roleCapitalized}`;

      info.append(name, sub);
      left.append(avatar, info);

      const right = document.createElement("div");
      right.style.display = "flex";
      right.style.alignItems = "center";
      right.style.gap = "8px";

      const statusBadge = document.createElement("span");
      statusBadge.className = `badge-status ${user.status === "active" ? "approved" : "rejected"}`;
      statusBadge.textContent = user.status;

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      chevron.style.width = "16px";
      chevron.style.height = "16px";
      chevron.style.stroke = "var(--color-muted)";
      chevron.style.fill = "none";
      chevron.style.strokeWidth = "32";
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);

      right.append(statusBadge, chevron);
      row.append(left, right);

      row.addEventListener("click", () => openUserDetail(user));
      return row;
    }));
  };

  const loadUsers = async (query = "") => {
    try {
      const url = query ? `${getBasePath()}api/company-users.php?q=${encodeURIComponent(query)}` : `${getBasePath()}api/company-users.php`;
      const res = await fetch(url);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      cachedUsers = Array.isArray(data.users) ? data.users : [];
      renderUsersList(cachedUsers);
    } catch {
      // Quiet fail if network/api is offline
    }
  };

  const openUserDetail = (user) => {
    currentUser = user;
    if (detailAvatar) {
      detailAvatar.textContent = (user.full_name ? user.full_name.trim().charAt(0).toUpperCase() : "U");
    }
    if (detailName) detailName.textContent = user.full_name || "-";
    if (detailMeta) {
      const roleCap = user.role.charAt(0).toUpperCase() + user.role.slice(1);
      detailMeta.textContent = `${roleCap} • ${user.status.toUpperCase()}`;
    }
    if (detailRole) detailRole.textContent = user.role;
    if (detailStatus) {
      detailStatus.innerHTML = `<span class="badge-status ${user.status === "active" ? "approved" : "rejected"}">${user.status}</span>`;
    }
    if (detailPhone) detailPhone.textContent = user.phone || "-";
    if (detailEmail) detailEmail.textContent = user.email || "-";
    if (detailCreatedAt) detailCreatedAt.textContent = formatDate(user.created_at);

    if (detailModal) detailModal.hidden = false;
  };

  const closeUserDetail = () => {
    if (detailModal) detailModal.hidden = true;
    currentUser = null;
  };

  const openCreateModal = () => {
    if (createError) createError.hidden = true;
    createForm?.reset();
    if (createModal) createModal.hidden = false;
    createForm?.querySelector('input[name="full_name"]')?.focus();
  };

  const closeCreateModal = () => {
    if (createModal) createModal.hidden = true;
    if (createError) createError.hidden = true;
  };

  const openEditModal = (user = currentUser) => {
    if (!user) return;
    currentUser = user;
    if (editError) editError.hidden = true;
    if (editId) editId.value = String(user.id);
    if (editFullName) editFullName.value = user.full_name || "";
    if (editPhone) editPhone.value = user.phone || "";
    if (editEmail) editEmail.value = user.email || "";
    if (editRole) editRole.value = user.role || "staff";
    if (editStatus) editStatus.value = user.status || "active";
    if (editPassword) editPassword.value = "";
    if (editModal) editModal.hidden = false;
    editFullName?.focus();
  };

  const closeEditModal = () => {
    if (editModal) editModal.hidden = true;
    if (editError) editError.hidden = true;
  };

  // Event handlers
  openCreateButtons.forEach((btn) => btn.addEventListener("click", openCreateModal));
  createCloseBtn?.addEventListener("click", closeCreateModal);
  detailCloseBtn?.addEventListener("click", closeUserDetail);
  editCloseBtn?.addEventListener("click", closeEditModal);

  detailEditBtn?.addEventListener("click", () => {
    const userToEdit = currentUser;
    if (detailModal) detailModal.hidden = true;
    openEditModal(userToEdit);
  });

  detailDeleteBtn?.addEventListener("click", async () => {
    if (!currentUser) return;
    const userToDelete = currentUser;
    const confirmed = await showConfirmModal({
      title: "Delete User",
      message: `Are you sure you want to delete user "${userToDelete.full_name}"? They will no longer have access to this company.`,
      confirmLabel: "Delete",
      cancelLabel: "Cancel",
      danger: true,
    });
    if (!confirmed) return;

    detailDeleteBtn.disabled = true;
    try {
      const formData = new FormData();
      formData.set("action", "delete");
      formData.set("user_id", String(userToDelete.id));

      const res = await fetch(`${getBasePath()}api/company-users.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to delete user.");
      }

      closeUserDetail();
      await loadUsers(searchInput?.value || "");
      await showAppModal("User Deleted", `User "${userToDelete.full_name}" has been removed from this company.`);
    } catch (err) {
      await showAppModal("Error", err.message || "Could not delete user.");
    } finally {
      detailDeleteBtn.disabled = false;
    }
  });

  createForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (createError) createError.hidden = true;
    if (createSubmit) createSubmit.disabled = true;

    try {
      const formData = new FormData(createForm);
      formData.set("action", "create");

      const res = await fetch(`${getBasePath()}api/company-users.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to add user.");
      }

      closeCreateModal();
      createForm.reset();
      await loadUsers(searchInput?.value || "");
      if (data.user) {
        openUserDetail(data.user);
      }
      await showAppModal("User Added", `${data.user?.full_name || "User"} can now log in and access this company.`);
    } catch (err) {
      if (createError) {
        createError.textContent = err.message || "Failed to add user.";
        createError.hidden = false;
      }
    } finally {
      if (createSubmit) createSubmit.disabled = false;
    }
  });

  editForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (editError) editError.hidden = true;
    if (editSubmit) editSubmit.disabled = true;

    try {
      const formData = new FormData(editForm);
      formData.set("action", "update");

      const res = await fetch(`${getBasePath()}api/company-users.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to update user.");
      }

      closeEditModal();
      await loadUsers(searchInput?.value || "");
      if (data.user) {
        openUserDetail(data.user);
      }
      await showAppModal("User Updated", "User details updated successfully.");
    } catch (err) {
      if (editError) {
        editError.textContent = err.message || "Failed to update user.";
        editError.hidden = false;
      }
    } finally {
      if (editSubmit) editSubmit.disabled = false;
    }
  });

  let searchTimeout;
  searchInput?.addEventListener("input", (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      loadUsers(e.target.value.trim());
    }, 250);
  });

  // Initial load
  loadUsers();
};

const setupLocationSelects = async () => {
  const regionSelect = document.querySelector('[data-location-select="region"]');
  const districtSelect = document.querySelector('[data-location-select="district"]');
  if (!regionSelect || !districtSelect) {
    return;
  }

  const response = await fetch(`${getBasePath()}api/locations.php`);
  if (!response.ok) {
    return;
  }

  const payload = await response.json();
  const regions = Array.isArray(payload.regions) ? payload.regions : [];
  const regionInput = regionSelect.querySelector("[data-search-select-value]");
  const districtInput = districtSelect.querySelector("[data-search-select-value]");

  const updateDistricts = () => {
    const region = regions.find((item) => item.value === regionInput.value);
    setSearchSelectOptions(districtSelect, region?.districts || [], "register.district");
  };

  setSearchSelectOptions(regionSelect, regions, "register.region");
  regionInput.addEventListener("change", () => {
    districtInput.value = "";
    districtInput.removeAttribute("value");
    updateDistricts();
  });
  updateDistricts();
};

const setupPasswordTools = () => {
  document.querySelectorAll("[data-password-field]").forEach((field) => {
    const input = field.querySelector("input");
    const valid = field.querySelector("[data-password-valid]");
    const toggle = field.querySelector("[data-password-toggle]");
    const eye = toggle?.querySelector(".icon-eye");
    const eyeOff = toggle?.querySelector(".icon-eye-off");

    input?.addEventListener("input", () => {
      if (valid) {
        valid.hidden = !isValidPassword(input.value);
      }
    });

    toggle?.addEventListener("click", () => {
      const shouldShow = input.type === "password";
      input.type = shouldShow ? "text" : "password";
      toggle.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
      if (eye && eyeOff) {
        eye.hidden = shouldShow;
        eyeOff.hidden = !shouldShow;
      }
      input.focus();
    });
  });
};

const setupLoginFlow = () => {
  const form = document.querySelector("[data-login-flow]");
  if (!form) {
    return;
  }

  const submitButton = form.querySelector('button[type="submit"]');
  const error = form.querySelector("[data-login-error]");
  const status = form.querySelector("[data-login-status]");

  const showError = (message) => {
    if (error) {
      error.textContent = message;
      error.hidden = false;
    }
    if (status) {
      status.hidden = true;
    }
  };

  const clearMessages = () => {
    if (error) {
      error.hidden = true;
    }
    if (status) {
      status.hidden = true;
    }
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    clearMessages();

    const login = form.querySelector('input[name="login"]');
    const password = form.querySelector('input[name="password"]');

    if (!login?.value.trim() || !password?.value) {
      showError("Phone/email and password are required.");
      (!login?.value.trim() ? login : password)?.focus();
      return;
    }

    submitButton.disabled = true;
    if (status) {
      status.textContent = "Logging in...";
      status.hidden = false;
    }

    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || "Unable to login.");
      }

      localStorage.setItem("zipoo.isLoggedIn", "true");
      if (payload.user) {
        localStorage.setItem("zipoo.user", JSON.stringify(payload.user));
      }
      if (Array.isArray(payload.businesses)) {
        localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses));
      }
      if (payload.user?.business_id) {
        localStorage.setItem("zipoo.currentBusinessId", String(payload.user.business_id));
      }
      window.location.href = `${getBasePath()}dashboard`;
    } catch (submitError) {
      submitButton.disabled = false;
      showError(submitError.message || "Unable to login.");
    }
  });

  form.querySelectorAll("input").forEach((input) => {
    input.addEventListener("input", clearMessages);
  });
};

const setupRegisterFlow = () => {
  const form = document.querySelector("[data-register-flow]");
  if (!form) {
    return;
  }

  const fields = [...form.querySelectorAll("[data-flow-field]")];
  const dots = [...form.querySelectorAll(".flow-dot")];
  const counter = form.querySelector("[data-flow-count]");
  const sectionLabel = form.querySelector(".eyebrow[data-i18n]");
  const helper = form.querySelector(".flow-helper[data-i18n]");
  const nextButton = form.querySelector("[data-flow-next]");
  const backButton = form.querySelector("[data-flow-back]");
  const error = form.querySelector("[data-flow-error]");
  const status = form.querySelector("[data-flow-status]");
  const success = form.querySelector("[data-register-success]");
  const otpPanel = form.querySelector("[data-otp-panel]");
  const otpInput = form.querySelector("[data-otp-input]");
  const otpVerify = form.querySelector("[data-otp-verify]");
  const actions = form.querySelector(".flow-actions");
  let index = 0;

  const restoreDraft = () => {
    const draft = readRegisterDraft();
    form.querySelectorAll("input[name], select[name]").forEach((field) => {
      if (Object.prototype.hasOwnProperty.call(draft, field.name)) {
        field.value = draft[field.name];
        field.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });
  };

  const showStep = (nextIndex) => {
    fields.forEach((field, fieldIndex) => {
      const isActive = fieldIndex === nextIndex;
      field.hidden = !isActive;
      field.classList.toggle("active", isActive);
    });

    dots.forEach((dot, dotIndex) => {
      dot.classList.toggle("active", dotIndex <= Math.min(nextIndex, dots.length - 1));
    });

    if (counter) {
      counter.textContent = `${Math.min(nextIndex + 1, fields.length)} / ${fields.length}`;
    }

    const section = fields[nextIndex]?.dataset.flowSection || "personal";
    if (sectionLabel) {
      sectionLabel.dataset.i18n = section === "business"
        ? "register.stepBusiness"
        : section === "security"
          ? "register.stepSecurity"
          : "register.stepPersonal";
    }
    if (helper) {
      helper.dataset.i18n = section === "business"
        ? "register.businessHint"
        : section === "security"
          ? "register.securityHint"
          : "register.personalHint";
    }

    if (backButton) {
      backButton.hidden = nextIndex === 0;
    }

    actions?.classList.toggle("first-step", nextIndex === 0);
    nextButton.querySelector("[data-i18n]")?.setAttribute(
      "data-i18n",
      nextIndex === fields.length - 1 ? "common.register" : "common.next"
    );
    if (error) {
      error.hidden = true;
    }
    if (status) {
      status.hidden = true;
      status.classList.remove("error");
    }

    fields[nextIndex]?.querySelector("input, [data-search-select-trigger]")?.focus();
  };

  const activeInputIsValid = () => {
    const input = fields[index]?.querySelector("[data-search-select-value], input, select");
    const value = input?.value.trim() || "";
    let errorKey = "";

    if (!input) {
      return true;
    }

    if (input.required && !value) {
      errorKey = "validation.required";
    } else if (input.dataset.validate === "phone" && (!/^0\d*$/.test(value) || value.length > 10)) {
      errorKey = "validation.phone";
    } else if (input.dataset.validate === "email" && value && !isValidEmail(value)) {
      errorKey = "validation.email";
    } else if (input.dataset.validate === "password" && !isValidPassword(value)) {
      errorKey = "validation.password";
    } else if (input.dataset.validate === "confirm-password") {
      const password = form.querySelector('input[name="password"]')?.value || "";
      if (value !== password) {
        errorKey = "validation.passwordMatch";
      }
    }

    if (!errorKey) {
      return true;
    }

    input.focus();
    if (error) {
      error.dataset.i18n = errorKey;
      error.hidden = false;
      setLanguage(getSavedLanguage());
    }
    return false;
  };

  const showFlowError = async (message) => {
    if (error) {
      error.removeAttribute("data-i18n");
      error.textContent = message;
      error.hidden = false;
    }
  };

  const clearFlowMessage = () => {
    if (error) {
      error.hidden = true;
    }
    if (status) {
      status.hidden = true;
      status.classList.remove("error");
    }
  };

  form.querySelectorAll("[data-availability-check]").forEach((input) => {
    let debounceTimer;

    input.addEventListener("input", () => {
      window.clearTimeout(debounceTimer);
      const value = input.value.trim();
      const field = input.closest("[data-flow-field]");
      const isActiveField = fields[index] === field;

      availabilityChecks.delete(input);
      setAvailabilitySpinner(input, false);

      if (isActiveField) {
        clearFlowMessage();
      }

      if (!value) {
        return;
      }

      if (input.dataset.validate === "phone" && (!/^0\d*$/.test(value) || value.length > 10)) {
        return;
      }

      if (input.dataset.validate === "email" && !isValidEmail(value)) {
        return;
      }

      debounceTimer = window.setTimeout(async () => {
        const availabilityError = await checkRegistrationAvailability(input);

        if (fields[index] !== field) {
          return;
        }

        if (availabilityError) {
          await showFlowError(availabilityError);
        } else {
          clearFlowMessage();
        }
      }, 450);
    });
  });

  const showRegistrationSuccess = () => {
    fields.forEach((field) => {
      field.hidden = true;
    });
    form.querySelector(".flow-progress")?.setAttribute("hidden", "");
    counter?.setAttribute("hidden", "");
    sectionLabel?.setAttribute("hidden", "");
    helper?.setAttribute("hidden", "");
    actions?.setAttribute("hidden", "");
    otpPanel?.setAttribute("hidden", "");
    if (status) {
      status.hidden = true;
    }
    if (success) {
      success.hidden = false;
    }
    const countdown = success?.querySelector("[data-success-countdown]");
    let seconds = 5;
    if (countdown) {
      countdown.textContent = String(seconds);
    }
    const redirectTimer = window.setInterval(() => {
      seconds -= 1;
      if (countdown) {
        countdown.textContent = String(Math.max(seconds, 0));
      }
      if (seconds <= 0) {
        window.clearInterval(redirectTimer);
        window.location.href = `${getBasePath()}dashboard`;
      }
    }, 1000);
  };

  backButton?.addEventListener("click", () => {
    writeRegisterDraft(form);
    if (index > 0) {
      index -= 1;
      showStep(index);
      setLanguage(getSavedLanguage());
    }
  });

  nextButton?.addEventListener("click", async () => {
    if (!activeInputIsValid()) {
      return;
    }

    const activeInput = fields[index]?.querySelector("[data-search-select-value], input, select");
    nextButton.disabled = true;
    const availabilityError = await checkRegistrationAvailability(activeInput);
    nextButton.disabled = false;
    if (availabilityError) {
      activeInput?.focus();
      await showFlowError(availabilityError);
      return;
    }

    writeRegisterDraft(form);

    if (index < fields.length - 1) {
      index += 1;
      showStep(index);
      await setLanguage(getSavedLanguage());
      return;
    }

    nextButton.disabled = true;
    if (status) {
      status.hidden = false;
      status.classList.remove("error");
      status.textContent = "Creating account...";
    }

    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || "Unable to create the account.");
      }

      clearCookie(REGISTER_DRAFT_COOKIE);
      fields.forEach((field) => {
        field.hidden = true;
      });
      actions?.setAttribute("hidden", "");
      if (status) {
        status.hidden = false;
        status.classList.remove("error");
        status.textContent = payload.message || "Enter the OTP sent to your phone or email.";
      }
      if (otpPanel) {
        otpPanel.hidden = false;
        otpInput?.focus();
      }
    } catch (submitError) {
      nextButton.disabled = false;
      if (status) {
        status.hidden = false;
        status.classList.add("error");
        status.textContent = submitError.message;
      }
    }

    await setLanguage(getSavedLanguage());
  });

  otpVerify?.addEventListener("click", async () => {
    clearFlowMessage();
    const otp = otpInput?.value.trim() || "";
    if (!/^\d{6}$/.test(otp)) {
      if (error) {
        error.removeAttribute("data-i18n");
        error.textContent = "Enter the 6 digit OTP.";
        error.hidden = false;
      }
      otpInput?.focus();
      return;
    }

    otpVerify.disabled = true;
    if (status) {
      status.hidden = false;
      status.classList.remove("error");
      status.textContent = "Verifying OTP...";
    }

    try {
      const body = new FormData();
      body.set("otp", otp);
      const response = await fetch(`${getBasePath()}api/verify-registration-otp.php`, {
        method: "POST",
        body,
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || "Unable to verify OTP.");
      }

      localStorage.setItem("zipoo.isLoggedIn", "true");
      if (payload.user) {
        localStorage.setItem("zipoo.user", JSON.stringify(payload.user));
      }
      if (Array.isArray(payload.businesses)) {
        localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses));
      }
      if (payload.user?.business_id) {
        localStorage.setItem("zipoo.currentBusinessId", String(payload.user.business_id));
      }
      showRegistrationSuccess();
    } catch (verifyError) {
      otpVerify.disabled = false;
      if (status) {
        status.hidden = false;
        status.classList.add("error");
        status.textContent = verifyError.message;
      }
    }
  });

  form.querySelectorAll("input[name], select[name]").forEach((field) => {
    field.addEventListener("input", () => {
      clearFlowMessage();
      if (field.type !== "text" || field.name !== "otp") {
        writeRegisterDraft(form);
      }
    });
    field.addEventListener("change", () => {
      clearFlowMessage();
      if (field.type !== "text" || field.name !== "otp") {
        writeRegisterDraft(form);
      }
    });
    field.addEventListener("focus", clearFlowMessage);
  });

  form.querySelectorAll("[data-search-select-trigger]").forEach((trigger) => {
    trigger.addEventListener("focus", clearFlowMessage);
  });

  restoreDraft();
  showStep(index);
};

const setupCustomersPage = () => {
  const page = document.querySelector("[data-customers-page]");
  if (!page) {
    return;
  }

  const customersList = document.querySelector("[data-customers-list]");
  const customersEmpty = document.querySelector("[data-customers-empty]");
  const searchInput = document.querySelector("[data-customers-search]");
  const openCreateButtons = document.querySelectorAll("[data-open-create-customer]");

  // Create Modal elements
  const createModal = document.querySelector("[data-customer-create-modal]");
  const createCloseBtn = document.querySelector("[data-customer-create-close]");
  const createForm = document.querySelector("[data-customer-create-form]");
  const createError = document.querySelector("[data-customer-create-error]");
  const createSubmit = document.querySelector("[data-customer-create-submit]");

  // Detail Modal elements
  const detailModal = document.querySelector("[data-customer-detail-modal]");
  const detailCloseBtn = document.querySelector("[data-customer-detail-close]");
  const detailAvatar = document.querySelector("[data-detail-avatar]");
  const detailFullName = document.querySelector("[data-detail-full-name]");
  const detailPhoneEmail = document.querySelector("[data-detail-phone-email]");
  const detailTotalSales = document.querySelector("[data-detail-total-sales]");
  const detailSalesCount = document.querySelector("[data-detail-sales-count]");
  const detailPhone = document.querySelector("[data-detail-phone]");
  const detailEmail = document.querySelector("[data-detail-email]");
  const detailAddress = document.querySelector("[data-detail-address]");
  const detailNotes = document.querySelector("[data-detail-notes]");
  const detailCreatedAt = document.querySelector("[data-detail-created-at]");
  const detailEditBtn = document.querySelector("[data-detail-edit-btn]");
  const detailDeleteBtn = document.querySelector("[data-detail-delete-btn]");

  // Edit Modal elements
  const editModal = document.querySelector("[data-customer-edit-modal]");
  const editCloseBtn = document.querySelector("[data-customer-edit-close]");
  const editForm = document.querySelector("[data-customer-edit-form]");
  const editError = document.querySelector("[data-customer-edit-error]");
  const editSubmit = document.querySelector("[data-customer-edit-submit]");
  const editId = document.querySelector("[data-edit-customer-id]");
  const editFullName = document.querySelector("[data-edit-full-name]");
  const editPhone = document.querySelector("[data-edit-phone]");
  const editEmail = document.querySelector("[data-edit-email]");
  const editAddress = document.querySelector("[data-edit-address]");
  const editTin = document.querySelector("[data-edit-tin]");
  const editVrn = document.querySelector("[data-edit-vrn]");
  const editNotes = document.querySelector("[data-edit-notes]");

  let currentCustomer = null;
  let cachedCustomers = [];

  const formatTzs = (amount) => {
    const val = Number(amount) || 0;
    return "TZS " + val.toLocaleString("en-US", { maximumFractionDigits: 0 });
  };

  const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    try {
      const d = new Date(dateStr.replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch {
      return dateStr;
    }
  };

  const renderCustomerRows = (customers) => {
    if (!customers || !customers.length) {
      if (customersList) customersList.replaceChildren();
      if (customersEmpty) customersEmpty.hidden = false;
      return;
    }

    if (customersEmpty) customersEmpty.hidden = true;

    customersList?.replaceChildren(...customers.map((customer) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row customer-row";
      row.dataset.customerId = String(customer.id);

      const name = document.createElement("span");
      name.className = "customer-row-name";
      name.textContent = customer.full_name;

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);

      row.append(name, chevron);
      row.addEventListener("click", () => openCustomerDetail(customer));
      return row;
    }));
  };

  const loadCustomers = async (searchQuery = "") => {
    try {
      const url = `${getBasePath()}api/customers.php` + (searchQuery ? `?q=${encodeURIComponent(searchQuery)}` : "");
      const res = await fetch(url);
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to load customers.");
      }
      cachedCustomers = Array.isArray(data.customers) ? data.customers : [];
      renderCustomerRows(cachedCustomers);
    } catch (err) {
      if (customersList) customersList.replaceChildren();
      if (customersEmpty) {
        customersEmpty.hidden = false;
        const msg = customersEmpty.querySelector("p");
        if (msg) msg.textContent = err.message || "Unable to load customers.";
      }
    }
  };

  const openCustomerDetail = (customer) => {
    currentCustomer = customer;
    if (detailAvatar) {
      detailAvatar.textContent = (customer.full_name ? customer.full_name.trim().charAt(0).toUpperCase() : "C");
    }
    if (detailFullName) detailFullName.textContent = customer.full_name || "-";
    if (detailPhoneEmail) {
      detailPhoneEmail.textContent = [customer.phone, customer.email].filter(Boolean).join(" • ") || "No contact info";
    }
    if (detailTotalSales) detailTotalSales.textContent = formatTzs(customer.total_sales);
    if (detailSalesCount) detailSalesCount.textContent = String(customer.sales_count || 0);
    if (detailPhone) detailPhone.textContent = customer.phone || "-";
    if (detailEmail) detailEmail.textContent = customer.email || "-";
    if (detailAddress) detailAddress.textContent = customer.address || "-";
    if (detailNotes) detailNotes.textContent = customer.notes || "-";
    if (detailCreatedAt) detailCreatedAt.textContent = formatDate(customer.created_at);

    if (detailModal) detailModal.hidden = false;
  };

  const closeDetail = () => {
    if (detailModal) detailModal.hidden = true;
    currentCustomer = null;
  };

  const openCreateModal = () => {
    if (createError) createError.hidden = true;
    createForm?.reset();
    if (createModal) createModal.hidden = false;
    createForm?.querySelector('input[name="full_name"]')?.focus();
  };

  const closeCreateModal = () => {
    if (createModal) createModal.hidden = true;
    if (createError) createError.hidden = true;
  };

  const openEditModal = (customer = currentCustomer) => {
    if (!customer) return;
    currentCustomer = customer;
    if (editError) editError.hidden = true;
    if (editId) editId.value = String(customer.id);
    if (editFullName) editFullName.value = customer.full_name || "";
    if (editPhone) editPhone.value = customer.phone || "";
    if (editEmail) editEmail.value = customer.email || "";
    if (editAddress) editAddress.value = customer.address || "";
    if (editTin) editTin.value = customer.tin || "";
    if (editVrn) editVrn.value = customer.vrn || "";
    if (editNotes) editNotes.value = customer.notes || "";
    if (editModal) editModal.hidden = false;
    editFullName?.focus();
  };

  const closeEditModal = () => {
    if (editModal) editModal.hidden = true;
    if (editError) editError.hidden = true;
  };

  // Event handlers
  openCreateButtons.forEach((btn) => btn.addEventListener("click", openCreateModal));
  createCloseBtn?.addEventListener("click", closeCreateModal);
  detailCloseBtn?.addEventListener("click", closeDetail);
  editCloseBtn?.addEventListener("click", closeEditModal);

  detailEditBtn?.addEventListener("click", () => {
    const cust = currentCustomer;
    if (detailModal) detailModal.hidden = true;
    openEditModal(cust);
  });

  detailDeleteBtn?.addEventListener("click", async () => {
    if (!currentCustomer) return;
    const customerToDelete = currentCustomer;
    const confirmDelete = await showConfirmModal({
      title: "Delete Customer",
      message: `Are you sure you want to delete customer "${customerToDelete.full_name}"?`,
      confirmLabel: "Delete",
      cancelLabel: "Cancel",
      danger: true,
    });
    if (!confirmDelete) return;

    detailDeleteBtn.disabled = true;
    try {
      const formData = new FormData();
      formData.set("action", "delete");
      formData.set("customer_id", String(customerToDelete.id));

      const res = await fetch(`${getBasePath()}api/customers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to delete customer.");
      }

      closeDetail();
      await loadCustomers(searchInput?.value || "");
      await showAppModal("Customer Deleted", `Customer "${customerToDelete.full_name}" has been removed.`);
    } catch (err) {
      await showAppModal("Error", err.message || "Could not delete customer.");
    } finally {
      detailDeleteBtn.disabled = false;
    }
  });

  createForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (createError) createError.hidden = true;
    if (createSubmit) createSubmit.disabled = true;

    try {
      const formData = new FormData(createForm);
      formData.set("action", "create");

      const res = await fetch(`${getBasePath()}api/customers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to add customer.");
      }

      closeCreateModal();
      createForm.reset();
      await loadCustomers();
      if (data.customer) {
        openCustomerDetail(data.customer);
      }
      await showAppModal("Customer Added", `${data.customer?.full_name || "Customer"} has been added successfully.`);
    } catch (err) {
      if (createError) {
        createError.textContent = err.message || "Failed to save customer.";
        createError.hidden = false;
      }
    } finally {
      if (createSubmit) createSubmit.disabled = false;
    }
  });

  editForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (editError) editError.hidden = true;
    if (editSubmit) editSubmit.disabled = true;

    try {
      const formData = new FormData(editForm);
      formData.set("action", "update");

      const res = await fetch(`${getBasePath()}api/customers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to update customer.");
      }

      closeEditModal();
      await loadCustomers(searchInput?.value || "");
      if (data.customer) {
        openCustomerDetail(data.customer);
      }
      await showAppModal("Customer Updated", "Customer details updated successfully.");
    } catch (err) {
      if (editError) {
        editError.textContent = err.message || "Failed to update customer.";
        editError.hidden = false;
      }
    } finally {
      if (editSubmit) editSubmit.disabled = false;
    }
  });

  let searchTimeout;
  searchInput?.addEventListener("input", (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      loadCustomers(e.target.value.trim());
    }, 250);
  });

  // Initial load
  loadCustomers();
};

const setupSuppliersPage = () => {
  const page = document.querySelector("[data-suppliers-page]");
  if (!page) {
    return;
  }

  const suppliersList = document.querySelector("[data-suppliers-list]");
  const suppliersEmpty = document.querySelector("[data-suppliers-empty]");
  const searchInput = document.querySelector("[data-suppliers-search]");
  const openCreateButtons = document.querySelectorAll("[data-open-create-supplier]");

  // Create Modal elements
  const createModal = document.querySelector("[data-supplier-create-modal]");
  const createCloseBtn = document.querySelector("[data-supplier-create-close]");
  const createForm = document.querySelector("[data-supplier-create-form]");
  const createError = document.querySelector("[data-supplier-create-error]");
  const createSubmit = document.querySelector("[data-supplier-create-submit]");

  // Detail Modal elements
  const detailModal = document.querySelector("[data-supplier-detail-modal]");
  const detailCloseBtn = document.querySelector("[data-supplier-detail-close]");
  const detailAvatar = document.querySelector("[data-supplier-detail-avatar]");
  const detailName = document.querySelector("[data-supplier-detail-name]");
  const detailContact = document.querySelector("[data-supplier-detail-contact]");
  const detailTotalPurchases = document.querySelector("[data-supplier-detail-total-purchases]");
  const detailPurchasesCount = document.querySelector("[data-supplier-detail-purchases-count]");
  const detailPhone = document.querySelector("[data-supplier-detail-phone]");
  const detailEmail = document.querySelector("[data-supplier-detail-email]");
  const detailAddress = document.querySelector("[data-supplier-detail-address]");
  const detailNotes = document.querySelector("[data-supplier-detail-notes]");
  const detailCreatedAt = document.querySelector("[data-supplier-detail-created-at]");
  const detailEditBtn = document.querySelector("[data-supplier-detail-edit-btn]");
  const detailDeleteBtn = document.querySelector("[data-supplier-detail-delete-btn]");

  // Edit Modal elements
  const editModal = document.querySelector("[data-supplier-edit-modal]");
  const editCloseBtn = document.querySelector("[data-supplier-edit-close]");
  const editForm = document.querySelector("[data-supplier-edit-form]");
  const editError = document.querySelector("[data-supplier-edit-error]");
  const editSubmit = document.querySelector("[data-supplier-edit-submit]");
  const editId = document.querySelector("[data-edit-supplier-id]");
  const editSupplierName = document.querySelector("[data-edit-supplier-name]");
  const editPhone = document.querySelector("[data-edit-supplier-phone]");
  const editEmail = document.querySelector("[data-edit-supplier-email]");
  const editAddress = document.querySelector("[data-edit-supplier-address]");
  const editTin = document.querySelector("[data-edit-supplier-tin]");
  const editVrn = document.querySelector("[data-edit-supplier-vrn]");
  const editNotes = document.querySelector("[data-edit-supplier-notes]");

  let currentSupplier = null;
  let cachedSuppliers = [];

  const formatTzs = (amount) => {
    const val = Number(amount) || 0;
    return "TZS " + val.toLocaleString("en-US", { maximumFractionDigits: 0 });
  };

  const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    try {
      const d = new Date(dateStr.replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch {
      return dateStr;
    }
  };

  const renderSupplierRows = (suppliers) => {
    if (!suppliers || !suppliers.length) {
      if (suppliersList) suppliersList.replaceChildren();
      if (suppliersEmpty) suppliersEmpty.hidden = false;
      return;
    }

    if (suppliersEmpty) suppliersEmpty.hidden = true;

    suppliersList?.replaceChildren(...suppliers.map((supplier) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row customer-row";
      row.dataset.supplierId = String(supplier.id);

      const name = document.createElement("span");
      name.className = "customer-row-name";
      name.textContent = supplier.supplier_name || supplier.full_name;

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);

      row.append(name, chevron);
      row.addEventListener("click", () => openSupplierDetail(supplier));
      return row;
    }));
  };

  const loadSuppliers = async (searchQuery = "") => {
    try {
      const url = `${getBasePath()}api/suppliers.php` + (searchQuery ? `?q=${encodeURIComponent(searchQuery)}` : "");
      const res = await fetch(url);
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to load suppliers.");
      }
      cachedSuppliers = Array.isArray(data.suppliers) ? data.suppliers : [];
      renderSupplierRows(cachedSuppliers);
    } catch (err) {
      if (suppliersList) suppliersList.replaceChildren();
      if (suppliersEmpty) {
        suppliersEmpty.hidden = false;
        const msg = suppliersEmpty.querySelector("p");
        if (msg) msg.textContent = err.message || "Unable to load suppliers.";
      }
    }
  };

  const openSupplierDetail = (supplier) => {
    currentSupplier = supplier;
    const name = supplier.supplier_name || supplier.full_name || "-";
    if (detailAvatar) {
      detailAvatar.textContent = (name ? name.trim().charAt(0).toUpperCase() : "S");
    }
    if (detailName) detailName.textContent = name;
    if (detailContact) {
      detailContact.textContent = [supplier.phone, supplier.email].filter(Boolean).join(" • ") || "No contact info";
    }
    if (detailTotalPurchases) detailTotalPurchases.textContent = formatTzs(supplier.total_purchases);
    if (detailPurchasesCount) detailPurchasesCount.textContent = String(supplier.purchases_count || 0);
    if (detailPhone) detailPhone.textContent = supplier.phone || "-";
    if (detailEmail) detailEmail.textContent = supplier.email || "-";
    if (detailAddress) detailAddress.textContent = supplier.address || "-";
    if (detailNotes) detailNotes.textContent = supplier.notes || "-";
    if (detailCreatedAt) detailCreatedAt.textContent = formatDate(supplier.created_at);

    if (detailModal) detailModal.hidden = false;
  };

  const closeDetail = () => {
    if (detailModal) detailModal.hidden = true;
    currentSupplier = null;
  };

  const openCreateModal = () => {
    if (createError) createError.hidden = true;
    createForm?.reset();
    if (createModal) createModal.hidden = false;
    createForm?.querySelector('input[name="supplier_name"]')?.focus();
  };

  const closeCreateModal = () => {
    if (createModal) createModal.hidden = true;
    if (createError) createError.hidden = true;
  };

  const openEditModal = (supplier = currentSupplier) => {
    if (!supplier) return;
    currentSupplier = supplier;
    if (editError) editError.hidden = true;
    if (editId) editId.value = String(supplier.id);
    if (editSupplierName) editSupplierName.value = supplier.supplier_name || supplier.full_name || "";
    if (editPhone) editPhone.value = supplier.phone || "";
    if (editEmail) editEmail.value = supplier.email || "";
    if (editAddress) editAddress.value = supplier.address || "";
    if (editTin) editTin.value = supplier.tin || "";
    if (editVrn) editVrn.value = supplier.vrn || "";
    if (editNotes) editNotes.value = supplier.notes || "";
    if (editModal) editModal.hidden = false;
    editSupplierName?.focus();
  };

  const closeEditModal = () => {
    if (editModal) editModal.hidden = true;
    if (editError) editError.hidden = true;
  };

  // Event handlers
  openCreateButtons.forEach((btn) => btn.addEventListener("click", openCreateModal));
  createCloseBtn?.addEventListener("click", closeCreateModal);
  detailCloseBtn?.addEventListener("click", closeDetail);
  editCloseBtn?.addEventListener("click", closeEditModal);

  detailEditBtn?.addEventListener("click", () => {
    const supp = currentSupplier;
    if (detailModal) detailModal.hidden = true;
    openEditModal(supp);
  });

  detailDeleteBtn?.addEventListener("click", async () => {
    if (!currentSupplier) return;
    const supplierToDelete = currentSupplier;
    const displayName = supplierToDelete.supplier_name || supplierToDelete.full_name;
    const confirmDelete = await showConfirmModal({
      title: "Delete Supplier",
      message: `Are you sure you want to delete supplier "${displayName}"?`,
      confirmLabel: "Delete",
      cancelLabel: "Cancel",
      danger: true,
    });
    if (!confirmDelete) return;

    detailDeleteBtn.disabled = true;
    try {
      const formData = new FormData();
      formData.set("action", "delete");
      formData.set("supplier_id", String(supplierToDelete.id));

      const res = await fetch(`${getBasePath()}api/suppliers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to delete supplier.");
      }

      closeDetail();
      await loadSuppliers(searchInput?.value || "");
      await showAppModal("Supplier Deleted", `Supplier "${displayName}" has been removed.`);
    } catch (err) {
      await showAppModal("Error", err.message || "Could not delete supplier.");
    } finally {
      detailDeleteBtn.disabled = false;
    }
  });

  createForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (createError) createError.hidden = true;
    if (createSubmit) createSubmit.disabled = true;

    try {
      const formData = new FormData(createForm);
      formData.set("action", "create");

      const res = await fetch(`${getBasePath()}api/suppliers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to add supplier.");
      }

      closeCreateModal();
      createForm.reset();
      await loadSuppliers();
      if (data.supplier) {
        openSupplierDetail(data.supplier);
      }
      const sName = data.supplier?.supplier_name || data.supplier?.full_name || "Supplier";
      await showAppModal("Supplier Added", `${sName} has been added successfully.`);
    } catch (err) {
      if (createError) {
        createError.textContent = err.message || "Failed to save supplier.";
        createError.hidden = false;
      }
    } finally {
      if (createSubmit) createSubmit.disabled = false;
    }
  });

  editForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (editError) editError.hidden = true;
    if (editSubmit) editSubmit.disabled = true;

    try {
      const formData = new FormData(editForm);
      formData.set("action", "update");

      const res = await fetch(`${getBasePath()}api/suppliers.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to update supplier.");
      }

      closeEditModal();
      await loadSuppliers(searchInput?.value || "");
      if (data.supplier) {
        openSupplierDetail(data.supplier);
      }
      await showAppModal("Supplier Updated", "Supplier details updated successfully.");
    } catch (err) {
      if (editError) {
        editError.textContent = err.message || "Failed to update supplier.";
        editError.hidden = false;
      }
    } finally {
      if (editSubmit) editSubmit.disabled = false;
    }
  });

  let searchTimeout;
  searchInput?.addEventListener("input", (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      loadSuppliers(e.target.value.trim());
    }, 250);
  });

  // Initial load
  loadSuppliers();
};
const setupStockPage = () => {
  const page = document.querySelector("[data-stock-page]");
  if (!page) return;

  // KPI Overview Elements
  const kpiTotalItems = document.querySelector("[data-kpi-total-items]");
  const kpiLowStock = document.querySelector("[data-kpi-low-stock]");
  const kpiLowStockCard = document.querySelector("[data-kpi-low-stock-card]");
  const kpiTotalPo = document.querySelector("[data-kpi-total-po]");
  const kpiTodayMovements = document.querySelector("[data-kpi-today-movements]");

  // 1. Items Elements
  const itemsList = document.querySelector("[data-stock-items-list]");
  const itemsEmpty = document.querySelector("[data-stock-items-empty]");
  const itemsCountBadge = document.querySelector("[data-items-count-badge]");
  const itemsSearch = document.querySelector("[data-items-search]");
  const itemTypeFilters = document.querySelectorAll("[data-filter-type]");
  const openCreateItemBtns = document.querySelectorAll("[data-open-create-item]");
  const stockQuickAddBtn = document.querySelector("[data-stock-quick-add]");

  // Item Details Modal
  const itemDetailModal = document.querySelector("[data-item-detail-modal]");
  const itemDetailCloseBtn = document.querySelector("[data-item-detail-close]");
  const itemDetailAvatar = document.querySelector("[data-item-detail-avatar]");
  const itemDetailName = document.querySelector("[data-item-detail-name]");
  const itemDetailMeta = document.querySelector("[data-item-detail-meta]");
  const itemDetailType = document.querySelector("[data-item-detail-type]");
  const itemDetailStock = document.querySelector("[data-item-detail-stock]");
  const itemDetailSelling = document.querySelector("[data-item-detail-selling]");
  const itemDetailCost = document.querySelector("[data-item-detail-cost]");
  const itemDetailMargin = document.querySelector("[data-item-detail-margin]");
  const itemDetailSku = document.querySelector("[data-item-detail-sku]");
  const itemDetailCategory = document.querySelector("[data-item-detail-category]");
  const itemDetailUnit = document.querySelector("[data-item-detail-unit]");
  const itemDetailMinStock = document.querySelector("[data-item-detail-min-stock]");
  const itemDetailDescription = document.querySelector("[data-item-detail-description]");
  const itemDetailEditBtn = document.querySelector("[data-item-detail-edit-btn]");
  const itemDetailAdjustBtn = document.querySelector("[data-item-detail-adjust-btn]");
  const itemDetailDeleteBtn = document.querySelector("[data-item-detail-delete-btn]");
  const itemStockRow = document.querySelector("[data-item-stock-row]");
  const itemMinStockRow = document.querySelector("[data-item-min-stock-row]");

  // Item Form Modal (Add / Edit)
  const itemFormModal = document.querySelector("[data-item-form-modal]");
  const itemFormCloseBtn = document.querySelector("[data-item-form-close]");
  const itemFormTitle = document.querySelector("[data-item-form-title]");
  const itemForm = document.querySelector("[data-item-form]");
  const itemFormError = document.querySelector("[data-item-form-error]");
  const itemFormSubmit = document.querySelector("[data-item-form-submit]");
  const itemFormId = document.querySelector("[data-item-form-id]");
  const itemFormType = document.querySelector("[data-item-form-type]");
  const typeChoiceBtns = document.querySelectorAll("[data-type-choice]");
  const productOnlyFields = document.querySelector("[data-product-only-fields]");
  const initialStockWrap = document.querySelector("[data-initial-stock-wrap]");
  const categorySelectEl = document.querySelector("[data-category-select]");
  const categoryValueInput = document.querySelector("[data-category-value]");
  const unitSelectEl = document.querySelector("[data-unit-select]");
  const newCategoryInput = document.querySelector("[data-new-category-input]");
  const statusField = document.querySelector("[data-status-field]");
  const itemVatField = document.querySelector("[data-item-vat-field]");
  const itemVatCheckbox = document.querySelector("[data-item-vat-checkbox]");
  const itemVatHint = document.querySelector("[data-item-vat-hint]");
  const itemTaxModeField = document.querySelector("[data-item-taxmode-field]");
  const itemTaxModeToggle = document.querySelector("[data-item-taxmode]");
  const itemTaxModeHint = document.querySelector("[data-item-taxmode-hint]");
  const syncItemTaxMode = () => {
    // The inclusive/exclusive switch only applies to taxable items.
    if (itemTaxModeField) itemTaxModeField.hidden = !(itemVatCheckbox && itemVatCheckbox.checked);
    if (itemTaxModeHint) itemTaxModeHint.textContent = (itemTaxModeToggle && itemTaxModeToggle.checked)
      ? "(VAT already in the price)"
      : "(VAT added on top)";
  };
  itemVatCheckbox?.addEventListener("change", syncItemTaxMode);
  itemTaxModeToggle?.addEventListener("change", syncItemTaxMode);

  // Stock Adjust Modal
  const adjustModal = document.querySelector("[data-stock-adjust-modal]");
  const adjustCloseBtn = document.querySelector("[data-stock-adjust-close]");
  const adjustForm = document.querySelector("[data-stock-adjust-form]");
  const adjustError = document.querySelector("[data-stock-adjust-error]");
  const adjustSubmit = document.querySelector("[data-stock-adjust-submit]");
  const adjustItemSelect = document.querySelector("[data-adjust-item-select]");
  const adjustItemValue = document.querySelector("[data-adjust-item-value]");
  const adjustTypeSelect = document.querySelector("[data-adjust-type-select]");
  const adjustPreview = document.querySelector("[data-adjust-preview]");
  const adjustCurrentStock = document.querySelector("[data-adjust-current-stock]");
  const openAdjustBtns = document.querySelectorAll("[data-open-stock-adjust]");

  // 2. Purchasing Elements
  const poList = document.querySelector("[data-po-list]");
  const poEmpty = document.querySelector("[data-po-empty]");
  const poCountBadge = document.querySelector("[data-po-count-badge]");
  const poSearch = document.querySelector("[data-po-search]");
  const poStatusFilters = document.querySelectorAll("[data-filter-po]");
  const openCreatePoBtns = document.querySelectorAll("[data-open-create-po]");

  // PO Create Modal
  const poCreateModal = document.querySelector("[data-po-create-modal]");
  const poCreateCloseBtn = document.querySelector("[data-po-create-close]");
  const poCreateForm = document.querySelector("[data-po-create-form]");
  const poCreateError = document.querySelector("[data-po-create-error]");
  const poCreateSubmit = document.querySelector("[data-po-create-submit]");
  const poSupplierSelect = document.querySelector("[data-po-supplier-select]");
  const poSupplierValue = document.querySelector("[data-po-supplier-value]");
  const poLinesContainer = document.querySelector("[data-po-lines-container]");
  const addPoLineBtn = document.querySelector("[data-add-po-line]");
  const poVatRow = document.querySelector("[data-po-vat-row]");
  const poVatRateLabel = document.querySelector("[data-po-vat-rate]");
  const poCalcSubtotal = document.querySelector("[data-po-calc-subtotal]");
  const poCalcTax = document.querySelector("[data-po-calc-tax]");
  const poCalcTotal = document.querySelector("[data-po-calc-total]");

  // PO Detail Modal
  const poDetailModal = document.querySelector("[data-po-detail-modal]");
  const poDetailCloseBtn = document.querySelector("[data-po-detail-close]");
  const poDetailNum = document.querySelector("[data-po-detail-num]");
  const poDetailSupplier = document.querySelector("[data-po-detail-supplier]");
  const poDetailStatusBadge = document.querySelector("[data-po-detail-status-badge]");
  const poDetailDate = document.querySelector("[data-po-detail-date]");
  const poDetailExpected = document.querySelector("[data-po-detail-expected]");
  const poDetailPhone = document.querySelector("[data-po-detail-phone]");
  const poDetailEmail = document.querySelector("[data-po-detail-email]");
  const poDetailItemsList = document.querySelector("[data-po-detail-items-list]");
  const poDetailGrandTotal = document.querySelector("[data-po-detail-grand-total]");
  const poDetailNotesWrap = document.querySelector("[data-po-detail-notes-wrap]");
  const poDetailNotes = document.querySelector("[data-po-detail-notes]");
  const poReceiveBtn = document.querySelector("[data-po-receive-btn]");
  const poDownloadPdfBtn = document.querySelector("[data-po-download-pdf-btn]");
  const poEmailBtn = document.querySelector("[data-po-email-btn]");
  const poCancelBtn = document.querySelector("[data-po-cancel-btn]");
  const poUncancelBtn = document.querySelector("[data-po-uncancel-btn]");

  // GRN Modal
  const grnModal = document.querySelector("[data-grn-modal]");
  const grnCloseBtn = document.querySelector("[data-grn-close]");
  const grnForm = document.querySelector("[data-grn-form]");
  const grnError = document.querySelector("[data-grn-error]");
  const grnSubmit = document.querySelector("[data-grn-submit]");
  const grnPoId = document.querySelector("[data-grn-po-id]");
  const grnPoNum = document.querySelector("[data-grn-po-num]");
  const grnItemsContainer = document.querySelector("[data-grn-items-container]");
  const grnReceiveAllBtn = document.querySelector("[data-grn-receive-all]");

  // 3. Movement Elements
  const movementsList = document.querySelector("[data-stock-movements-list]");
  const movementsEmpty = document.querySelector("[data-stock-movements-empty]");
  const movementsCountBadge = document.querySelector("[data-movements-count-badge]");
  const movementsSearch = document.querySelector("[data-movements-search]");
  const movementFilters = document.querySelectorAll("[data-filter-movement]");

  // In-memory state
  let cachedItems = [];
  let vatConfig = { enabled: false, rate: 0 };
  let cachedPOs = [];
  let cachedSuppliers = [];
  let cachedCategories = [];
  let currentItem = null;
  let currentPO = null;
  let activeItemType = "all";
  let activePoStatus = "all";
  let activeMovementType = "all";

  const formatCurrency = (amount, cur = "TZS") => {
    const val = Number(amount) || 0;
    return `${val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })} ${cur}`;
  };

  const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    try {
      const d = new Date(dateStr.replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch {
      return dateStr;
    }
  };

  // ----------------------------------------
  // Section 1: Products & Services Logic
  // ----------------------------------------

  const setItemTypeChoice = (choice) => {
    if (itemFormType) itemFormType.value = choice;
    typeChoiceBtns.forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.typeChoice === choice);
    });
    if (productOnlyFields) {
      productOnlyFields.hidden = (choice === "service");
    }
    setSearchSelectValue(unitSelectEl, choice === "service" ? "service" : "pcs");
  };

  typeChoiceBtns.forEach((btn) => {
    btn.addEventListener("click", () => setItemTypeChoice(btn.dataset.typeChoice));
  });

  const CATEGORY_NEW_VALUE = "__new__";

  const setSearchSelectValue = (wrapper, value) => {
    const input = wrapper?.querySelector("[data-search-select-value]");
    if (!input) return;
    input.value = value;
    input.setAttribute("value", value);
    input.dispatchEvent(new Event("change", { bubbles: true }));
  };

  const toggleNewCategoryInput = () => {
    if (!categoryValueInput || !newCategoryInput) return;
    const isNew = categoryValueInput.value === CATEGORY_NEW_VALUE;
    newCategoryInput.hidden = !isNew;
    if (isNew) {
      newCategoryInput.focus();
    } else {
      newCategoryInput.value = "";
    }
  };

  const populateCategorySelect = (categories = [], selected = "General") => {
    if (!categorySelectEl) return;
    const list = Array.isArray(categories) ? categories.slice() : [];
    if (!list.includes("General")) list.unshift("General");
    if (selected && selected !== CATEGORY_NEW_VALUE && !list.includes(selected)) {
      list.push(selected);
    }
    const seen = new Set();
    const options = list
      .filter((c) => c && !seen.has(c) && seen.add(c))
      .map((c) => ({ value: c, label: c }));
    options.push({ value: CATEGORY_NEW_VALUE, label: "+ New Category" });
    const target = (selected && selected !== CATEGORY_NEW_VALUE) ? selected : "General";
    setSearchSelectOptions(categorySelectEl, options, null, target);
    toggleNewCategoryInput();
  };

  categoryValueInput?.addEventListener("change", toggleNewCategoryInput);

  const renderItemsList = (items = []) => {
    if (!items || !items.length) {
      if (itemsList) itemsList.replaceChildren();
      if (itemsEmpty) itemsEmpty.hidden = false;
      return;
    }

    if (itemsEmpty) itemsEmpty.hidden = true;

    itemsList?.replaceChildren(...items.map((item) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row stock-item-row";
      row.dataset.itemId = String(item.id);
      row.style.display = "flex";
      row.style.alignItems = "center";
      row.style.justifyContent = "space-between";
      row.style.width = "100%";
      row.style.padding = "12px 14px";
      row.style.background = "#fff";
      row.style.border = "1px solid var(--color-line)";
      row.style.borderRadius = "10px";
      row.style.cursor = "pointer";

      const left = document.createElement("div");
      left.style.display = "flex";
      left.style.alignItems = "center";
      left.style.gap = "10px";
      left.style.textAlign = "left";

      const avatar = document.createElement("div");
      avatar.className = "customer-avatar";
      avatar.style.width = "38px";
      avatar.style.height = "38px";
      avatar.style.fontSize = "1.1rem";
      avatar.innerHTML = svgMarkup(item.type === "service" ? "bolt" : "box");

      const info = document.createElement("div");
      const name = document.createElement("div");
      name.style.fontWeight = "800";
      name.style.color = "var(--color-navy)";
      name.style.fontSize = "0.94rem";
      name.textContent = item.name;

      const sub = document.createElement("div");
      sub.style.fontSize = "0.78rem";
      sub.style.color = "var(--color-muted)";
      const codePart = item.sku ? `SKU: ${item.sku} • ` : "";
      sub.textContent = `${codePart}${item.category || "General"} • ${formatCurrency(item.selling_price)}`;

      info.append(name, sub);
      left.append(avatar, info);

      const right = document.createElement("div");
      right.style.display = "flex";
      right.style.alignItems = "center";
      right.style.gap = "8px";

      const stockBadge = document.createElement("span");
      if (item.type === "service") {
        stockBadge.className = "badge-stock service";
        stockBadge.textContent = "Service";
      } else if (item.current_stock <= 0) {
        stockBadge.className = "badge-stock out-of-stock";
        stockBadge.textContent = `0 ${item.unit}`;
      } else if (item.is_low_stock) {
        stockBadge.className = "badge-stock low-stock";
        stockBadge.textContent = `${item.current_stock} ${item.unit}`;
      } else {
        stockBadge.className = "badge-stock in-stock";
        stockBadge.textContent = `${item.current_stock} ${item.unit}`;
      }

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      chevron.style.width = "16px";
      chevron.style.height = "16px";
      chevron.style.stroke = "var(--color-muted)";
      chevron.style.fill = "none";
      chevron.style.strokeWidth = "32";
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);

      right.append(stockBadge, chevron);
      row.append(left, right);

      row.addEventListener("click", () => openItemDetail(item));
      return row;
    }));
  };

  const loadItems = async (query = "") => {
    try {
      const params = new URLSearchParams();
      if (activeItemType === "product" || activeItemType === "service") {
        params.set("type", activeItemType);
      } else if (activeItemType === "low_stock") {
        params.set("low_stock", "1");
      }
      if (query) {
        params.set("q", query);
      }

      const res = await fetch(`${getBasePath()}api/items.php?${params.toString()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      cachedItems = Array.isArray(data.items) ? data.items : [];
      if (data.vat) vatConfig = { enabled: !!data.vat.enabled, rate: Number(data.vat.rate) || 0 };
      renderItemsList(cachedItems);

      // Update KPI counters
      if (kpiTotalItems) kpiTotalItems.textContent = String(data.stats?.total || 0);
      if (kpiLowStock) kpiLowStock.textContent = String(data.stats?.low_stock || 0);
      if (kpiLowStockCard) {
        kpiLowStockCard.classList.toggle("alert", (data.stats?.low_stock || 0) > 0);
      }
      if (itemsCountBadge) {
        itemsCountBadge.textContent = `${data.stats?.total || 0} items`;
      }

      // Cache categories and refresh the category select (keep the user's current pick)
      if (Array.isArray(data.categories)) {
        cachedCategories = data.categories;
        if (categoryValueInput && categoryValueInput.value !== CATEGORY_NEW_VALUE) {
          populateCategorySelect(cachedCategories, categoryValueInput.value || "General");
        }
      }

      // Also populate adjust product search-select
      if (adjustItemSelect) {
        const prodItems = cachedItems.filter((i) => i.type === "product");
        const currentVal = adjustItemValue?.value || "";
        const options = prodItems.map((p) => ({
          value: String(p.id),
          label: `${p.name} (Stock: ${p.current_stock} ${p.unit})`,
        }));
        setSearchSelectOptions(adjustItemSelect, options, "Choose a product...", currentVal);
      }
    } catch {
      // Quiet fail if offline
    }
  };

  const openItemDetail = (item) => {
    currentItem = item;
    if (itemDetailAvatar) {
      itemDetailAvatar.innerHTML = svgMarkup(item.type === "service" ? "bolt" : "box");
    }
    if (itemDetailName) itemDetailName.textContent = item.name;
    if (itemDetailMeta) {
      itemDetailMeta.textContent = `${item.category || "General"} • ${item.type.toUpperCase()}`;
    }
    if (itemDetailType) itemDetailType.textContent = item.type;
    if (itemStockRow) itemStockRow.hidden = (item.type === "service");
    if (itemMinStockRow) itemMinStockRow.hidden = (item.type === "service");

    if (itemDetailStock) {
      const cls = item.current_stock <= 0 ? "out-of-stock" : (item.is_low_stock ? "low-stock" : "in-stock");
      itemDetailStock.innerHTML = `<span class="badge-stock ${cls}">${item.current_stock} ${item.unit}</span>`;
    }
    if (itemDetailSelling) itemDetailSelling.textContent = formatCurrency(item.selling_price);
    if (itemDetailCost) itemDetailCost.textContent = formatCurrency(item.cost_price);
    if (itemDetailMargin) {
      const marginVal = item.margin_percent || 0;
      itemDetailMargin.textContent = `${marginVal}%`;
    }
    if (itemDetailSku) itemDetailSku.textContent = item.sku || "-";
    if (itemDetailCategory) itemDetailCategory.textContent = item.category || "General";
    if (itemDetailUnit) itemDetailUnit.textContent = item.unit || "pcs";
    if (itemDetailMinStock) itemDetailMinStock.textContent = `${item.min_stock_alert} ${item.unit}`;
    if (itemDetailDescription) itemDetailDescription.textContent = item.description || "No description provided.";

    if (itemDetailAdjustBtn) itemDetailAdjustBtn.hidden = (item.type === "service");
    if (itemDetailModal) itemDetailModal.hidden = false;
  };

  const closeItemDetail = () => {
    if (itemDetailModal) itemDetailModal.hidden = true;
    currentItem = null;
  };

  const openCreateItemModal = () => {
    if (itemFormError) itemFormError.hidden = true;
    if (itemFormTitle) itemFormTitle.textContent = "Add Product / Service";
    if (itemFormId) itemFormId.value = "";
    itemForm?.reset();
    setItemTypeChoice("product");
    populateCategorySelect(cachedCategories, "General");
    if (statusField) statusField.hidden = true;
    if (initialStockWrap) initialStockWrap.hidden = false;
    if (itemVatField) itemVatField.hidden = false;
    if (itemVatCheckbox) itemVatCheckbox.checked = true; // new items default to taxable
    if (itemVatHint) itemVatHint.textContent = vatConfig.enabled ? "" : "(VAT is off — no tax applied yet)";
    if (itemTaxModeToggle) itemTaxModeToggle.checked = false; // default tax exclusive
    syncItemTaxMode();
    if (itemFormModal) itemFormModal.hidden = false;
    itemForm?.querySelector('input[name="name"]')?.focus();
  };

  const openEditItemModal = (item = currentItem) => {
    if (!item) return;
    currentItem = item;
    if (itemFormError) itemFormError.hidden = true;
    if (itemFormTitle) itemFormTitle.textContent = `Edit ${item.name}`;
    if (itemFormId) itemFormId.value = String(item.id);
    if (initialStockWrap) initialStockWrap.hidden = true;
    if (statusField) statusField.hidden = false;

    setItemTypeChoice(item.type || "product");
    populateCategorySelect(cachedCategories, item.category || "General");
    setSearchSelectValue(unitSelectEl, item.unit || "pcs");

    if (itemForm) {
      itemForm.elements["name"].value = item.name || "";
      itemForm.elements["selling_price"].value = item.selling_price || 0;
      itemForm.elements["cost_price"].value = item.cost_price || 0;
      itemForm.elements["min_stock_alert"].value = item.min_stock_alert ?? 5;
      itemForm.elements["sku"].value = item.sku || "";
      itemForm.elements["barcode"].value = item.barcode || "";
      itemForm.elements["description"].value = item.description || "";
      itemForm.elements["status"].value = item.status || "active";
    }

    if (itemVatField) itemVatField.hidden = false;
    if (itemVatCheckbox) itemVatCheckbox.checked = Number(item.vat_applicable ?? 1) === 1;
    if (itemVatHint) itemVatHint.textContent = vatConfig.enabled ? "" : "(VAT is off — no tax applied yet)";
    if (itemTaxModeToggle) itemTaxModeToggle.checked = Number(item.tax_inclusive ?? 0) === 1;
    syncItemTaxMode();

    if (itemDetailModal) itemDetailModal.hidden = true;
    if (itemFormModal) itemFormModal.hidden = false;
    itemForm?.querySelector('input[name="name"]')?.focus();
  };

  const closeItemFormModal = () => {
    if (itemFormModal) itemFormModal.hidden = true;
    if (itemFormError) itemFormError.hidden = true;
  };

  // Item Filter Tabs
  itemTypeFilters.forEach((btn) => {
    btn.addEventListener("click", () => {
      itemTypeFilters.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      activeItemType = btn.dataset.filterType || "all";
      loadItems(itemsSearch?.value.trim() || "");
    });
  });

  let itemSearchTimeout;
  itemsSearch?.addEventListener("input", (e) => {
    clearTimeout(itemSearchTimeout);
    itemSearchTimeout = setTimeout(() => {
      loadItems(e.target.value.trim());
    }, 250);
  });

  openCreateItemBtns.forEach((btn) => btn.addEventListener("click", openCreateItemModal));
  stockQuickAddBtn?.addEventListener("click", openCreateItemModal);
  itemDetailCloseBtn?.addEventListener("click", closeItemDetail);
  itemFormCloseBtn?.addEventListener("click", closeItemFormModal);

  itemDetailEditBtn?.addEventListener("click", () => {
    openEditItemModal(currentItem);
  });

  itemDetailDeleteBtn?.addEventListener("click", async () => {
    if (!currentItem) return;
    const itemToDelete = currentItem;
    const confirmed = await showConfirmModal({
      title: "Delete Item",
      message: `Are you sure you want to delete "${itemToDelete.name}"? If it has purchase order history, it will be deactivated.`,
      confirmLabel: "Delete",
      cancelLabel: "Cancel",
      danger: true,
    });
    if (!confirmed) return;

    itemDetailDeleteBtn.disabled = true;
    try {
      const formData = new FormData();
      formData.set("action", "delete");
      formData.set("item_id", String(itemToDelete.id));

      const res = await fetch(`${getBasePath()}api/items.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to delete item.");
      }

      closeItemDetail();
      await loadItems(itemsSearch?.value.trim() || "");
      await showAppModal("Item Removed", data.message || "Item was removed successfully.");
    } catch (err) {
      await showAppModal("Error", err.message || "Could not delete item.");
    } finally {
      itemDetailDeleteBtn.disabled = false;
    }
  });

  itemForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (itemFormError) itemFormError.hidden = true;
    if (itemFormSubmit) itemFormSubmit.disabled = true;

    try {
      const isEditing = Boolean(itemFormId?.value);

      // Resolve a brand-new category typed by the user
      const usingNewCategory = categoryValueInput && categoryValueInput.value === CATEGORY_NEW_VALUE;
      const newCat = (newCategoryInput?.value || "").trim();
      if (usingNewCategory && !newCat) {
        throw new Error("Please enter a name for the new category.");
      }

      const formData = new FormData(itemForm);
      formData.set("action", isEditing ? "update" : "create");
      if (usingNewCategory) {
        formData.set("category", newCat);
      }
      // Honour the Taxable checkbox. The flag is stored regardless of whether VAT is
      // currently enabled, so turning VAT on later applies tax to the right items.
      const isTaxable = itemVatCheckbox && itemVatCheckbox.checked;
      formData.set("vat_applicable", isTaxable ? "1" : "0");
      formData.set("tax_inclusive", (isTaxable && itemTaxModeToggle && itemTaxModeToggle.checked) ? "1" : "0");

      const res = await fetch(`${getBasePath()}api/items.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to save item.");
      }

      closeItemFormModal();
      await loadItems(itemsSearch?.value.trim() || "");
      if (data.item) {
        openItemDetail(data.item);
      }
      await showAppModal("Saved Successfully", data.message || "Item details saved.");
    } catch (err) {
      if (itemFormError) {
        itemFormError.textContent = err.message || "Failed to save item.";
        itemFormError.hidden = false;
      }
    } finally {
      if (itemFormSubmit) itemFormSubmit.disabled = false;
    }
  });

  // ----------------------------------------
  // Section 2: Stock Adjustment Logic
  // ----------------------------------------

  const openStockAdjustModal = (preselectedItem = null) => {
    if (adjustError) adjustError.hidden = true;
    adjustForm?.reset();

    // Populate the product search-select
    const prodItems = cachedItems.filter((i) => i.type === "product");
    if (adjustItemSelect) {
      const options = prodItems.map((p) => ({
        value: String(p.id),
        label: `${p.name} (Stock: ${p.current_stock} ${p.unit})`,
      }));
      setSearchSelectOptions(adjustItemSelect, options, "Choose a product...", preselectedItem ? String(preselectedItem.id) : "");
    }
    // Reset the adjustment-type search-select label to its default ("add")
    setSearchSelectValue(adjustTypeSelect, "add");

    updateAdjustPreview();
    if (itemDetailModal) itemDetailModal.hidden = true;
    if (adjustModal) adjustModal.hidden = false;
  };

  const updateAdjustPreview = () => {
    const selectedId = adjustItemValue?.value;
    const match = cachedItems.find((i) => String(i.id) === String(selectedId));
    if (match && adjustPreview && adjustCurrentStock) {
      adjustCurrentStock.textContent = `${match.current_stock} ${match.unit}`;
      adjustPreview.hidden = false;
    } else if (adjustPreview) {
      adjustPreview.hidden = true;
    }
  };

  adjustItemValue?.addEventListener("change", updateAdjustPreview);
  openAdjustBtns.forEach((btn) => btn.addEventListener("click", () => openStockAdjustModal()));
  adjustCloseBtn?.addEventListener("click", () => {
    if (adjustModal) adjustModal.hidden = true;
  });

  itemDetailAdjustBtn?.addEventListener("click", () => {
    openStockAdjustModal(currentItem);
  });

  adjustForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (adjustError) adjustError.hidden = true;
    if (adjustSubmit) adjustSubmit.disabled = true;

    try {
      const formData = new FormData(adjustForm);
      formData.set("action", "adjust_stock");

      const res = await fetch(`${getBasePath()}api/items.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to adjust stock.");
      }

      if (adjustModal) adjustModal.hidden = true;
      await loadItems(itemsSearch?.value.trim() || "");
      await loadStockMovements();
      await showAppModal("Stock Adjusted", data.message || "Stock was updated successfully.");
    } catch (err) {
      if (adjustError) {
        adjustError.textContent = err.message || "Failed to adjust stock.";
        adjustError.hidden = false;
      }
    } finally {
      if (adjustSubmit) adjustSubmit.disabled = false;
    }
  });

  // ----------------------------------------
  // Section 3: Purchasing (PO & GRN) Logic
  // ----------------------------------------

  const loadSuppliersForPo = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/suppliers.php`);
      if (!res.ok) return [];
      const data = await res.json();
      cachedSuppliers = Array.isArray(data.suppliers) ? data.suppliers : [];
      if (poSupplierSelect) {
        const options = cachedSuppliers.map((s) => ({
          value: String(s.id),
          label: `${s.supplier_name || s.full_name} (${s.phone || "No phone"})`,
        }));
        setSearchSelectOptions(poSupplierSelect, options, "Choose supplier...", "");
      }
      return cachedSuppliers;
    } catch {
      return [];
    }
  };

  const calculatePoTotals = () => {
    let subtotal = 0;
    let vatBase = 0;
    poLinesContainer?.querySelectorAll(".po-line-item-row").forEach((row) => {
      const qtyInput = row.querySelector('input[name="line_qty"]');
      const costInput = row.querySelector('input[name="line_cost"]');
      const selectValue = row.querySelector('[name="line_item_id"]');
      const totalSpan = row.querySelector("[data-line-total]");

      const qty = parseFloat(qtyInput?.value || "0") || 0;
      const cost = parseFloat(costInput?.value || "0") || 0;
      const lineTot = qty * cost;
      subtotal += lineTot;

      if (vatConfig.enabled) {
        const chosen = cachedItems.find((p) => String(p.id) === String(selectValue?.value || ""));
        if (chosen && Number(chosen.vat_applicable ?? 1) === 1) {
          vatBase += lineTot;
        }
      }

      if (totalSpan) {
        totalSpan.textContent = formatCurrency(lineTot);
      }
    });

    const taxRate = vatConfig.enabled ? vatConfig.rate : 0;
    const taxAmt = (vatBase * taxRate) / 100;
    const grandTot = subtotal + taxAmt;

    if (poVatRow) poVatRow.hidden = !vatConfig.enabled;
    if (poVatRateLabel) poVatRateLabel.textContent = String(taxRate);
    if (poCalcSubtotal) poCalcSubtotal.textContent = formatCurrency(subtotal);
    if (poCalcTax) poCalcTax.textContent = formatCurrency(taxAmt);
    if (poCalcTotal) poCalcTotal.textContent = formatCurrency(grandTot);
  };

  const addPoLineItem = () => {
    if (!poLinesContainer) return;
    const prodItems = cachedItems.filter((i) => i.type === "product");

    const row = document.createElement("div");
    row.className = "po-line-item-row";

    const select = createSearchSelectElement({
      name: "line_item_id",
      placeholder: "Select product...",
      required: true,
      options: prodItems.map((p) => ({ value: String(p.id), label: p.name })),
    });
    const selectValue = select.querySelector("[data-search-select-value]");

    const qtyInput = document.createElement("input");
    qtyInput.type = "text";
    qtyInput.inputMode = "numeric";
    qtyInput.name = "line_qty";
    qtyInput.value = "1";
    qtyInput.placeholder = "Qty";
    qtyInput.style.textAlign = "right";

    const costInput = document.createElement("input");
    costInput.type = "text";
    costInput.inputMode = "numeric";
    costInput.name = "line_cost";
    costInput.value = "0";
    costInput.placeholder = "Cost";
    costInput.style.textAlign = "right";

    selectValue?.addEventListener("change", () => {
      const chosen = prodItems.find((p) => String(p.id) === String(selectValue.value));
      costInput.value = chosen ? (chosen.cost_price || 0) : 0;
      calculatePoTotals();
    });

    qtyInput.addEventListener("input", calculatePoTotals);
    costInput.addEventListener("input", calculatePoTotals);

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "icon-button";
    removeBtn.style.padding = "4px";
    removeBtn.style.color = "var(--color-danger)";
    removeBtn.innerHTML = `<svg viewBox="0 0 512 512" aria-hidden="true" style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 48;"><path d="M112 112l288 288M400 112L112 400" /></svg>`;
    removeBtn.addEventListener("click", () => {
      row.remove();
      calculatePoTotals();
    });

    row.append(select, qtyInput, costInput, removeBtn);
    poLinesContainer.append(row);
    calculatePoTotals();
  };

  addPoLineBtn?.addEventListener("click", addPoLineItem);

  const openCreatePoModal = async () => {
    if (poCreateError) poCreateError.hidden = true;
    poCreateForm?.reset();
    if (poCreateForm) {
      poCreateForm.elements["order_date"].value = new Date().toISOString().split("T")[0];
    }
    await loadSuppliersForPo();
    if (poLinesContainer) poLinesContainer.replaceChildren();
    addPoLineItem(); // add initial item row
    calculatePoTotals();
    if (poCreateModal) poCreateModal.hidden = false;
  };

  const closeCreatePoModal = () => {
    if (poCreateModal) poCreateModal.hidden = true;
  };

  openCreatePoBtns.forEach((btn) => btn.addEventListener("click", openCreatePoModal));
  poCreateCloseBtn?.addEventListener("click", closeCreatePoModal);

  poCreateForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (poCreateError) poCreateError.hidden = true;
    if (poCreateSubmit) poCreateSubmit.disabled = true;

    try {
      const supplierId = poSupplierValue?.value;
      if (!supplierId) {
        throw new Error("Please select a vendor/supplier.");
      }

      const items = [];
      poLinesContainer?.querySelectorAll(".po-line-item-row").forEach((row) => {
        const select = row.querySelector('[name="line_item_id"]');
        const qtyInput = row.querySelector('input[name="line_qty"]');
        const costInput = row.querySelector('input[name="line_cost"]');
        const itemId = parseInt(select?.value || "0", 10);
        const qty = parseFloat(qtyInput?.value || "0");
        const cost = parseFloat(costInput?.value || "0");

        if (itemId > 0 && qty > 0) {
          items.push({ item_id: itemId, quantity: qty, unit_cost: cost });
        }
      });

      if (!items.length) {
        throw new Error("Please select at least one valid product for this purchase order.");
      }

      const formData = new FormData(poCreateForm);
      formData.set("action", "create_po");
      formData.set("items", JSON.stringify(items));

      const res = await fetch(`${getBasePath()}api/purchasing.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to create purchase order.");
      }

      closeCreatePoModal();
      await loadPurchaseOrders();
      if (data.po_id) {
        await loadSinglePoAndOpen(data.po_id);
      }
      await showAppModal("Purchase Order Created", data.message || "PO has been recorded.");
    } catch (err) {
      if (poCreateError) {
        poCreateError.textContent = err.message || "Failed to create purchase order.";
        poCreateError.hidden = false;
      }
    } finally {
      if (poCreateSubmit) poCreateSubmit.disabled = false;
    }
  });

  const renderPoList = (orders = []) => {
    if (!orders || !orders.length) {
      if (poList) poList.replaceChildren();
      if (poEmpty) poEmpty.hidden = false;
      return;
    }

    if (poEmpty) poEmpty.hidden = true;

    poList?.replaceChildren(...orders.map((po) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row po-row";
      row.dataset.poId = String(po.id);
      row.style.display = "flex";
      row.style.alignItems = "center";
      row.style.justifyContent = "space-between";
      row.style.width = "100%";
      row.style.padding = "12px 14px";
      row.style.background = "#fff";
      row.style.border = "1px solid var(--color-line)";
      row.style.borderRadius = "10px";
      row.style.cursor = "pointer";

      const left = document.createElement("div");
      left.style.display = "flex";
      left.style.alignItems = "center";
      left.style.gap = "10px";
      left.style.textAlign = "left";

      const avatar = document.createElement("div");
      avatar.className = "customer-avatar";
      avatar.style.width = "38px";
      avatar.style.height = "38px";
      avatar.style.fontSize = "1.1rem";
      avatar.innerHTML = svgMarkup("clipboard");

      const info = document.createElement("div");
      const name = document.createElement("div");
      name.style.fontWeight = "800";
      name.style.color = "var(--color-navy)";
      name.style.fontSize = "0.94rem";
      name.textContent = po.po_number;

      const sub = document.createElement("div");
      sub.style.fontSize = "0.78rem";
      sub.style.color = "var(--color-muted)";
      sub.textContent = `${po.supplier_name || "Supplier"} • ${formatDate(po.order_date)} • ${po.items_count || 1} items`;

      info.append(name, sub);
      left.append(avatar, info);

      const right = document.createElement("div");
      right.style.display = "flex";
      right.style.alignItems = "center";
      right.style.gap = "8px";

      const amountBadge = document.createElement("div");
      amountBadge.style.textAlign = "right";

      const amtVal = document.createElement("strong");
      amtVal.style.color = "var(--color-navy)";
      amtVal.style.fontSize = "0.92rem";
      amtVal.textContent = formatCurrency(po.total_amount);

      const statusTag = document.createElement("span");
      let statusCls = "pending";
      if (po.status === "received") statusCls = "approved";
      if (po.status === "cancelled") statusCls = "rejected";
      if (po.status === "sent") statusCls = "pending";
      statusTag.className = `badge-status ${statusCls}`;
      statusTag.style.display = "block";
      statusTag.style.marginTop = "2px";
      statusTag.textContent = (po.status || "draft").toUpperCase();

      amountBadge.append(amtVal, statusTag);

      const chevron = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      chevron.setAttribute("viewBox", "0 0 512 512");
      chevron.setAttribute("aria-hidden", "true");
      chevron.style.width = "16px";
      chevron.style.height = "16px";
      chevron.style.stroke = "var(--color-muted)";
      chevron.style.fill = "none";
      chevron.style.strokeWidth = "32";
      const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M184 112l144 144-144 144");
      chevron.append(path);

      right.append(amountBadge, chevron);
      row.append(left, right);

      row.addEventListener("click", () => loadSinglePoAndOpen(po.id));
      return row;
    }));
  };

  const loadPurchaseOrders = async (query = "") => {
    try {
      const params = new URLSearchParams();
      if (activePoStatus !== "all") {
        params.set("status", activePoStatus);
      }
      if (query) {
        params.set("q", query);
      }

      const res = await fetch(`${getBasePath()}api/purchasing.php?${params.toString()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      cachedPOs = Array.isArray(data.orders) ? data.orders : [];
      renderPoList(cachedPOs);

      if (kpiTotalPo) kpiTotalPo.textContent = String(data.stats?.total_orders || 0);
      if (poCountBadge) poCountBadge.textContent = `${data.stats?.total_orders || 0} orders`;
    } catch {
      // Quiet fail if offline
    }
  };

  const loadSinglePoAndOpen = async (poId) => {
    try {
      const res = await fetch(`${getBasePath()}api/purchasing.php?id=${poId}`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      currentPO = data.po;
      currentPO.items = data.items || [];
      currentPO.supplier = data.supplier || {};
      currentPO.goods_received = data.goods_received || [];

      openPoDetail(currentPO);
    } catch (e) {
      showAppModal("Error", "Could not load purchase order details: " + e.message);
    }
  };

  const openPoDetail = (po) => {
    if (poDetailNum) poDetailNum.textContent = po.po_number;
    if (poDetailSupplier) poDetailSupplier.textContent = po.supplier?.supplier_name || po.supplier_name || "Supplier";
    if (poDetailDate) poDetailDate.textContent = formatDate(po.order_date);
    if (poDetailExpected) poDetailExpected.textContent = formatDate(po.expected_date);
    if (poDetailPhone) poDetailPhone.textContent = po.supplier?.phone || "-";
    if (poDetailEmail) poDetailEmail.textContent = po.supplier?.email || "-";
    if (poDetailGrandTotal) poDetailGrandTotal.textContent = formatCurrency(po.total_amount);

    if (poDetailStatusBadge) {
      let statusCls = "pending";
      if (po.status === "received") statusCls = "approved";
      if (po.status === "cancelled") statusCls = "rejected";
      poDetailStatusBadge.className = `badge-status ${statusCls}`;
      poDetailStatusBadge.textContent = (po.status || "draft").toUpperCase();
    }

    if (poDetailNotesWrap && poDetailNotes) {
      if (po.notes) {
        poDetailNotes.textContent = po.notes;
        poDetailNotesWrap.hidden = false;
      } else {
        poDetailNotesWrap.hidden = true;
      }
    }

    // Render line items
    if (poDetailItemsList) {
      poDetailItemsList.innerHTML = (po.items || []).map((item, idx) => {
        const received = parseFloat(item.received_quantity || "0");
        const ordered = parseFloat(item.quantity || "0");
        const isFullyReceived = received >= ordered;
        const recBadge = isFullyReceived
          ? `<span class="badge-stock in-stock" style="font-size: 0.72rem;">Received ${received}/${ordered}</span>`
          : (received > 0 ? `<span class="badge-stock low-stock" style="font-size: 0.72rem;">Partial ${received}/${ordered}</span>` : `<span class="badge-stock" style="font-size: 0.72rem; background: #f1f5f9; color: var(--color-muted);">0/${ordered} received</span>`);

        return `
          <div style="padding: 10px 14px; border-bottom: 1px solid var(--color-line); display: flex; justify-content: space-between; align-items: center;">
            <div>
              <strong style="color: var(--color-navy); font-size: 0.9rem;">${idx + 1}. ${item.item_name}</strong>
              <div style="font-size: 0.78rem; color: var(--color-muted); margin-top: 2px;">
                ${item.quantity} pcs @ ${formatCurrency(item.unit_cost)}
              </div>
            </div>
            <div style="text-align: right;">
              <strong style="font-size: 0.92rem; color: var(--color-text);">${formatCurrency(item.line_total)}</strong>
              <div style="margin-top: 3px;">${recBadge}</div>
            </div>
          </div>
        `;
      }).join("");
    }

    // Toggle action buttons by status
    const isCancelled = po.status === "cancelled";
    if (poReceiveBtn) {
      poReceiveBtn.hidden = (po.status === "received" || isCancelled);
    }
    if (poCancelBtn) {
      poCancelBtn.hidden = (po.status === "received" || isCancelled || po.status === "partially_received");
    }
    if (poUncancelBtn) {
      poUncancelBtn.hidden = !isCancelled;
    }

    if (poDetailModal) poDetailModal.hidden = false;
  };

  poDetailCloseBtn?.addEventListener("click", () => {
    if (poDetailModal) poDetailModal.hidden = true;
    currentPO = null;
  });

  // Action: Download PDF
  poDownloadPdfBtn?.addEventListener("click", () => {
    if (!currentPO) return;
    const url = `${getBasePath()}api/purchasing.php?action=pdf&id=${currentPO.id}`;
    window.open(url, "_blank");
  });

  // Action: Email PO to Vendor
  poEmailBtn?.addEventListener("click", async () => {
    if (!currentPO) return;
    const defaultEmail = currentPO.supplier?.email || currentPO.supplier_email || "";
    const emailTo = await showPromptModal({
      title: "Email Purchase Order",
      message: "Enter the vendor email address to send the PO PDF:",
      placeholder: "vendor@example.com",
      defaultValue: defaultEmail,
      confirmLabel: "Send",
      inputType: "email",
    });
    if (!emailTo) return;

    poEmailBtn.disabled = true;
    const origText = poEmailBtn.textContent;
    poEmailBtn.textContent = "Sending Email...";

    try {
      const formData = new FormData();
      formData.set("action", "email_po");
      formData.set("po_id", String(currentPO.id));
      formData.set("email", emailTo.trim());

      const res = await fetch(`${getBasePath()}api/purchasing.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to send email.");
      }

      await showAppModal("Email Sent", data.message || `Purchase order PDF has been sent to ${emailTo}.`);
      await loadSinglePoAndOpen(currentPO.id);
    } catch (e) {
      await showAppModal("Email Error", e.message || "Could not send email.");
    } finally {
      poEmailBtn.disabled = false;
      poEmailBtn.textContent = origText;
    }
  });

  // Action: Cancel PO
  poCancelBtn?.addEventListener("click", async () => {
    if (!currentPO) return;
    const confirmed = await showConfirmModal({
      title: "Cancel Purchase Order",
      message: `Are you sure you want to cancel purchase order ${currentPO.po_number}?`,
      confirmLabel: "Cancel Order",
      cancelLabel: "Keep Order",
      danger: true,
    });
    if (!confirmed) return;

    try {
      const formData = new FormData();
      formData.set("action", "cancel_po");
      formData.set("po_id", String(currentPO.id));

      const res = await fetch(`${getBasePath()}api/purchasing.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to cancel order.");
      }

      if (poDetailModal) poDetailModal.hidden = true;
      await loadPurchaseOrders();
      await showAppModal("Order Cancelled", "The purchase order has been cancelled.");
    } catch (e) {
      showAppModal("Error", e.message);
    }
  });

  // Action: Reactivate (uncancel) PO
  poUncancelBtn?.addEventListener("click", async () => {
    if (!currentPO) return;
    const confirmed = await showConfirmModal({
      title: "Reactivate Purchase Order",
      message: `Reactivate purchase order ${currentPO.po_number}? It will return to Draft status.`,
      confirmLabel: "Reactivate",
      cancelLabel: "Keep Cancelled",
    });
    if (!confirmed) return;

    try {
      const formData = new FormData();
      formData.set("action", "uncancel_po");
      formData.set("po_id", String(currentPO.id));

      const res = await fetch(`${getBasePath()}api/purchasing.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to reactivate order.");
      }

      await loadPurchaseOrders();
      await loadSinglePoAndOpen(currentPO.id);
      await showAppModal("Order Reactivated", "The purchase order is back to Draft status.");
    } catch (e) {
      showAppModal("Error", e.message);
    }
  });

  // Action: Goods Receiving (GRN)
  const openGrnModal = (po = currentPO) => {
    if (!po) return;
    currentPO = po;
    if (grnError) grnError.hidden = true;
    grnForm?.reset();

    if (grnPoId) grnPoId.value = String(po.id);
    if (grnPoNum) grnPoNum.textContent = `${po.po_number} (${po.supplier?.supplier_name || po.supplier_name || "Supplier"})`;

    if (grnForm) {
      grnForm.elements["received_date"].value = new Date().toISOString().split("T")[0];
    }

    if (grnItemsContainer) {
      grnItemsContainer.innerHTML = (po.items || []).map((item) => {
        const ordered = parseFloat(item.quantity || "0");
        const received = parseFloat(item.received_quantity || "0");
        const remaining = Math.max(0, ordered - received);
        const isDone = remaining <= 0;

        return `
          <div class="grn-line-row" style="background: #fff; border: 1px solid var(--color-line); border-radius: 8px; padding: 10px; margin-bottom: 8px;" data-poi-id="${item.id}">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <strong style="font-size: 0.92rem; color: var(--color-navy);">${item.item_name}</strong>
              <span style="font-size: 0.8rem; color: var(--color-muted);">Ordered: ${ordered} | Remaining: <strong style="color: ${isDone ? 'var(--color-muted)' : 'var(--color-blue)'};">${remaining}</strong></span>
            </div>
            ${isDone ? `
              <div style="font-size: 0.82rem; color: #047857; font-weight: 700;">${svgMarkup("check", { size: 13, style: "vertical-align:-2px;" })} Fully received</div>
            ` : `
              <div style="display: flex; align-items: center; gap: 8px;">
                <label style="font-size: 0.8rem; color: var(--color-muted); white-space: nowrap;">Receiving now:</label>
                <input type="text" inputmode="numeric" max="${remaining}" value="${remaining}" class="grn-qty-input" data-remaining="${remaining}" style="height: 36px; text-align: right;" required>
                <span style="font-size: 0.82rem; color: var(--color-muted);">${item.unit || "pcs"}</span>
              </div>
            `}
          </div>
        `;
      }).join("");
    }

    if (grnModal) grnModal.hidden = false;
  };

  poReceiveBtn?.addEventListener("click", () => openGrnModal(currentPO));
  grnCloseBtn?.addEventListener("click", () => {
    if (grnModal) grnModal.hidden = true;
  });

  grnReceiveAllBtn?.addEventListener("click", () => {
    grnItemsContainer?.querySelectorAll(".grn-line-row").forEach((row) => {
      const input = row.querySelector(".grn-qty-input");
      if (input && input.dataset.remaining) {
        input.value = input.dataset.remaining;
      }
    });
  });

  grnForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (grnError) grnError.hidden = true;
    if (grnSubmit) grnSubmit.disabled = true;

    try {
      const itemsToReceive = [];
      grnItemsContainer?.querySelectorAll(".grn-line-row").forEach((row) => {
        const poiId = parseInt(row.dataset.poiId || "0", 10);
        const input = row.querySelector(".grn-qty-input");
        if (input) {
          const qty = parseFloat(input.value || "0");
          if (poiId > 0 && qty > 0) {
            itemsToReceive.push({ po_item_id: poiId, quantity_received: qty });
          }
        }
      });

      if (!itemsToReceive.length) {
        throw new Error("Please enter quantities to receive for at least one item.");
      }

      const formData = new FormData(grnForm);
      formData.set("action", "receive_goods");
      formData.set("items", JSON.stringify(itemsToReceive));

      const res = await fetch(`${getBasePath()}api/purchasing.php`, {
        method: "POST",
        body: formData,
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Failed to receive goods.");
      }

      if (grnModal) grnModal.hidden = true;
      await loadItems();
      await loadStockMovements();
      await loadPurchaseOrders();

      if (currentPO?.id) {
        await loadSinglePoAndOpen(currentPO.id);
      }

      await showAppModal("Goods Received", data.message || "Inventory stock was successfully updated.");
    } catch (err) {
      if (grnError) {
        grnError.textContent = err.message || "Failed to receive goods.";
        grnError.hidden = false;
      }
    } finally {
      if (grnSubmit) grnSubmit.disabled = false;
    }
  });

  // PO Filter Tabs
  poStatusFilters.forEach((btn) => {
    btn.addEventListener("click", () => {
      poStatusFilters.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      activePoStatus = btn.dataset.filterPo || "all";
      loadPurchaseOrders(poSearch?.value.trim() || "");
    });
  });

  let poSearchTimeout;
  poSearch?.addEventListener("input", (e) => {
    clearTimeout(poSearchTimeout);
    poSearchTimeout = setTimeout(() => {
      loadPurchaseOrders(e.target.value.trim());
    }, 250);
  });

  // ----------------------------------------
  // Section 4: Stock Movement Ledger Logic
  // ----------------------------------------

  const renderMovementsList = (movements = []) => {
    if (!movements || !movements.length) {
      if (movementsList) movementsList.replaceChildren();
      if (movementsEmpty) movementsEmpty.hidden = false;
      return;
    }

    if (movementsEmpty) movementsEmpty.hidden = true;

    movementsList?.replaceChildren(...movements.map((m) => {
      const row = document.createElement("div");
      row.className = "settings-list-row movement-row";
      row.style.display = "flex";
      row.style.alignItems = "center";
      row.style.justifyContent = "space-between";
      row.style.width = "100%";
      row.style.padding = "12px 14px";
      row.style.background = "#fff";
      row.style.border = "1px solid var(--color-line)";
      row.style.borderRadius = "10px";

      const qty = parseFloat(m.quantity || "0");
      const isPositive = qty > 0;

      const left = document.createElement("div");
      left.style.display = "flex";
      left.style.alignItems = "center";
      left.style.gap = "10px";
      left.style.textAlign = "left";

      const avatar = document.createElement("div");
      avatar.className = "customer-avatar";
      avatar.style.width = "38px";
      avatar.style.height = "38px";
      avatar.style.fontSize = "1rem";
      avatar.style.background = isPositive ? "rgba(16, 185, 129, 0.12)" : "rgba(239, 68, 68, 0.12)";
      avatar.style.color = isPositive ? "#047857" : "#b91c1c";
      avatar.textContent = isPositive ? "↑" : "↓";

      const info = document.createElement("div");
      const name = document.createElement("div");
      name.style.fontWeight = "800";
      name.style.color = "var(--color-navy)";
      name.style.fontSize = "0.92rem";
      name.textContent = m.item_name || "Item";

      const sub = document.createElement("div");
      sub.style.fontSize = "0.78rem";
      sub.style.color = "var(--color-muted)";
      const refPart = m.reference_id ? `${m.reference_id} • ` : "";
      sub.textContent = `${refPart}${formatDate(m.created_at)} • by ${m.user_name || "User"}`;

      info.append(name, sub);
      left.append(avatar, info);

      const right = document.createElement("div");
      right.style.textAlign = "right";

      const changeVal = document.createElement("strong");
      changeVal.style.fontSize = "0.95rem";
      changeVal.style.color = isPositive ? "#047857" : "#b91c1c";
      changeVal.textContent = `${isPositive ? "+" : ""}${qty} ${m.item_unit || "pcs"}`;

      const balanceVal = document.createElement("div");
      balanceVal.style.fontSize = "0.75rem";
      balanceVal.style.color = "var(--color-muted)";
      balanceVal.textContent = `Balance: ${m.new_stock} ${m.item_unit || "pcs"}`;

      right.append(changeVal, balanceVal);
      row.append(left, right);

      return row;
    }));
  };

  const loadStockMovements = async (query = "") => {
    try {
      const params = new URLSearchParams();
      if (activeMovementType !== "all") {
        params.set("movement_type", activeMovementType);
      }
      if (query) {
        params.set("q", query);
      }

      const res = await fetch(`${getBasePath()}api/stock-movements.php?${params.toString()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;

      const movements = Array.isArray(data.movements) ? data.movements : [];
      renderMovementsList(movements);

      if (kpiTodayMovements) kpiTodayMovements.textContent = String(data.stats?.today_movements || 0);
      if (movementsCountBadge) movementsCountBadge.textContent = `${data.stats?.total_movements || 0} entries`;
    } catch {
      // Quiet fail if offline
    }
  };

  movementFilters.forEach((btn) => {
    btn.addEventListener("click", () => {
      movementFilters.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      activeMovementType = btn.dataset.filterMovement || "all";
      loadStockMovements(movementsSearch?.value.trim() || "");
    });
  });

  let movementSearchTimeout;
  movementsSearch?.addEventListener("input", (e) => {
    clearTimeout(movementSearchTimeout);
    movementSearchTimeout = setTimeout(() => {
      loadStockMovements(e.target.value.trim());
    }, 250);
  });

  // ----------------------------------------
  // Section workspaces (tap-to-open fullscreen)
  // ----------------------------------------
  const itemsWorkspace = document.querySelector("[data-items-workspace]");
  const purchasingWorkspace = document.querySelector("[data-purchasing-workspace]");
  const movementsWorkspace = document.querySelector("[data-movements-workspace]");

  const openWorkspace = (el, refresh) => {
    if (!el) return;
    el.hidden = false;
    if (typeof refresh === "function") refresh();
  };
  const closeWorkspace = (el) => {
    if (el) el.hidden = true;
  };

  document.querySelectorAll("[data-open-items-workspace]").forEach((btn) => {
    btn.addEventListener("click", () => openWorkspace(itemsWorkspace, () => loadItems(itemsSearch?.value.trim() || "")));
  });
  document.querySelector("[data-items-workspace-close]")?.addEventListener("click", () => closeWorkspace(itemsWorkspace));

  document.querySelectorAll("[data-open-purchasing-workspace]").forEach((btn) => {
    btn.addEventListener("click", () => openWorkspace(purchasingWorkspace, () => loadPurchaseOrders(poSearch?.value.trim() || "")));
  });
  document.querySelector("[data-purchasing-workspace-close]")?.addEventListener("click", () => closeWorkspace(purchasingWorkspace));

  document.querySelectorAll("[data-open-movements-workspace]").forEach((btn) => {
    btn.addEventListener("click", () => openWorkspace(movementsWorkspace, () => loadStockMovements(movementsSearch?.value.trim() || "")));
  });
  document.querySelector("[data-movements-workspace-close]")?.addEventListener("click", () => closeWorkspace(movementsWorkspace));

  // ----------------------------------------
  // Warehouses (registry + default)
  // ----------------------------------------
  const warehousesWorkspace = document.querySelector("[data-warehouses-workspace]");
  const warehousesList = document.querySelector("[data-warehouses-list]");
  const warehousesEmpty = document.querySelector("[data-warehouses-empty]");
  const warehousesCountBadge = document.querySelector("[data-warehouses-count-badge]");
  const warehouseFormModal = document.querySelector("[data-warehouse-form-modal]");
  const warehouseForm = document.querySelector("[data-warehouse-form]");
  const warehouseFormTitle = document.querySelector("[data-warehouse-form-title]");
  const warehouseFormId = document.querySelector("[data-warehouse-form-id]");
  const warehouseFormDefault = document.querySelector("[data-warehouse-form-default]");
  const warehouseFormError = document.querySelector("[data-warehouse-form-error]");
  const warehouseFormSubmit = document.querySelector("[data-warehouse-form-submit]");

  const escWh = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  let warehouseCache = [];

  const renderWarehouses = (list = []) => {
    warehouseCache = list;
    if (warehousesCountBadge) warehousesCountBadge.textContent = `${list.length} location${list.length === 1 ? "" : "s"}`;
    if (!list.length) {
      warehousesList?.replaceChildren();
      if (warehousesEmpty) warehousesEmpty.hidden = false;
      return;
    }
    if (warehousesEmpty) warehousesEmpty.hidden = true;
    if (warehousesList) {
      warehousesList.innerHTML = list.map((w) => {
        const meta = [w.code, w.location].filter(Boolean).map(escWh).join(" • ") || "No code or location";
        const defBadge = w.is_default ? ` <span class="badge-stock in-stock">Default</span>` : "";
        const setDefaultBtn = w.is_default ? "" : `<button type="button" class="btn btn-outline btn-sm" data-wh-set-default="${w.id}">Set default</button>`;
        return `
          <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
            <div style="text-align:left;min-width:0;">
              <div style="font-weight:800;color:var(--color-navy);font-size:0.94rem;">${escWh(w.name)}${defBadge}</div>
              <div style="font-size:0.78rem;color:var(--color-muted);">${meta}</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end;flex:0 0 auto;">
              <button type="button" class="btn btn-primary btn-sm" data-wh-stock="${w.id}">Stock</button>
              ${setDefaultBtn}
              <button type="button" class="btn btn-outline btn-sm" data-wh-edit="${w.id}">Edit</button>
              <button type="button" class="btn btn-danger-outline btn-sm" data-wh-delete="${w.id}">Delete</button>
            </div>
          </div>`;
      }).join("");
    }
  };

  const loadWarehouses = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/warehouses.php`);
      const data = await res.json();
      if (res.ok && data.ok) renderWarehouses(data.warehouses || []);
    } catch {
      /* offline: keep whatever is rendered */
    }
  };

  const openWarehouseForm = (warehouse = null) => {
    if (!warehouseForm) return;
    warehouseForm.reset();
    if (warehouseFormError) warehouseFormError.hidden = true;
    if (warehouseFormId) warehouseFormId.value = warehouse ? String(warehouse.id) : "";
    if (warehouseFormTitle) warehouseFormTitle.textContent = warehouse ? "Edit Warehouse" : "Add Warehouse";
    if (warehouse) {
      warehouseForm.elements.name.value = warehouse.name || "";
      warehouseForm.elements.code.value = warehouse.code || "";
      warehouseForm.elements.location.value = warehouse.location || "";
      if (warehouseFormDefault) { warehouseFormDefault.checked = !!warehouse.is_default; warehouseFormDefault.disabled = !!warehouse.is_default; }
    } else if (warehouseFormDefault) {
      warehouseFormDefault.disabled = false;
    }
    if (warehouseFormModal) warehouseFormModal.hidden = false;
  };

  // Per-warehouse stock view (products listed under one warehouse; stock is global in this MVP).
  const warehouseStockWorkspace = document.querySelector("[data-warehouse-stock-workspace]");
  const warehouseStockTitle = document.querySelector("[data-warehouse-stock-title]");
  const warehouseProductsList = document.querySelector("[data-warehouse-products-list]");
  const warehouseProductsEmpty = document.querySelector("[data-warehouse-products-empty]");
  const warehouseProductsBadge = document.querySelector("[data-warehouse-products-badge]");
  const warehouseProductsSearch = document.querySelector("[data-warehouse-products-search]");
  let warehouseProductsCache = [];
  let currentStockWarehouse = null;

  const renderWarehouseProducts = (list = []) => {
    warehouseProductsCache = list;
    if (warehouseProductsBadge) warehouseProductsBadge.textContent = `${list.length} product${list.length === 1 ? "" : "s"}`;
    if (!list.length) {
      warehouseProductsList?.replaceChildren();
      if (warehouseProductsEmpty) warehouseProductsEmpty.hidden = false;
      return;
    }
    if (warehouseProductsEmpty) warehouseProductsEmpty.hidden = true;
    warehouseProductsList.innerHTML = list.map((p) => {
      const stock = Number(p.warehouse_qty) || 0;
      const stockCls = stock <= 0 ? "out-of-stock" : (p.is_low_stock ? "low-stock" : "in-stock");
      const stockTxt = `${stock} ${escWh(p.unit || "")}`.trim();
      const sub = [p.sku ? `SKU: ${escWh(p.sku)}` : "", escWh(p.category || "General")].filter(Boolean).join(" • ");
      return `
        <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
          <div style="min-width:0;text-align:left;">
            <div style="font-weight:800;color:var(--color-navy);font-size:0.92rem;">${escWh(p.name)}</div>
            <div style="font-size:0.78rem;color:var(--color-muted);">${sub}</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex:0 0 auto;">
            <span class="badge-stock ${stockCls}">${stockTxt}</span>
            <button type="button" class="btn btn-outline btn-sm" data-wh-transfer="${p.id}">Transfer</button>
            <button type="button" class="btn btn-outline btn-sm" data-wh-view-product="${p.id}">View</button>
          </div>
        </div>`;
    }).join("");
  };

  const loadWarehouseProducts = async (query = "") => {
    if (!currentStockWarehouse) return;
    try {
      const params = new URLSearchParams({ stock_warehouse_id: String(currentStockWarehouse.id) });
      if (query) params.set("q", query);
      const res = await fetch(`${getBasePath()}api/warehouses.php?${params.toString()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.ok) renderWarehouseProducts(Array.isArray(data.products) ? data.products : []);
    } catch {
      /* offline: keep current */
    }
  };

  const openWarehouseStock = (warehouse) => {
    if (!warehouse) return;
    currentStockWarehouse = warehouse;
    if (warehouseStockTitle) warehouseStockTitle.textContent = `${warehouse.name} — Stock`;
    if (warehouseProductsSearch) warehouseProductsSearch.value = "";
    openWorkspace(warehouseStockWorkspace, () => loadWarehouseProducts(""));
  };

  let whProductsSearchTimeout;
  warehouseProductsSearch?.addEventListener("input", (e) => {
    clearTimeout(whProductsSearchTimeout);
    whProductsSearchTimeout = setTimeout(() => loadWarehouseProducts(e.target.value.trim()), 250);
  });

  warehouseProductsList?.addEventListener("click", async (e) => {
    const transferId = e.target.closest("[data-wh-transfer]")?.dataset.whTransfer;
    if (transferId) {
      const product = warehouseProductsCache.find((x) => String(x.id) === String(transferId));
      if (product) openStockTransfer(product);
      return;
    }
    const viewId = e.target.closest("[data-wh-view-product]")?.dataset.whViewProduct;
    if (!viewId) return;
    try {
      const res = await fetch(`${getBasePath()}api/items.php?id=${encodeURIComponent(viewId)}`);
      const data = await res.json();
      if (res.ok && data.ok && data.item) openItemDetail(data.item);
    } catch { /* offline */ }
  });

  // ---- Stock transfer between warehouses ----
  const stockTransferModal = document.querySelector("[data-stock-transfer-modal]");
  const stockTransferForm = document.querySelector("[data-stock-transfer-form]");
  const stockTransferItemName = document.querySelector("[data-stock-transfer-item]");
  const stockTransferItemId = document.querySelector("[data-stock-transfer-item-id]");
  const stockTransferFromId = document.querySelector("[data-stock-transfer-from-id]");
  const stockTransferFromName = document.querySelector("[data-stock-transfer-from-name]");
  const stockTransferTo = document.querySelector("[data-stock-transfer-to]");
  const stockTransferAvail = document.querySelector("[data-stock-transfer-avail]");
  const stockTransferError = document.querySelector("[data-stock-transfer-error]");
  const stockTransferSubmit = document.querySelector("[data-stock-transfer-submit]");

  const openStockTransfer = (product) => {
    if (!stockTransferForm || !currentStockWarehouse) return;
    stockTransferForm.reset();
    if (stockTransferError) stockTransferError.hidden = true;
    if (stockTransferItemName) stockTransferItemName.textContent = product.name;
    if (stockTransferItemId) stockTransferItemId.value = String(product.id);
    if (stockTransferFromId) stockTransferFromId.value = String(currentStockWarehouse.id);
    if (stockTransferFromName) stockTransferFromName.textContent = currentStockWarehouse.name;
    if (stockTransferAvail) stockTransferAvail.textContent = `Available here: ${Number(product.warehouse_qty) || 0} ${product.unit || ""}`.trim();
    if (stockTransferTo) {
      const others = warehouseCache.filter((w) => String(w.id) !== String(currentStockWarehouse.id));
      stockTransferTo.innerHTML = others.length
        ? others.map((w) => `<option value="${w.id}">${escWh(w.name)}${w.is_default ? " (default)" : ""}</option>`).join("")
        : `<option value="">No other warehouse</option>`;
    }
    if (stockTransferModal) stockTransferModal.hidden = false;
  };

  document.querySelector("[data-stock-transfer-close]")?.addEventListener("click", () => { if (stockTransferModal) stockTransferModal.hidden = true; });

  stockTransferForm?.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    if (stockTransferError) stockTransferError.hidden = true;
    const toId = stockTransferTo?.value || "";
    const qty = parseFloat(String(stockTransferForm.elements.quantity.value).replace(/[^0-9.\-]/g, "")) || 0;
    if (!toId) { if (stockTransferError) { stockTransferError.textContent = "Choose a destination warehouse."; stockTransferError.hidden = false; } return; }
    if (qty <= 0) { if (stockTransferError) { stockTransferError.textContent = "Enter a quantity greater than zero."; stockTransferError.hidden = false; } return; }
    if (stockTransferSubmit) stockTransferSubmit.disabled = true;
    try {
      const body = new FormData();
      body.set("action", "transfer_stock");
      body.set("from_warehouse_id", stockTransferFromId.value);
      body.set("to_warehouse_id", toId);
      body.set("item_id", stockTransferItemId.value);
      body.set("quantity", String(qty));
      body.set("notes", stockTransferForm.elements.notes.value.trim());
      const res = await fetch(`${getBasePath()}api/warehouses.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not transfer stock.");
      if (stockTransferModal) stockTransferModal.hidden = true;
      await loadWarehouseProducts(warehouseProductsSearch?.value.trim() || "");
    } catch (err) {
      if (stockTransferError) { stockTransferError.textContent = err.message || "Could not transfer stock."; stockTransferError.hidden = false; }
    } finally {
      if (stockTransferSubmit) stockTransferSubmit.disabled = false;
    }
  });

  document.querySelectorAll("[data-open-warehouses-workspace]").forEach((btn) => {
    btn.addEventListener("click", () => openWorkspace(warehousesWorkspace, loadWarehouses));
  });
  document.querySelector("[data-warehouses-workspace-close]")?.addEventListener("click", () => closeWorkspace(warehousesWorkspace));
  document.querySelector("[data-warehouse-stock-close]")?.addEventListener("click", () => closeWorkspace(warehouseStockWorkspace));
  document.querySelectorAll("[data-open-create-warehouse]").forEach((btn) => btn.addEventListener("click", () => openWarehouseForm(null)));
  document.querySelector("[data-warehouse-form-close]")?.addEventListener("click", () => { if (warehouseFormModal) warehouseFormModal.hidden = true; });

  warehousesList?.addEventListener("click", async (e) => {
    const editId = e.target.closest("[data-wh-edit]")?.dataset.whEdit;
    const delId = e.target.closest("[data-wh-delete]")?.dataset.whDelete;
    const defId = e.target.closest("[data-wh-set-default]")?.dataset.whSetDefault;
    const stockId = e.target.closest("[data-wh-stock]")?.dataset.whStock;
    if (stockId) {
      const w = warehouseCache.find((x) => String(x.id) === String(stockId));
      if (w) openWarehouseStock(w);
      return;
    }
    if (editId) {
      const w = warehouseCache.find((x) => String(x.id) === String(editId));
      if (w) openWarehouseForm(w);
      return;
    }
    if (defId) {
      const body = new FormData(); body.set("action", "set_default"); body.set("warehouse_id", defId);
      try {
        const res = await fetch(`${getBasePath()}api/warehouses.php`, { method: "POST", body });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not update.");
        renderWarehouses(data.warehouses || []);
      } catch (err) { await showAppModal("Warehouses", err.message || "Please try again."); }
      return;
    }
    if (delId) {
      const w = warehouseCache.find((x) => String(x.id) === String(delId));
      const confirmed = await showConfirmModal({ title: "Delete warehouse", message: `Delete "${w?.name || "this warehouse"}"? This cannot be undone.`, confirmLabel: "Delete", danger: true });
      if (!confirmed) return;
      const body = new FormData(); body.set("action", "delete"); body.set("warehouse_id", delId);
      try {
        const res = await fetch(`${getBasePath()}api/warehouses.php`, { method: "POST", body });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.message || "Could not delete.");
        renderWarehouses(data.warehouses || []);
      } catch (err) { await showAppModal("Warehouses", err.message || "Please try again."); }
    }
  });

  warehouseForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (warehouseFormError) warehouseFormError.hidden = true;
    const name = warehouseForm.elements.name.value.trim();
    if (!name) {
      if (warehouseFormError) { warehouseFormError.textContent = "Warehouse name is required."; warehouseFormError.hidden = false; }
      return;
    }
    if (warehouseFormSubmit) warehouseFormSubmit.disabled = true;
    try {
      const id = warehouseFormId?.value || "";
      const body = new FormData();
      body.set("action", id ? "update" : "create");
      if (id) body.set("warehouse_id", id);
      body.set("name", name);
      body.set("code", warehouseForm.elements.code.value.trim());
      body.set("location", warehouseForm.elements.location.value.trim());
      if (warehouseFormDefault?.checked) body.set("is_default", "1");
      const res = await fetch(`${getBasePath()}api/warehouses.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not save warehouse.");
      renderWarehouses(data.warehouses || []);
      if (warehouseFormModal) warehouseFormModal.hidden = true;
    } catch (err) {
      if (warehouseFormError) { warehouseFormError.textContent = err.message || "Could not save warehouse."; warehouseFormError.hidden = false; }
    } finally {
      if (warehouseFormSubmit) warehouseFormSubmit.disabled = false;
    }
  });

  // Initial Data Loads (populate KPIs + hub counters)
  loadItems();
  loadPurchaseOrders();
  loadStockMovements();
  loadWarehouses();

  const stockParams = new URLSearchParams(window.location.search);
  const stockWs = stockParams.get("workspace") || stockParams.get("view");
  if (stockWs === "items" || stockParams.get("filter") === "service") {
    openWorkspace(itemsWorkspace, () => {
      loadItems(itemsSearch?.value.trim() || "");
      if (stockParams.get("filter") === "service") {
        document.querySelector("[data-filter-type='service']")?.click();
      }
    });
  }
};

const setupSalesPage = () => {
  const page = document.querySelector("[data-sales-page]");
  if (!page) return;

  // ---- Local helpers ----
  const formatCurrency = (amount, cur = "TZS") => {
    const val = Number(amount) || 0;
    return `${cur} ${val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };
  const formatAmount = (amount) => {
    const val = Number(amount) || 0;
    return val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  };
  const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    try {
      const d = new Date(String(dateStr).replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch {
      return dateStr;
    }
  };
  const num = (v) => parseFloat(String(v ?? "").replace(/[^0-9.\-]/g, "")) || 0;
  const setSSValue = (wrapper, value) => {
    const input = wrapper?.querySelector("[data-search-select-value]");
    if (!input) return;
    input.value = value;
    input.setAttribute("value", value);
    input.dispatchEvent(new Event("change", { bubbles: true }));
  };
  const statusClass = (eff) => {
    if (eff === "paid") return "approved";
    if (eff === "overdue" || eff === "cancelled") return "rejected";
    return "pending";
  };

  // ---- Workspaces ----
  const posWorkspace = document.querySelector("[data-pos-workspace]");
  const invoicesWorkspace = document.querySelector("[data-invoices-workspace]");
  const reportsWorkspace = document.querySelector("[data-reports-workspace]");
  const openWorkspace = (el) => { if (el) el.hidden = false; };
  const closeWorkspace = (el) => { if (el) el.hidden = true; };

  // POS full-screen (hide the header/back bar while immersive).
  const enterPosFullscreen = () => {
    const el = document.documentElement;
    try { if (el.requestFullscreen) el.requestFullscreen().catch(() => {}); else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen(); } catch { /* unsupported */ }
  };
  const exitFullscreen = () => {
    try {
      if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
      else if (document.webkitFullscreenElement && document.webkitExitFullscreen) document.webkitExitFullscreen();
    } catch { /* ignore */ }
  };
  const syncPosFs = () => {
    const fs = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (fs && !posWorkspace?.hidden) {
      posWorkspace?.classList.add("pos-fs");
    }
  };
  document.addEventListener("fullscreenchange", syncPosFs);
  document.addEventListener("webkitfullscreenchange", syncPosFs);
  const openPos = () => {
    openWorkspace(posWorkspace);
    posWorkspace?.classList.add("pos-fs");
    enterPosFullscreen();
    refreshPosCatalogue();
    loadPosTickets();
    refreshShift();
  };
  const closePos = () => {
    exitFullscreen();
    posWorkspace?.classList.remove("pos-fs");
    closeWorkspace(posWorkspace);
    if (new URLSearchParams(window.location.search).get("workspace") === "pos") {
      window.location.href = `${getBasePath()}dashboard`;
    }
  };

  document.querySelector("[data-pos-workspace-close]")?.addEventListener("click", closePos);
  document.querySelector("[data-pos-fs-exit]")?.addEventListener("click", closePos);
  posWorkspace?.addEventListener("click", () => {
    if (!posWorkspace.hidden && posWorkspace.classList.contains("pos-fs")) {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        enterPosFullscreen();
      }
    }
  }, { passive: true });
  document.querySelectorAll("[data-open-invoices-workspace]").forEach((btn) => btn.addEventListener("click", () => { openWorkspace(invoicesWorkspace); loadInvoices(invoicesSearch?.value.trim() || ""); }));
  document.querySelector("[data-invoices-workspace-close]")?.addEventListener("click", () => closeWorkspace(invoicesWorkspace));
  document.querySelectorAll("[data-open-reports-workspace]").forEach((btn) => btn.addEventListener("click", () => openWorkspace(reportsWorkspace)));
  document.querySelector("[data-reports-workspace-close]")?.addEventListener("click", () => closeWorkspace(reportsWorkspace));

  document.querySelectorAll("[data-report-range-filters] .filter-pill").forEach((btn) => {
    btn.addEventListener("click", () => {
      const group = btn.closest(".filter-bar");
      group?.querySelectorAll(".filter-pill").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  // ---- KPI + list refs ----
  const kpiSalesToday = document.querySelector("[data-kpi-sales-today]");
  const kpiSalesCount = document.querySelector("[data-kpi-sales-count]");
  const kpiInvoicesDue = document.querySelector("[data-kpi-invoices-due]");
  const kpiSalesMonth = document.querySelector("[data-kpi-sales-month]");
  const invoicesCountBadge = document.querySelector("[data-invoices-count-badge]");
  const invoicesList = document.querySelector("[data-invoices-list]");
  const invoicesEmpty = document.querySelector("[data-invoices-empty]");
  const invoicesSearch = document.querySelector("[data-invoices-search]");
  const invoiceStatusFilters = document.querySelectorAll("[data-filter-invoice]");
  const openCreateInvoiceBtns = document.querySelectorAll("[data-open-create-invoice]");

  // ---- Invoice form modal refs ----
  const invoiceFormModal = document.querySelector("[data-invoice-form-modal]");
  const invoiceFormCloseBtn = document.querySelector("[data-invoice-form-close]");
  const invoiceFormTitle = document.querySelector("[data-invoice-form-title]");
  const invoiceForm = document.querySelector("[data-invoice-form]");
  const invoiceFormId = document.querySelector("[data-invoice-form-id]");
  const invoiceFormError = document.querySelector("[data-invoice-form-error]");
  const invoiceFormSubmit = document.querySelector("[data-invoice-form-submit]");
  const invoiceCustomerSelect = document.querySelector("[data-invoice-customer-select]");
  const invoiceCustomerValue = document.querySelector("[data-invoice-customer-value]");
  const invoiceLinesContainer = document.querySelector("[data-invoice-lines-container]");
  const addInvoiceLineBtn = document.querySelector("[data-add-invoice-line]");
  const invoiceDiscountInput = document.querySelector("[data-invoice-discount-input]");
  const invoiceVatRow = document.querySelector("[data-invoice-vat-row]");
  const invoiceVatRateLabel = document.querySelector("[data-invoice-vat-rate]");
  const invoiceCalcSubtotal = document.querySelector("[data-invoice-calc-subtotal]");
  const invoiceCalcTax = document.querySelector("[data-invoice-calc-tax]");
  const invoiceCalcTotal = document.querySelector("[data-invoice-calc-total]");

  // ---- Invoice detail modal refs ----
  const invoiceDetailModal = document.querySelector("[data-invoice-detail-modal]");
  const invoiceDetailCloseBtn = document.querySelector("[data-invoice-detail-close]");
  const invoiceDetailNum = document.querySelector("[data-invoice-detail-num]");
  const invoiceDetailCustomer = document.querySelector("[data-invoice-detail-customer]");
  const invoiceDetailStatusBadge = document.querySelector("[data-invoice-detail-status-badge]");
  const invoiceDetailIssue = document.querySelector("[data-invoice-detail-issue]");
  const invoiceDetailDue = document.querySelector("[data-invoice-detail-due]");
  const invoiceDetailPhone = document.querySelector("[data-invoice-detail-phone]");
  const invoiceDetailEmail = document.querySelector("[data-invoice-detail-email]");
  const invoiceDetailItemsList = document.querySelector("[data-invoice-detail-items-list]");
  const invoiceDetailSubtotal = document.querySelector("[data-invoice-detail-subtotal]");
  const invoiceDetailDiscount = document.querySelector("[data-invoice-detail-discount]");
  const invoiceDetailTax = document.querySelector("[data-invoice-detail-tax]");
  const invoiceDetailTotal = document.querySelector("[data-invoice-detail-total]");
  const invoiceDetailNotesWrap = document.querySelector("[data-invoice-detail-notes-wrap]");
  const invoiceDetailNotes = document.querySelector("[data-invoice-detail-notes]");
  const invoiceMarkSentBtn = document.querySelector("[data-invoice-mark-sent-btn]");
  const invoiceMarkPaidBtn = document.querySelector("[data-invoice-mark-paid-btn]");
  const invoiceEditBtn = document.querySelector("[data-invoice-edit-btn]");
  const invoiceDownloadPdfBtn = document.querySelector("[data-invoice-download-pdf-btn]");
  const invoiceCancelBtn = document.querySelector("[data-invoice-cancel-btn]");
  const invoiceUncancelBtn = document.querySelector("[data-invoice-uncancel-btn]");
  const invoiceDeleteBtn = document.querySelector("[data-invoice-delete-btn]");

  // ---- State ----
  let cachedProducts = [];
  let invVatConfig = { enabled: false, rate: 0 };
  let cachedCustomers = [];
  let activeInvoiceStatus = "all";
  let currentInvoice = null;

  // ---- Data loads ----
  const loadProducts = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/items.php`);
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.ok) {
        cachedProducts = Array.isArray(data.items) ? data.items : [];
        if (data.vat) invVatConfig = { enabled: !!data.vat.enabled, rate: Number(data.vat.rate) || 0 };
      }
    } catch { /* offline */ }
  };

  const loadCustomers = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/customers.php`);
      if (!res.ok) return;
      const data = await res.json();
      cachedCustomers = Array.isArray(data.customers) ? data.customers : [];
      if (invoiceCustomerSelect) {
        const options = [{ value: "", label: "Walk-in Customer" }].concat(
          cachedCustomers.map((c) => ({ value: String(c.id), label: `${c.full_name}${c.phone ? ` (${c.phone})` : ""}` }))
        );
        setSearchSelectOptions(invoiceCustomerSelect, options, "Walk-in Customer", invoiceCustomerValue?.value || "");
      }
    } catch { /* offline */ }
  };

  const applyInvoiceStats = (stats = {}) => {
    if (kpiSalesToday) kpiSalesToday.textContent = formatCurrency(stats.sales_today || 0);
    if (kpiSalesCount) kpiSalesCount.textContent = String(stats.txn_today || 0);
    if (kpiInvoicesDue) kpiInvoicesDue.textContent = String(stats.overdue || 0);
    if (kpiSalesMonth) kpiSalesMonth.textContent = formatCurrency(stats.sales_month || 0);
    if (invoicesCountBadge) invoicesCountBadge.textContent = `${stats.total || 0} invoices`;
  };

  const renderInvoicesList = (invoices = []) => {
    if (!invoices.length) {
      invoicesList?.replaceChildren();
      if (invoicesEmpty) invoicesEmpty.hidden = false;
      return;
    }
    if (invoicesEmpty) invoicesEmpty.hidden = true;

    invoicesList?.replaceChildren(...invoices.map((inv) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "settings-list-row";
      row.style.cssText = "display:flex;align-items:center;justify-content:space-between;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;";

      const left = document.createElement("div");
      left.style.cssText = "display:flex;align-items:center;gap:10px;text-align:left;min-width:0;";
      const avatar = document.createElement("div");
      avatar.className = "customer-avatar";
      avatar.style.cssText = "width:38px;height:38px;font-size:1.1rem;";
      avatar.innerHTML = svgMarkup("receipt");
      const info = document.createElement("div");
      info.style.minWidth = "0";
      const name = document.createElement("div");
      name.style.cssText = "font-weight:800;color:var(--color-navy);font-size:0.94rem;";
      name.textContent = inv.invoice_number;
      const sub = document.createElement("div");
      sub.style.cssText = "font-size:0.78rem;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;";
      sub.textContent = `${inv.customer_name || "Walk-in"} • ${formatDate(inv.issue_date)} • ${inv.items_count || 0} items`;
      info.append(name, sub);
      left.append(avatar, info);

      const right = document.createElement("div");
      right.style.cssText = "display:flex;align-items:center;gap:8px;";
      const amtBox = document.createElement("div");
      amtBox.style.textAlign = "right";
      const amt = document.createElement("strong");
      amt.style.cssText = "color:var(--color-navy);font-size:0.92rem;";
      amt.textContent = formatCurrency(inv.total_amount);
      const badge = document.createElement("span");
      badge.className = `badge-status ${statusClass(inv.effective_status)}`;
      badge.style.cssText = "display:block;margin-top:2px;";
      badge.textContent = (inv.effective_status || "draft").toUpperCase();
      amtBox.append(amt, badge);
      right.append(amtBox);

      row.append(left, right);
      row.addEventListener("click", () => loadSingleInvoiceAndOpen(inv.id));
      return row;
    }));
  };

  const loadInvoices = async (query = "") => {
    try {
      const params = new URLSearchParams();
      if (activeInvoiceStatus !== "all") params.set("status", activeInvoiceStatus);
      if (query) params.set("q", query);
      const res = await fetch(`${getBasePath()}api/sales.php?${params.toString()}`);
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.ok) return;
      renderInvoicesList(Array.isArray(data.invoices) ? data.invoices : []);
      applyInvoiceStats(data.stats || {});
    } catch { /* offline */ }
  };

  // ---- Invoice form: line items ----
  const calculateInvoiceTotals = () => {
    let subtotal = 0; // net subtotal
    let taxableNet = 0;
    const taxRate = invVatConfig.enabled ? invVatConfig.rate : 0;
    invoiceLinesContainer?.querySelectorAll(".po-line-item-row").forEach((row) => {
      const qty = num(row.querySelector('input[name="line_qty"]')?.value);
      const price = num(row.querySelector('input[name="line_price"]')?.value);
      const selectValue = row.querySelector('[name="line_item_id"]');
      const gross = qty * price;
      let taxable = false;
      let inclusive = false;
      if (invVatConfig.enabled) {
        const chosen = cachedProducts.find((p) => String(p.id) === String(selectValue?.value || ""));
        // Known items follow their flags; free-text lines default to taxable, exclusive.
        taxable = !chosen || Number(chosen.vat_applicable ?? 1) === 1;
        inclusive = !!chosen && Number(chosen.tax_inclusive ?? 0) === 1;
      }
      const net = (taxable && inclusive && taxRate > 0) ? gross / (1 + taxRate / 100) : gross;
      subtotal += net;
      if (taxable) taxableNet += net;
      const totSpan = row.querySelector("[data-line-total]");
      if (totSpan) totSpan.textContent = formatCurrency(gross);
    });
    const discount = Math.min(Math.max(0, num(invoiceDiscountInput?.value)), subtotal);
    const discountRatio = subtotal > 0 ? discount / subtotal : 0;
    const taxAmt = (taxableNet * (1 - discountRatio) * taxRate) / 100;
    const total = (subtotal - discount) + taxAmt;
    if (invoiceVatRow) invoiceVatRow.hidden = !invVatConfig.enabled;
    if (invoiceVatRateLabel) invoiceVatRateLabel.textContent = String(taxRate);
    if (invoiceCalcSubtotal) invoiceCalcSubtotal.textContent = formatCurrency(subtotal);
    if (invoiceCalcTax) invoiceCalcTax.textContent = formatCurrency(taxAmt);
    if (invoiceCalcTotal) invoiceCalcTotal.textContent = formatCurrency(total);
  };
  invoiceDiscountInput?.addEventListener("input", calculateInvoiceTotals);

  const addInvoiceLineItem = (preset = null) => {
    if (!invoiceLinesContainer) return;
    const row = document.createElement("div");
    row.className = "po-line-item-row";

    const select = createSearchSelectElement({
      name: "line_item_id",
      placeholder: "Select product...",
      required: true,
      options: cachedProducts.map((p) => ({ value: String(p.id), label: p.name })),
    });
    const selectValue = select.querySelector("[data-search-select-value]");

    const qtyInput = document.createElement("input");
    qtyInput.type = "text";
    qtyInput.inputMode = "numeric";
    qtyInput.name = "line_qty";
    qtyInput.value = preset ? String(preset.quantity) : "1";
    qtyInput.placeholder = "Qty";
    qtyInput.style.textAlign = "right";

    const priceInput = document.createElement("input");
    priceInput.type = "text";
    priceInput.inputMode = "numeric";
    priceInput.name = "line_price";
    priceInput.value = preset ? String(preset.unit_price) : "0";
    priceInput.placeholder = "Price";
    priceInput.style.textAlign = "right";

    selectValue?.addEventListener("change", () => {
      const chosen = cachedProducts.find((p) => String(p.id) === String(selectValue.value));
      if (chosen) priceInput.value = chosen.selling_price || 0;
      calculateInvoiceTotals();
    });
    qtyInput.addEventListener("input", calculateInvoiceTotals);
    priceInput.addEventListener("input", calculateInvoiceTotals);

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "icon-button";
    removeBtn.style.cssText = "padding:4px;color:var(--color-danger);";
    removeBtn.innerHTML = `<svg viewBox="0 0 512 512" aria-hidden="true" style="width:14px;height:14px;stroke:currentColor;stroke-width:48;"><path d="M112 112l288 288M400 112L112 400" /></svg>`;
    removeBtn.addEventListener("click", () => { row.remove(); calculateInvoiceTotals(); });

    row.append(select, qtyInput, priceInput, removeBtn);
    invoiceLinesContainer.append(row);
    if (preset && preset.item_id) setSSValue(select, String(preset.item_id));
    calculateInvoiceTotals();
  };
  addInvoiceLineBtn?.addEventListener("click", () => addInvoiceLineItem());

  // ---- Invoice form: open/close/submit ----
  const openCreateInvoiceModal = () => {
    if (invoiceFormError) invoiceFormError.hidden = true;
    if (invoiceFormTitle) invoiceFormTitle.textContent = "New Invoice";
    if (invoiceFormId) invoiceFormId.value = "";
    invoiceForm?.reset();
    if (invoiceForm) invoiceForm.elements["issue_date"].value = new Date().toISOString().split("T")[0];
    setSSValue(invoiceCustomerSelect, "");
    if (invoiceLinesContainer) invoiceLinesContainer.replaceChildren();
    addInvoiceLineItem();
    calculateInvoiceTotals();
    if (invoiceFormModal) invoiceFormModal.hidden = false;
  };

  const openEditInvoiceModal = (inv) => {
    if (!inv) return;
    if (invoiceFormError) invoiceFormError.hidden = true;
    if (invoiceFormTitle) invoiceFormTitle.textContent = `Edit ${inv.invoice_number}`;
    if (invoiceFormId) invoiceFormId.value = String(inv.id);
    invoiceForm?.reset();
    if (invoiceForm) {
      invoiceForm.elements["issue_date"].value = inv.issue_date || new Date().toISOString().split("T")[0];
      invoiceForm.elements["due_date"].value = inv.due_date || "";
      invoiceForm.elements["discount"].value = inv.discount || 0;
      invoiceForm.elements["notes"].value = inv.notes || "";
    }
    setSSValue(invoiceCustomerSelect, inv.customer_id ? String(inv.customer_id) : "");
    if (invoiceLinesContainer) invoiceLinesContainer.replaceChildren();
    (inv.items || []).forEach((it) => addInvoiceLineItem(it));
    if (!(inv.items || []).length) addInvoiceLineItem();
    calculateInvoiceTotals();
    if (invoiceDetailModal) invoiceDetailModal.hidden = true;
    if (invoiceFormModal) invoiceFormModal.hidden = false;
  };

  const closeInvoiceFormModal = () => { if (invoiceFormModal) invoiceFormModal.hidden = true; };
  openCreateInvoiceBtns.forEach((btn) => btn.addEventListener("click", openCreateInvoiceModal));
  invoiceFormCloseBtn?.addEventListener("click", closeInvoiceFormModal);

  invoiceForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (invoiceFormError) invoiceFormError.hidden = true;
    if (invoiceFormSubmit) invoiceFormSubmit.disabled = true;
    try {
      const isEditing = Boolean(invoiceFormId?.value);
      const items = [];
      invoiceLinesContainer?.querySelectorAll(".po-line-item-row").forEach((row) => {
        const itemId = parseInt(row.querySelector('[name="line_item_id"]')?.value || "0", 10);
        const qty = num(row.querySelector('input[name="line_qty"]')?.value);
        const price = num(row.querySelector('input[name="line_price"]')?.value);
        if (itemId > 0 && qty > 0) items.push({ item_id: itemId, quantity: qty, unit_price: price });
      });
      if (!items.length) throw new Error("Please add at least one product with a quantity.");

      const formData = new FormData(invoiceForm);
      formData.set("action", isEditing ? "update_invoice" : "create_invoice");
      formData.set("items", JSON.stringify(items));

      const res = await fetch(`${getBasePath()}api/sales.php`, { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Failed to save invoice.");

      closeInvoiceFormModal();
      await loadInvoices(invoicesSearch?.value.trim() || "");
      if (data.invoice_id) await loadSingleInvoiceAndOpen(data.invoice_id);
      await showAppModal("Invoice Saved", data.message || "Invoice saved successfully.");
    } catch (err) {
      if (invoiceFormError) {
        invoiceFormError.textContent = err.message || "Failed to save invoice.";
        invoiceFormError.hidden = false;
      }
    } finally {
      if (invoiceFormSubmit) invoiceFormSubmit.disabled = false;
    }
  });

  // ---- Invoice detail ----
  const renderInvoiceDetail = (inv) => {
    currentInvoice = inv;
    const eff = inv.effective_status || inv.status;
    if (invoiceDetailNum) invoiceDetailNum.textContent = inv.invoice_number;
    if (invoiceDetailCustomer) invoiceDetailCustomer.textContent = inv.customer_name || "Walk-in Customer";
    if (invoiceDetailStatusBadge) {
      invoiceDetailStatusBadge.className = `badge-status ${statusClass(eff)}`;
      invoiceDetailStatusBadge.textContent = (eff || "draft").toUpperCase();
    }
    if (invoiceDetailIssue) invoiceDetailIssue.textContent = formatDate(inv.issue_date);
    if (invoiceDetailDue) invoiceDetailDue.textContent = inv.due_date ? formatDate(inv.due_date) : "-";
    if (invoiceDetailPhone) invoiceDetailPhone.textContent = inv.customer?.phone || "-";
    if (invoiceDetailEmail) invoiceDetailEmail.textContent = inv.customer?.email || "-";
    if (invoiceDetailSubtotal) invoiceDetailSubtotal.textContent = formatCurrency(inv.subtotal);
    if (invoiceDetailDiscount) invoiceDetailDiscount.textContent = formatCurrency(inv.discount);
    if (invoiceDetailTax) invoiceDetailTax.textContent = `${formatCurrency(inv.tax_amount)} (${inv.tax_rate || 0}%)`;
    if (invoiceDetailTotal) invoiceDetailTotal.textContent = formatCurrency(inv.total_amount);

    if (invoiceDetailItemsList) {
      invoiceDetailItemsList.innerHTML = (inv.items || []).map((it, idx) => `
        <div style="padding:10px 14px;border-bottom:1px solid var(--color-line);display:flex;justify-content:space-between;align-items:center;">
          <div><strong style="color:var(--color-navy);font-size:0.9rem;">${idx + 1}. ${it.item_name}</strong>
            <div style="font-size:0.78rem;color:var(--color-muted);margin-top:2px;">${it.quantity} @ ${formatCurrency(it.unit_price)}</div></div>
          <strong style="font-size:0.9rem;color:var(--color-navy);">${formatCurrency(it.line_total)}</strong>
        </div>`).join("");
    }

    if (invoiceDetailNotesWrap && invoiceDetailNotes) {
      if (inv.notes) { invoiceDetailNotes.textContent = inv.notes; invoiceDetailNotesWrap.hidden = false; }
      else { invoiceDetailNotesWrap.hidden = true; }
    }

    const isCancelled = inv.status === "cancelled";
    const isPaid = inv.status === "paid";
    if (invoiceMarkSentBtn) invoiceMarkSentBtn.hidden = isCancelled || isPaid || inv.status === "sent";
    if (invoiceMarkPaidBtn) invoiceMarkPaidBtn.hidden = isCancelled || isPaid;
    if (invoiceEditBtn) invoiceEditBtn.hidden = isCancelled || isPaid;
    if (invoiceCancelBtn) invoiceCancelBtn.hidden = isCancelled || isPaid;
    if (invoiceUncancelBtn) invoiceUncancelBtn.hidden = !isCancelled;

    if (invoiceDetailModal) invoiceDetailModal.hidden = false;
  };

  const loadSingleInvoiceAndOpen = async (id) => {
    try {
      const res = await fetch(`${getBasePath()}api/sales.php?id=${encodeURIComponent(id)}`);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Failed to load invoice.");
      renderInvoiceDetail(data.invoice);
    } catch (err) {
      await showAppModal("Error", err.message || "Could not load invoice.");
    }
  };

  invoiceDetailCloseBtn?.addEventListener("click", () => { if (invoiceDetailModal) invoiceDetailModal.hidden = true; currentInvoice = null; });
  invoiceEditBtn?.addEventListener("click", () => openEditInvoiceModal(currentInvoice));
  invoiceDownloadPdfBtn?.addEventListener("click", () => {
    if (!currentInvoice) return;
    window.open(`${getBasePath()}api/sales.php?action=pdf&id=${currentInvoice.id}`, "_blank");
  });

  const postInvoiceAction = async (body, successTitle, successMsg, reopen = true) => {
    try {
      const formData = new FormData();
      Object.entries(body).forEach(([k, v]) => formData.set(k, String(v)));
      const res = await fetch(`${getBasePath()}api/sales.php`, { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Action failed.");
      await loadInvoices(invoicesSearch?.value.trim() || "");
      if (reopen && currentInvoice) await loadSingleInvoiceAndOpen(currentInvoice.id);
      await showAppModal(successTitle, successMsg || data.message);
    } catch (err) {
      await showAppModal("Error", err.message || "Action failed.");
    }
  };

  invoiceMarkSentBtn?.addEventListener("click", () => {
    if (!currentInvoice) return;
    postInvoiceAction({ action: "update_status", invoice_id: currentInvoice.id, status: "sent" }, "Invoice Sent", "Invoice marked as sent.");
  });
  invoiceMarkPaidBtn?.addEventListener("click", async () => {
    if (!currentInvoice) return;
    const ok = await showConfirmModal({ title: "Mark as Paid", message: `Mark ${currentInvoice.invoice_number} as fully paid?`, confirmLabel: "Mark Paid", cancelLabel: "Cancel" });
    if (!ok) return;
    postInvoiceAction({ action: "update_status", invoice_id: currentInvoice.id, status: "paid" }, "Invoice Paid", "Invoice marked as paid.");
  });
  invoiceCancelBtn?.addEventListener("click", async () => {
    if (!currentInvoice) return;
    const ok = await showConfirmModal({ title: "Cancel Invoice", message: `Cancel invoice ${currentInvoice.invoice_number}?`, confirmLabel: "Cancel Invoice", cancelLabel: "Keep", danger: true });
    if (!ok) return;
    postInvoiceAction({ action: "cancel_invoice", invoice_id: currentInvoice.id }, "Invoice Cancelled", "Invoice has been cancelled.");
  });
  invoiceUncancelBtn?.addEventListener("click", async () => {
    if (!currentInvoice) return;
    const ok = await showConfirmModal({ title: "Reactivate Invoice", message: `Reactivate ${currentInvoice.invoice_number}? It returns to Draft.`, confirmLabel: "Reactivate", cancelLabel: "Keep Cancelled" });
    if (!ok) return;
    postInvoiceAction({ action: "uncancel_invoice", invoice_id: currentInvoice.id }, "Invoice Reactivated", "Invoice is back to Draft.");
  });
  invoiceDeleteBtn?.addEventListener("click", async () => {
    if (!currentInvoice) return;
    const ok = await showConfirmModal({ title: "Delete Invoice", message: `Permanently delete ${currentInvoice.invoice_number}? This cannot be undone.`, confirmLabel: "Delete", cancelLabel: "Cancel", danger: true });
    if (!ok) return;
    const id = currentInvoice.id;
    try {
      const formData = new FormData();
      formData.set("action", "delete_invoice");
      formData.set("invoice_id", String(id));
      const res = await fetch(`${getBasePath()}api/sales.php`, { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Failed to delete invoice.");
      if (invoiceDetailModal) invoiceDetailModal.hidden = true;
      currentInvoice = null;
      await loadInvoices(invoicesSearch?.value.trim() || "");
      await showAppModal("Invoice Deleted", "The invoice was removed.");
    } catch (err) {
      await showAppModal("Error", err.message || "Could not delete invoice.");
    }
  });

  // ---- Filters & search ----
  invoiceStatusFilters.forEach((btn) => {
    btn.addEventListener("click", () => {
      invoiceStatusFilters.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      activeInvoiceStatus = btn.dataset.filterInvoice || "all";
      loadInvoices(invoicesSearch?.value.trim() || "");
    });
  });
  let invoiceSearchTimeout;
  invoicesSearch?.addEventListener("input", (e) => {
    clearTimeout(invoiceSearchTimeout);
    invoiceSearchTimeout = setTimeout(() => loadInvoices(e.target.value.trim()), 250);
  });

  // =========================================================
  //  Point of Sale
  // =========================================================
  const posCustomerNameEl = document.querySelector("[data-pos-customer-name]");
  const posChangeCustomerBtn = document.querySelector("[data-pos-change-customer]");
  const posSearch = document.querySelector("[data-pos-search]");
  const posCartWrap = document.querySelector("[data-pos-cart-wrap]");
  const posCartBody = document.querySelector("[data-pos-cart-body]");
  const posCartEmpty = document.querySelector("[data-pos-cart-empty]");
  const posCategoryFilters = document.querySelector("[data-pos-category-filters]");
  const posProductList = document.querySelector("[data-pos-product-list]");
  const posProductEmpty = document.querySelector("[data-pos-product-empty]");
  const posTotalEl = document.querySelector("[data-pos-total]");
  const posClearBtn = document.querySelector("[data-pos-clear]");
  const posCheckoutBtn = document.querySelector("[data-pos-checkout]");

  // Customer modal
  const posCustomerModal = document.querySelector("[data-pos-customer-modal]");
  const posCustomerSelect = document.querySelector("[data-pos-customer-select]");
  const posCustomerSelectValue = posCustomerSelect?.querySelector("[data-search-select-value]");
  const posCustomerConfirmBtn = document.querySelector("[data-pos-customer-confirm]");
  const posCustomerCancelBtn = document.querySelector("[data-pos-customer-cancel]");

  // Checkout modal
  const posCheckoutModal = document.querySelector("[data-pos-checkout-modal]");
  const posCheckoutCloseBtn = document.querySelector("[data-pos-checkout-close]");
  const posCheckoutCustomer = document.querySelector("[data-pos-checkout-customer]");
  const posCheckoutSubtotal = document.querySelector("[data-pos-checkout-subtotal]");
  const posCheckoutVatRow = document.querySelector("[data-pos-checkout-vat-row]");
  const posCheckoutVatRate = document.querySelector("[data-pos-checkout-vat-rate]");
  const posCheckoutVat = document.querySelector("[data-pos-checkout-vat]");
  const posCheckoutTotal = document.querySelector("[data-pos-checkout-total]");
  const posPaymentMethods = document.querySelector("[data-pos-payment-methods]");
  const posAmountPaid = document.querySelector("[data-pos-amount-paid]");
  const posChangeDue = document.querySelector("[data-pos-change-due]");
  const posCheckoutError = document.querySelector("[data-pos-checkout-error]");
  const posCompleteSaleBtn = document.querySelector("[data-pos-complete-sale]");

  // Receipt modal
  const posReceiptModal = document.querySelector("[data-pos-receipt-modal]");
  const posReceiptNumber = document.querySelector("[data-pos-receipt-number]");
  const posReceiptTotal = document.querySelector("[data-pos-receipt-total]");
  const posReceiptPaid = document.querySelector("[data-pos-receipt-paid]");
  const posReceiptChange = document.querySelector("[data-pos-receipt-change]");
  const posReceiptPrintBtn = document.querySelector("[data-pos-receipt-print]");
  const posNewSaleBtn = document.querySelector("[data-pos-new-sale]");

  let posCart = [];
  let posCustomer = { id: 0, name: "Walk-in Customer" };
  let posCategory = "all";
  let posPayment = "cash";
  let lastSale = null;

  const posTotals = () => {
    let subtotal = 0; // net subtotal
    let taxableNet = 0;
    const rate = invVatConfig.enabled ? invVatConfig.rate : 0;
    posCart.forEach((l) => {
      const gross = l.price * l.qty;
      const taxable = invVatConfig.enabled && Number(l.vat_applicable) === 1;
      const net = (taxable && Number(l.tax_inclusive) === 1 && rate > 0) ? gross / (1 + rate / 100) : gross;
      subtotal += net;
      if (taxable) taxableNet += net;
    });
    const tax = (taxableNet * rate) / 100;
    return { subtotal, tax, rate, total: subtotal + tax };
  };

  const buildPosCartRow = (line) => {
    const tr = document.createElement("tr");

    const nameTd = document.createElement("td");
    nameTd.innerHTML = `<div class="pos-cart-item-name">${line.name}</div>`;

    const priceTd = document.createElement("td");
    priceTd.style.textAlign = "right";
    priceTd.textContent = formatAmount(line.price);

    const qtyTd = document.createElement("td");
    qtyTd.style.textAlign = "center";
    const qtyVal = document.createElement("span");
    qtyVal.className = "pos-qty-value";
    qtyVal.textContent = String(line.qty);
    qtyTd.append(qtyVal);

    const actTd = document.createElement("td");
    actTd.style.textAlign = "center";
    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "pos-cart-remove";
    removeBtn.setAttribute("aria-label", "Remove");
    removeBtn.innerHTML = `<svg viewBox="0 0 512 512"><path d="M112 112l20 320c.95 18.49 14.4 32 32 32h184c17.67 0 30.87-13.51 32-32l20-320"/><path d="M80 112h352M192 112V72h128v40M256 176v224M184 176l8 224M328 176l-8 224"/></svg>`;
    removeBtn.addEventListener("click", () => removeFromPosCart(line.id));
    actTd.append(removeBtn);

    tr.append(nameTd, priceTd, qtyTd, actTd);
    return tr;
  };

  const renderPosCart = () => {
    const has = posCart.length > 0;
    if (posCartWrap) posCartWrap.hidden = !has;
    if (posCartEmpty) posCartEmpty.hidden = has;
    if (posCartBody) posCartBody.replaceChildren(...posCart.map(buildPosCartRow));
    const { total } = posTotals();
    if (posTotalEl) posTotalEl.textContent = formatCurrency(total);
    if (posCheckoutBtn) posCheckoutBtn.disabled = !has || !currentShift;
    if (posClearBtn) posClearBtn.disabled = !has;
    if (typeof persistActiveTicket === "function") persistActiveTicket();
  };

  const addToPosCart = (product) => {
    const stock = Number(product.current_stock) || 0;
    const isProduct = product.type !== "service";
    const existing = posCart.find((l) => l.id === product.id);
    if (existing) {
      changePosQty(product.id, existing.qty + 1);
      return;
    }
    if (isProduct && stock <= 0) {
      showAppModal("Out of stock", `"${product.name}" has no stock available.`);
      return;
    }
    posCart.push({
      id: product.id,
      name: product.name,
      unit: product.unit || "pcs",
      price: Number(product.selling_price) || 0,
      qty: 1,
      vat_applicable: Number(product.vat_applicable ?? 1),
      tax_inclusive: Number(product.tax_inclusive ?? 0),
      type: product.type,
      stock,
    });
    renderPosCart();
  };

  const changePosQty = (id, newQty) => {
    const line = posCart.find((l) => l.id === id);
    if (!line) return;
    if (newQty <= 0) {
      removeFromPosCart(id);
      return;
    }
    if (line.type !== "service" && newQty > line.stock) {
      showAppModal("Not enough stock", `Only ${line.stock} ${line.unit} of "${line.name}" available.`);
      newQty = line.stock;
    }
    line.qty = newQty;
    renderPosCart();
  };

  const removeFromPosCart = (id) => {
    posCart = posCart.filter((l) => l.id !== id);
    renderPosCart();
  };

  const clearPosCart = () => {
    posCart = [];
    renderPosCart();
  };

  // ===================== Held sales (tickets) + Shift + Sales history =====================
  const escH = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const posTicketsBar = document.querySelector("[data-pos-tickets]");
  const posShiftStrip = document.querySelector("[data-pos-shift-strip]");
  const POS_TICKETS_KEY = () => `zipoo.pos.tickets.${getStoredBusinessState().selectedBusiness?.id || 0}`;
  let posTickets = [];
  let activeTicketId = null;
  let posTicketSeq = 0;
  let currentShift = null;
  let shiftAccounts = [];

  const activeTicket = () => posTickets.find((t) => t.id === activeTicketId) || null;
  const savePosTickets = () => {
    try { localStorage.setItem(POS_TICKETS_KEY(), JSON.stringify({ tickets: posTickets, activeId: activeTicketId, seq: posTicketSeq })); } catch { /* ignore */ }
  };
  const renderTicketsBar = () => {
    if (!posTicketsBar) return;
    const chips = posTickets.map((t) => {
      const count = (t.cart || []).reduce((s, l) => s + (Number(l.qty) || 0), 0);
      const active = t.id === activeTicketId;
      return `<button type="button" class="pos-ticket${active ? " active" : ""}" data-ticket="${t.id}">
        <span class="pos-ticket-label">${escH(t.label)}</span>
        <span class="pos-ticket-meta">${count} item${count !== 1 ? "s" : ""}</span>
        <span class="pos-ticket-x" data-ticket-del="${t.id}" aria-label="Remove">${svgMarkup("close", { size: 11 })}</span>
      </button>`;
    }).join("");
    posTicketsBar.innerHTML = chips + `<button type="button" class="pos-ticket pos-ticket-new" data-ticket-new>+ New sale</button>`;
  };
  const persistActiveTicket = () => {
    const t = activeTicket();
    if (t) { t.cart = posCart; t.customer = { ...posCustomer }; t.payment = posPayment; }
    savePosTickets();
    renderTicketsBar();
  };
  const loadTicketIntoState = (t) => {
    posCart = Array.isArray(t.cart) ? t.cart : [];
    posCustomer = (t.customer && t.customer.id) ? { ...t.customer } : { id: 0, name: "Walk-in Customer" };
    posPayment = t.payment || "cash";
    if (posCustomerNameEl) posCustomerNameEl.textContent = posCustomer.name;
    posPaymentMethods?.querySelectorAll("[data-pos-payment]").forEach((b) => b.classList.toggle("active", (b.dataset.posPayment || "cash") === posPayment));
    renderPosCart();
  };
  const newPosTicket = (makeActive = true) => {
    posTicketSeq += 1;
    const t = { id: `t${Date.now()}${Math.floor(Math.random() * 1000)}`, label: `Sale ${posTicketSeq}`, cart: [], customer: { id: 0, name: "Walk-in Customer" }, payment: "cash", createdAt: Date.now() };
    posTickets.push(t);
    if (makeActive) { activeTicketId = t.id; loadTicketIntoState(t); }
    savePosTickets();
    renderTicketsBar();
    return t;
  };
  const switchPosTicket = (id) => {
    if (id === activeTicketId) return;
    persistActiveTicket();
    const t = posTickets.find((x) => x.id === id);
    if (!t) return;
    activeTicketId = id;
    loadTicketIntoState(t);
    savePosTickets();
    renderTicketsBar();
  };
  const deletePosTicket = async (id) => {
    const t = posTickets.find((x) => x.id === id);
    if (!t) return;
    if ((t.cart || []).length) {
      const ok = await showConfirmModal({ title: "Remove sale", message: `Discard "${t.label}" with ${t.cart.length} item(s)? This held sale will be deleted.`, confirmLabel: "Remove", danger: true });
      if (!ok) return;
    }
    posTickets = posTickets.filter((x) => x.id !== id);
    if (activeTicketId === id) {
      if (!posTickets.length) { newPosTicket(true); return; }
      activeTicketId = posTickets[0].id;
      loadTicketIntoState(posTickets[0]);
    }
    savePosTickets();
    renderTicketsBar();
  };
  const dropActiveTicketAfterSale = () => {
    posTickets = posTickets.filter((x) => x.id !== activeTicketId);
    if (!posTickets.length) { newPosTicket(true); return; }
    activeTicketId = posTickets[0].id;
    loadTicketIntoState(posTickets[0]);
    savePosTickets();
    renderTicketsBar();
  };
  const loadPosTickets = () => {
    try {
      const raw = localStorage.getItem(POS_TICKETS_KEY());
      if (raw) { const d = JSON.parse(raw); posTickets = Array.isArray(d.tickets) ? d.tickets : []; activeTicketId = d.activeId || null; posTicketSeq = Number(d.seq) || posTickets.length; }
    } catch { /* ignore */ }
    if (!posTickets.length) { newPosTicket(true); return; }
    if (!posTickets.find((t) => t.id === activeTicketId)) activeTicketId = posTickets[0].id;
    loadTicketIntoState(activeTicket());
    renderTicketsBar();
  };
  posTicketsBar?.addEventListener("click", (e) => {
    const delId = e.target.closest("[data-ticket-del]")?.dataset.ticketDel;
    if (delId) { deletePosTicket(delId); return; }
    if (e.target.closest("[data-ticket-new]")) { newPosTicket(true); return; }
    const id = e.target.closest("[data-ticket]")?.dataset.ticket;
    if (id) switchPosTicket(id);
  });

  // ---- Shift (till) ----
  const startShiftModal = document.querySelector("[data-start-shift-modal]");
  const startShiftAmount = document.querySelector("[data-start-shift-amount]");
  const startShiftError = document.querySelector("[data-start-shift-error]");
  const closeShiftModal = document.querySelector("[data-close-shift-modal]");
  const closeShiftAmount = document.querySelector("[data-close-shift-amount]");
  const closeShiftAccounts = document.querySelector("[data-close-shift-accounts]");
  const csOpening = document.querySelector("[data-cs-opening]");
  const csCashSales = document.querySelector("[data-cs-cashsales]");
  const csExpected = document.querySelector("[data-cs-expected]");
  const csVariance = document.querySelector("[data-cs-variance]");
  const closeShiftError = document.querySelector("[data-close-shift-error]");
  const shiftStatusBadge = document.querySelector("[data-shift-status-badge]");
  const shiftWsStatus = document.querySelector("[data-shift-ws-status]");
  attachThousandsFormatting(startShiftAmount);
  attachThousandsFormatting(closeShiftAmount);

  const shiftStripHtml = (isPos = false) => {
    if (currentShift) {
      const opened = new Date(String(currentShift.opened_at).replace(" ", "T"));
      const t = isNaN(opened.getTime()) ? "" : opened.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
      const salesInfo = isPos ? "" : ` · cash sales ${formatCurrency(currentShift.cash_sales || 0)}`;
      return `<div class="pos-shift-open">
        <span><strong>Shift open</strong>${t ? " · since " + t : ""}${salesInfo}</span>
        <button type="button" class="btn btn-danger-outline btn-sm" data-close-shift-open>Close Shift</button>
      </div>`;
    }
    return `<div class="pos-shift-closed">
      <span>No open shift — start one to begin.</span>
      <button type="button" class="btn btn-primary btn-sm" data-start-shift-open>Start Shift</button>
    </div>`;
  };
  const renderShiftUI = () => {
    if (posShiftStrip) posShiftStrip.innerHTML = shiftStripHtml(true);
    if (shiftWsStatus) shiftWsStatus.innerHTML = shiftStripHtml(false);
    if (shiftStatusBadge) shiftStatusBadge.textContent = currentShift ? "Open" : "Closed";
    renderPosCart();
  };
  const refreshShift = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/shifts.php?action=current`);
      const d = await res.json();
      if (res.ok && d.ok) { currentShift = d.shift; shiftAccounts = d.accounts || []; }
    } catch { /* offline */ }
    renderShiftUI();
  };
  const openStartShift = () => {
    if (startShiftModal) startShiftModal.hidden = false;
    if (startShiftError) startShiftError.hidden = true;
    if (startShiftAmount) { startShiftAmount.value = ""; startShiftAmount.focus(); }
  };
  const openCloseShift = async () => {
    await refreshShift();
    if (!currentShift) { await showAppModal("Shift", "There is no open shift to close."); return; }
    if (closeShiftError) closeShiftError.hidden = true;
    if (closeShiftAccounts) closeShiftAccounts.innerHTML = shiftAccounts.map((a) => `<div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:3px;"><span style="color:var(--color-muted);">${escH(a.name)}</span><strong>${formatCurrency(a.balance)}</strong></div>`).join("") || `<div style="font-size:0.82rem;color:var(--color-muted);">No accounts.</div>`;
    if (csOpening) csOpening.textContent = formatCurrency(currentShift.opening_balance || 0);
    if (csCashSales) csCashSales.textContent = formatCurrency(currentShift.cash_sales || 0);
    if (csExpected) csExpected.textContent = formatCurrency(currentShift.expected_cash || 0);
    if (closeShiftAmount) closeShiftAmount.value = "";
    if (csVariance) csVariance.textContent = formatCurrency(0 - (currentShift.expected_cash || 0));
    if (closeShiftModal) closeShiftModal.hidden = false;
  };
  closeShiftAmount?.addEventListener("input", () => {
    const counted = num(closeShiftAmount.value);
    const v = counted - (currentShift?.expected_cash || 0);
    if (csVariance) { csVariance.textContent = formatCurrency(v); csVariance.style.color = v === 0 ? "var(--color-navy)" : (v < 0 ? "#dc2626" : "#16a34a"); }
  });
  document.querySelector("[data-start-shift-cancel]")?.addEventListener("click", () => { if (startShiftModal) startShiftModal.hidden = true; });
  document.querySelector("[data-close-shift-cancel]")?.addEventListener("click", () => { if (closeShiftModal) closeShiftModal.hidden = true; });
  document.querySelector("[data-start-shift-confirm]")?.addEventListener("click", async () => {
    if (startShiftError) startShiftError.hidden = true;
    try {
      const b = new FormData(); b.set("action", "open"); b.set("opening_balance", String(num(startShiftAmount?.value)));
      const res = await fetch(`${getBasePath()}api/shifts.php`, { method: "POST", body: b });
      const d = await res.json();
      if (!res.ok || !d.ok) throw new Error(d.message || "Could not start shift.");
      if (startShiftModal) startShiftModal.hidden = true;
      await refreshShift();
      if (typeof loadShiftsList === "function") loadShiftsList();
    } catch (err) { if (startShiftError) { startShiftError.textContent = err.message; startShiftError.hidden = false; } }
  });
  // Shift Summary & Reports Modal
  const shiftSummaryModal = document.querySelector("[data-shift-summary-modal]");
  const shiftSummaryMeta = document.querySelector("[data-shift-summary-meta]");
  const sumTotalSales = document.querySelector("[data-sum-total-sales]");
  const sumCashSales = document.querySelector("[data-sum-cash-sales]");
  const sumExpected = document.querySelector("[data-sum-expected]");
  const sumCounted = document.querySelector("[data-sum-counted]");
  const sumVariance = document.querySelector("[data-sum-variance]");
  const shiftDownloadPdfBtn = document.querySelector("[data-shift-download-pdf]");
  const shiftDownloadExcelBtn = document.querySelector("[data-shift-download-excel]");
  const shiftEmailInput = document.querySelector("[data-shift-email-input]");
  const shiftSendEmailBtn = document.querySelector("[data-shift-send-email]");
  const shiftEmailStatus = document.querySelector("[data-shift-email-status]");
  let activeReportShiftId = 0;

  const openShiftSummaryModal = (s, shiftObj) => {
    activeReportShiftId = s.shift_id || (shiftObj ? shiftObj.id : 0);
    if (shiftSummaryMeta) {
      shiftSummaryMeta.textContent = `Shift #${activeReportShiftId} · Cashier: ${shiftObj?.cashier || "Cashier"}`;
    }
    if (sumTotalSales) sumTotalSales.textContent = formatCurrency(s.sales_total || 0);
    if (sumCashSales) sumCashSales.textContent = formatCurrency(s.cash_sales || 0);
    if (sumExpected) sumExpected.textContent = formatCurrency(s.expected_cash || 0);
    if (sumCounted) sumCounted.textContent = formatCurrency(s.closing_balance || 0);
    if (sumVariance) {
      const v = Number(s.variance) || 0;
      sumVariance.textContent = formatCurrency(v);
      sumVariance.style.color = v === 0 ? "#16a34a" : (v < 0 ? "#dc2626" : "#2563eb");
    }
    if (shiftEmailStatus) { shiftEmailStatus.hidden = true; shiftEmailStatus.textContent = ""; }
    if (shiftSummaryModal) shiftSummaryModal.hidden = false;
  };

  shiftDownloadPdfBtn?.addEventListener("click", () => {
    if (activeReportShiftId) {
      window.open(`${getBasePath()}api/shifts.php?action=export_pdf&id=${activeReportShiftId}`, "_blank");
    }
  });

  shiftDownloadExcelBtn?.addEventListener("click", () => {
    if (activeReportShiftId) {
      window.location.href = `${getBasePath()}api/shifts.php?action=export_excel&id=${activeReportShiftId}`;
    }
  });

  shiftSendEmailBtn?.addEventListener("click", async () => {
    const email = shiftEmailInput?.value?.trim();
    if (!email || !email.includes("@")) {
      if (shiftEmailStatus) {
        shiftEmailStatus.textContent = "Please enter a valid email address.";
        shiftEmailStatus.style.color = "#dc2626";
        shiftEmailStatus.hidden = false;
      }
      return;
    }
    if (shiftEmailStatus) {
      shiftEmailStatus.textContent = "Sending PDF report via email...";
      shiftEmailStatus.style.color = "var(--color-blue)";
      shiftEmailStatus.hidden = false;
    }
    shiftSendEmailBtn.disabled = true;
    try {
      const b = new FormData();
      b.set("action", "email_report");
      b.set("id", String(activeReportShiftId));
      b.set("email", email);
      const res = await fetch(`${getBasePath()}api/shifts.php`, { method: "POST", body: b });
      const d = await res.json();
      if (!res.ok || !d.ok) throw new Error(d.message || "Failed to send email.");
      if (shiftEmailStatus) {
        shiftEmailStatus.textContent = d.message || "Report emailed successfully!";
        shiftEmailStatus.style.color = "#16a34a";
      }
    } catch (err) {
      if (shiftEmailStatus) {
        shiftEmailStatus.textContent = err.message || "Failed to send email.";
        shiftEmailStatus.style.color = "#dc2626";
      }
    } finally {
      shiftSendEmailBtn.disabled = false;
    }
  });

  document.querySelector("[data-shift-summary-done]")?.addEventListener("click", () => {
    if (shiftSummaryModal) shiftSummaryModal.hidden = true;
  });

  document.querySelector("[data-close-shift-confirm]")?.addEventListener("click", async () => {
    if (closeShiftError) closeShiftError.hidden = true;
    try {
      const b = new FormData(); b.set("action", "close"); b.set("closing_balance", String(num(closeShiftAmount?.value)));
      const res = await fetch(`${getBasePath()}api/shifts.php`, { method: "POST", body: b });
      const d = await res.json();
      if (!res.ok || !d.ok) throw new Error(d.message || "Could not close shift.");
      if (closeShiftModal) closeShiftModal.hidden = true;
      const s = d.summary || {};
      openShiftSummaryModal(s, currentShift);
      await refreshShift();
      if (typeof loadShiftsList === "function") loadShiftsList();
    } catch (err) { if (closeShiftError) { closeShiftError.textContent = err.message; closeShiftError.hidden = false; } }
  });
  document.addEventListener("click", (e) => {
    if (e.target.closest("[data-start-shift-open]")) openStartShift();
    else if (e.target.closest("[data-close-shift-open]")) openCloseShift();
  });

  // ---- Shift workspace ----
  const shiftWorkspace = document.querySelector("[data-shift-workspace]");
  const shiftListEl = document.querySelector("[data-shift-list]");
  const shiftEmptyEl = document.querySelector("[data-shift-empty]");
  const loadShiftsList = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/shifts.php?action=list`);
      const d = await res.json();
      if (!res.ok || !d.ok) return;
      const list = d.shifts || [];
      if (!list.length) { shiftListEl?.replaceChildren(); if (shiftEmptyEl) shiftEmptyEl.hidden = false; return; }
      if (shiftEmptyEl) shiftEmptyEl.hidden = true;
      if (shiftListEl) {
        shiftListEl.innerHTML = list.map((s) => {
          const open = s.status === "open";
          const o = new Date(String(s.opened_at).replace(" ", "T"));
          const when = isNaN(o.getTime()) ? s.opened_at : o.toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
          const badge = open ? `<span class="badge-stock in-stock">Open</span>` : `<span class="badge-stock out-of-stock">Closed</span>`;
          const extra = (!open && s.variance != null) ? ` · var ${formatCurrency(s.variance)}` : "";
          const reportActions = !open ? `
            <div style="display:flex;align-items:center;gap:6px;margin-top:6px;">
              <a href="${getBasePath()}api/shifts.php?action=export_pdf&id=${s.id}" target="_blank" class="btn btn-outline btn-sm" style="font-size:0.75rem;padding:3px 8px;" title="Download PDF Report">📄 PDF</a>
              <a href="${getBasePath()}api/shifts.php?action=export_excel&id=${s.id}" class="btn btn-outline btn-sm" style="font-size:0.75rem;padding:3px 8px;" title="Download Excel Report">📊 Excel</a>
              <button type="button" class="btn btn-outline btn-sm" data-shift-row-email="${s.id}" style="font-size:0.75rem;padding:3px 8px;" title="Send PDF via Email">✉️ Email</button>
            </div>
          ` : "";
          return `<div class="settings-list-row" style="display:flex;flex-direction:column;gap:6px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
              <div style="font-weight:800;color:var(--color-navy);font-size:0.88rem;">Shift #${s.id} · ${escH(s.cashier || "Cashier")}</div>
              <div>${badge}</div>
            </div>
            <div style="font-size:0.75rem;color:var(--color-muted);">${when} · open ${formatCurrency(s.opening_balance)}${s.sales_total != null ? " · sales " + formatCurrency(s.sales_total) : ""}${extra}</div>
            ${reportActions}
          </div>`;
        }).join("");
      }
    } catch { /* offline */ }
  };

  shiftListEl?.addEventListener("click", (e) => {
    const emailBtn = e.target.closest("[data-shift-row-email]");
    if (emailBtn) {
      const id = Number(emailBtn.dataset.shiftRowEmail);
      if (id) {
        activeReportShiftId = id;
        if (shiftSummaryMeta) shiftSummaryMeta.textContent = `Shift #${id} Reports`;
        if (shiftEmailStatus) { shiftEmailStatus.hidden = true; shiftEmailStatus.textContent = ""; }
        if (shiftSummaryModal) shiftSummaryModal.hidden = false;
      }
    }
  });
  document.querySelectorAll("[data-open-shift-workspace]").forEach((b) => b.addEventListener("click", () => { openWorkspace(shiftWorkspace); refreshShift(); loadShiftsList(); }));
  document.querySelector("[data-shift-close]")?.addEventListener("click", () => closeWorkspace(shiftWorkspace));

  // ---- Sales history workspace ----
  const salesHistoryWorkspace = document.querySelector("[data-sales-history-workspace]");
  const historyList = document.querySelector("[data-history-list]");
  const historyEmpty = document.querySelector("[data-history-empty]");
  const historyCountEl = document.querySelector("[data-history-count]");
  const historyTotalEl = document.querySelector("[data-history-total]");
  let historyRangeVal = "today";
  const rangeToDates = (r) => {
    const today = new Date();
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    if (r === "all") return { from: "", to: "" };
    if (r === "today") return { from: iso(today), to: iso(today) };
    const days = Number(r) || 7;
    const start = new Date(today); start.setDate(start.getDate() - (days - 1));
    return { from: iso(start), to: iso(today) };
  };
  const loadSalesHistory = async () => {
    const { from, to } = rangeToDates(historyRangeVal);
    try {
      const params = new URLSearchParams({ history: "pos" });
      if (from) params.set("from", from);
      if (to) params.set("to", to);
      const res = await fetch(`${getBasePath()}api/sales.php?${params.toString()}`);
      const d = await res.json();
      if (!res.ok || !d.ok) return;
      const list = d.sales || [];
      if (historyCountEl) historyCountEl.textContent = `${d.stats.count} sale${d.stats.count !== 1 ? "s" : ""}`;
      if (historyTotalEl) historyTotalEl.textContent = formatCurrency(d.stats.total);
      if (!list.length) { historyList?.replaceChildren(); if (historyEmpty) historyEmpty.hidden = false; return; }
      if (historyEmpty) historyEmpty.hidden = true;
      if (historyList) {
        historyList.innerHTML = list.map((s) => {
          const dt = new Date(String(s.created_at).replace(" ", "T"));
          const when = isNaN(dt.getTime()) ? s.created_at : dt.toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
          const cancelled = s.status === "cancelled";
          return `<button type="button" class="settings-list-row" data-history-open="${s.id}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;text-align:left;">
            <div style="min-width:0;">
              <div style="font-weight:800;color:var(--color-navy);font-size:0.9rem;">${escH(s.receipt_number)}${cancelled ? ' <span class="badge-stock out-of-stock">Cancelled</span>' : ""}</div>
              <div style="font-size:0.76rem;color:var(--color-muted);">${when} · ${escH(s.payment_method || "-")}${s.cashier ? " · " + escH(s.cashier) : ""}</div>
            </div>
            <strong style="color:var(--color-navy);white-space:nowrap;">${formatCurrency(s.total_amount)}</strong>
          </button>`;
        }).join("");
      }
    } catch { /* offline */ }
  };
  document.querySelectorAll("[data-open-sales-history-workspace]").forEach((b) => b.addEventListener("click", () => { openWorkspace(salesHistoryWorkspace); loadSalesHistory(); }));
  document.querySelector("[data-sales-history-close]")?.addEventListener("click", () => closeWorkspace(salesHistoryWorkspace));
  document.querySelectorAll("[data-history-range] .filter-pill").forEach((btn) => {
    btn.addEventListener("click", () => {
      document.querySelectorAll("[data-history-range] .filter-pill").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      historyRangeVal = btn.dataset.range || "today";
      loadSalesHistory();
    });
  });
  historyList?.addEventListener("click", (e) => {
    const id = e.target.closest("[data-history-open]")?.dataset.historyOpen;
    if (id) window.open(`${getBasePath()}api/sales.php?action=pdf&id=${id}`, "_blank");
  });

  const renderPosCategories = () => {
    if (!posCategoryFilters) return;
    const cats = Array.from(new Set(cachedProducts.map((p) => p.category || "General"))).sort();
    const all = [{ key: "all", label: "All Category" }].concat(cats.map((c) => ({ key: c, label: c })));
    posCategoryFilters.replaceChildren(...all.map((c) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "filter-pill" + (posCategory === c.key ? " active" : "");
      btn.textContent = c.label;
      btn.addEventListener("click", () => {
        posCategory = c.key;
        renderPosCategories();
        renderPosProducts();
      });
      return btn;
    }));
  };

  const renderPosProducts = () => {
    if (!posProductList) return;
    const q = (posSearch?.value || "").trim().toLowerCase();
    const matches = cachedProducts.filter((p) => {
      if (p.status && p.status !== "active") return false;
      if (posCategory !== "all" && (p.category || "General") !== posCategory) return false;
      if (!q) return true;
      return [p.name, p.sku, p.barcode, p.category].some((f) => String(f || "").toLowerCase().includes(q));
    });

    if (posProductEmpty) posProductEmpty.hidden = matches.length > 0;

    posProductList.replaceChildren(...matches.map((p) => {
      const isProduct = p.type !== "service";
      const stock = Number(p.current_stock) || 0;
      const out = isProduct && stock <= 0;
      const row = document.createElement("button");
      row.type = "button";
      row.className = "pos-product-row" + (out ? " is-out" : "");
      const meta = isProduct ? `${stock} ${p.unit || "pcs"}` : "";
      const metaHtml = meta ? `<span class="pos-product-meta">${meta}${out ? " — Out of stock" : ""}</span>` : "";
      row.innerHTML = `<span><span class="pos-product-name">${p.name}</span>${metaHtml}</span><span class="pos-product-price">${formatCurrency(p.selling_price)}</span>`;
      if (!out) row.addEventListener("click", () => addToPosCart(p));
      return row;
    }));
  };

  const refreshPosCatalogue = async () => {
    await loadProducts();
    renderPosCategories();
    renderPosProducts();
    renderPosCart();
  };

  // Open / close POS
  document.querySelectorAll("[data-open-pos-workspace]").forEach((btn) => {
    btn.addEventListener("click", openPos);
  });

  let posSearchTimer;
  posSearch?.addEventListener("input", () => {
    clearTimeout(posSearchTimer);
    posSearchTimer = setTimeout(renderPosProducts, 150);
  });

  posClearBtn?.addEventListener("click", async () => {
    if (!posCart.length) return;
    const ok = await showConfirmModal({ title: "Clear Cart", message: "Remove all items from this sale?", confirmLabel: "Clear", cancelLabel: "Keep", danger: true });
    if (ok) clearPosCart();
  });

  // Customer selection
  const setPosCustomer = (id, name) => {
    posCustomer = { id: Number(id) || 0, name: name || "Walk-in Customer" };
    if (posCustomerNameEl) posCustomerNameEl.textContent = posCustomer.name;
    if (typeof persistActiveTicket === "function") persistActiveTicket();
  };

  posChangeCustomerBtn?.addEventListener("click", () => {
    const options = [{ value: "", label: "Walk-in Customer" }].concat(
      cachedCustomers.map((c) => ({ value: String(c.id), label: `${c.full_name}${c.phone ? ` (${c.phone})` : ""}` }))
    );
    setSearchSelectOptions(posCustomerSelect, options, null, posCustomer.id ? String(posCustomer.id) : "");
    if (posCustomerModal) posCustomerModal.hidden = false;
  });
  posCustomerCancelBtn?.addEventListener("click", () => { if (posCustomerModal) posCustomerModal.hidden = true; });
  posCustomerConfirmBtn?.addEventListener("click", () => {
    const id = posCustomerSelectValue?.value || "";
    const cust = cachedCustomers.find((c) => String(c.id) === String(id));
    setPosCustomer(id, cust ? cust.full_name : "Walk-in Customer");
    if (posCustomerModal) posCustomerModal.hidden = true;
  });

  // Checkout flow
  // Format a typed amount with thousand separators, keeping an optional decimal part.
  const groupAmount = (raw) => {
    let s = String(raw).replace(/[^0-9.]/g, "");
    const dot = s.indexOf(".");
    if (dot !== -1) {
      s = s.slice(0, dot + 1) + s.slice(dot + 1).replace(/\./g, "");
    }
    let [intPart = "", decPart] = s.split(".");
    intPart = intPart.replace(/^0+(?=\d)/, "");
    const grouped = intPart === "" ? "" : Number(intPart).toLocaleString("en-US");
    if (s.includes(".")) return `${grouped || "0"}.${(decPart || "").slice(0, 2)}`;
    return grouped;
  };

  const updatePosChange = () => {
    const { total } = posTotals();
    const paid = num(posAmountPaid?.value);
    const change = paid > total ? paid - total : 0;
    if (posChangeDue) posChangeDue.textContent = formatCurrency(change);
  };

  // Mobile Money / Card / Bank are exact electronic payments: auto-fill the total and lock
  // the field. Cash stays editable with live thousand-separator formatting and keypad.
  const posKeypad = document.querySelector("[data-pos-keypad]");
  const posReceivedBox = document.querySelector("[data-pos-received-box]");
  const posBackspaceBtn = document.querySelector('[data-pos-key="backspace"]');

  const appendKeyToAmount = (keyVal) => {
    if (!posAmountPaid || posAmountPaid.disabled) return;
    let raw = String(posAmountPaid.value || "").replace(/[^0-9]/g, "");
    if (keyVal === "0" || keyVal === "00" || keyVal === "000") {
      if (!raw || raw === "0") {
        raw = "0";
      } else {
        raw += keyVal;
      }
    } else {
      if (raw === "0") raw = "";
      raw += keyVal;
    }
    posAmountPaid.value = groupAmount(raw);
    updatePosChange();
  };

  const backspaceAmount = () => {
    if (!posAmountPaid || posAmountPaid.disabled) return;
    let raw = String(posAmountPaid.value || "").replace(/[^0-9]/g, "");
    if (raw.length > 1) {
      raw = raw.slice(0, -1);
    } else {
      raw = "";
    }
    posAmountPaid.value = groupAmount(raw);
    updatePosChange();
  };

  posKeypad?.addEventListener("click", (e) => {
    const key = e.target.closest("[data-pos-key]");
    if (!key) return;
    const val = key.dataset.posKey;
    if (val === "backspace") {
      backspaceAmount();
    } else if (val) {
      appendKeyToAmount(val);
    }
  });

  posBackspaceBtn?.addEventListener("click", backspaceAmount);

  const applyAmountForMethod = ({ clearCash = false } = {}) => {
    if (!posAmountPaid) return;
    if (posPayment === "cash") {
      posAmountPaid.disabled = false;
      if (clearCash) posAmountPaid.value = "";
      if (posKeypad) posKeypad.classList.remove("pos-keypad-disabled");
    } else {
      const { total } = posTotals();
      posAmountPaid.value = groupAmount(Math.round(total));
      posAmountPaid.disabled = true;
      if (posKeypad) posKeypad.classList.add("pos-keypad-disabled");
    }
    updatePosChange();
  };

  const openPosCheckout = () => {
    if (!posCart.length) return;
    const { subtotal, tax, rate, total } = posTotals();
    if (posCheckoutCustomer) posCheckoutCustomer.textContent = posCustomer.name;
    if (posCheckoutSubtotal) posCheckoutSubtotal.textContent = formatCurrency(subtotal);
    if (posCheckoutVatRow) posCheckoutVatRow.hidden = !invVatConfig.enabled;
    if (posCheckoutVatRate) posCheckoutVatRate.textContent = String(rate);
    if (posCheckoutVat) posCheckoutVat.textContent = formatCurrency(tax);
    if (posCheckoutTotal) posCheckoutTotal.textContent = formatCurrency(total);
    if (posCheckoutError) posCheckoutError.hidden = true;
    if (posAmountPaid) posAmountPaid.value = "";
    posPayment = "cash";
    posPaymentMethods?.querySelectorAll("[data-pos-payment]").forEach((b) => {
      b.classList.toggle("active", (b.dataset.posPayment || "cash") === "cash");
    });
    applyAmountForMethod({ clearCash: true });
    if (posCheckoutModal) posCheckoutModal.hidden = false;
    if (window.innerWidth >= 768) {
      setTimeout(() => { posAmountPaid?.focus(); }, 60);
    }
  };

  posCheckoutBtn?.addEventListener("click", openPosCheckout);
  posCheckoutCloseBtn?.addEventListener("click", () => { if (posCheckoutModal) posCheckoutModal.hidden = true; });
  posCheckoutModal?.addEventListener("click", (e) => {
    if (e.target === posCheckoutModal) posCheckoutModal.hidden = true;
  });
  posAmountPaid?.addEventListener("input", () => {
    posAmountPaid.value = groupAmount(posAmountPaid.value);
    updatePosChange();
  });
  posAmountPaid?.addEventListener("focus", () => {
    if (posPayment === "cash" && posKeypad) {
      posKeypad.classList.remove("pos-keypad-disabled");
    }
  });

  posPaymentMethods?.querySelectorAll("[data-pos-payment]").forEach((btn) => {
    btn.addEventListener("click", () => {
      posPaymentMethods.querySelectorAll("[data-pos-payment]").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      posPayment = btn.dataset.posPayment || "cash";
      applyAmountForMethod({ clearCash: true });
      if (typeof persistActiveTicket === "function") persistActiveTicket();
    });
  });

  posCompleteSaleBtn?.addEventListener("click", async () => {
    if (!posCart.length) return;
    if (posCheckoutError) posCheckoutError.hidden = true;
    posCompleteSaleBtn.disabled = true;
    try {
      const items = posCart.map((l) => ({ item_id: l.id, quantity: l.qty, unit_price: l.price }));
      const body = new FormData();
      body.set("action", "create_pos_sale");
      body.set("items", JSON.stringify(items));
      body.set("customer_id", String(posCustomer.id || 0));
      body.set("payment_method", posPayment);
      const paid = num(posAmountPaid?.value);
      if (paid > 0) body.set("amount_paid", String(paid));

      const res = await fetch(`${getBasePath()}api/sales.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not complete the sale.");

      lastSale = data;
      if (posCheckoutModal) posCheckoutModal.hidden = true;
      if (posReceiptNumber) posReceiptNumber.textContent = data.receipt_number || "";
      if (posReceiptTotal) posReceiptTotal.textContent = formatCurrency(data.total_amount);
      if (posReceiptPaid) posReceiptPaid.textContent = formatCurrency(data.amount_paid);
      if (posReceiptChange) posReceiptChange.textContent = formatCurrency(data.change_due);
      if (posReceiptModal) posReceiptModal.hidden = false;

      // The completed sale's ticket is done — drop it and move to the next.
      dropActiveTicketAfterSale();
      await refreshPosCatalogue();
      await refreshShift();
      loadInvoices(invoicesSearch?.value.trim() || "");
    } catch (err) {
      if (posCheckoutError) {
        posCheckoutError.textContent = err.message || "Could not complete the sale.";
        posCheckoutError.hidden = false;
      }
    } finally {
      posCompleteSaleBtn.disabled = false;
    }
  });

  posReceiptPrintBtn?.addEventListener("click", () => {
    if (!lastSale) return;
    window.open(`${getBasePath()}api/sales.php?action=pdf&id=${lastSale.sale_id}`, "_blank");
  });
  posNewSaleBtn?.addEventListener("click", () => { if (posReceiptModal) posReceiptModal.hidden = true; });

  // ---- Init ----
  loadProducts();
  loadCustomers();
  loadInvoices();

  const salesParams = new URLSearchParams(window.location.search);
  const targetWorkspace = salesParams.get("workspace") || salesParams.get("view");
  if (targetWorkspace === "pos") {
    openPos();
  } else if (targetWorkspace === "invoices") {
    openWorkspace(invoicesWorkspace);
    loadInvoices(invoicesSearch?.value.trim() || "");
  }
};

const setupBankPage = () => {
  const page = document.querySelector("[data-bank-page]");
  if (!page) return;

  const currency = getStoredBusinessState().selectedBusiness?.currency || "TZS";
  const fmt = (a) => `${(Number(a) || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })} ${currency}`;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const typeIcon = (t) => svgMarkup(t === "bank" ? "bank" : t === "mobile" ? "mobile" : "cash");
  const formatDate = (dateStr) => {
    if (!dateStr) return "";
    try {
      const d = new Date(String(dateStr).replace(" ", "T"));
      return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
    } catch { return dateStr; }
  };

  // KPI refs
  const kpiCash = document.querySelector("[data-kpi-cash]");
  const kpiBank = document.querySelector("[data-kpi-bank]");
  const kpiMobile = document.querySelector("[data-kpi-mobile]");
  const kpiNet = document.querySelector("[data-kpi-net]");
  const accountsCountBadge = document.querySelector("[data-accounts-count-badge]");

  // Workspaces
  const accountsWorkspace = document.querySelector("[data-accounts-workspace]");
  const activityWorkspace = document.querySelector("[data-activity-workspace]");
  const detailWorkspace = document.querySelector("[data-account-detail-workspace]");
  const openWorkspace = (el, refresh) => { if (el) { el.hidden = false; if (typeof refresh === "function") refresh(); } };
  const closeWorkspace = (el) => { if (el) el.hidden = true; };

  // Lists
  const accountsList = document.querySelector("[data-accounts-list]");
  const accountsEmpty = document.querySelector("[data-accounts-empty]");
  const activityList = document.querySelector("[data-activity-list]");
  const activityEmpty = document.querySelector("[data-activity-empty]");

  // Detail refs
  const detailName = document.querySelector("[data-account-detail-name]");
  const detailMeta = document.querySelector("[data-account-detail-meta]");
  const detailBalance = document.querySelector("[data-account-detail-balance]");
  const detailTxns = document.querySelector("[data-account-txns]");
  const detailTxnsEmpty = document.querySelector("[data-account-txns-empty]");

  // Account form
  const accountFormModal = document.querySelector("[data-account-form-modal]");
  const accountForm = document.querySelector("[data-account-form]");
  const accountFormTitle = document.querySelector("[data-account-form-title]");
  const accountFormId = document.querySelector("[data-account-form-id]");
  const accountFormType = document.querySelector("[data-account-form-type]");
  const accountBankFields = document.querySelector("[data-account-bank-fields]");
  const accountOpeningField = document.querySelector("[data-account-opening-field]");
  const accountFormDefault = document.querySelector("[data-account-form-default]");
  const accountFormError = document.querySelector("[data-account-form-error]");
  const accountFormSubmit = document.querySelector("[data-account-form-submit]");

  // Money modal
  const moneyModal = document.querySelector("[data-money-modal]");
  const moneyForm = document.querySelector("[data-money-form]");
  const moneyTitle = document.querySelector("[data-money-title]");
  const moneyAction = document.querySelector("[data-money-action]");
  const moneyAccountId = document.querySelector("[data-money-account-id]");
  const moneyAccountName = document.querySelector("[data-money-account-name]");
  const moneyError = document.querySelector("[data-money-error]");
  const moneySubmit = document.querySelector("[data-money-submit]");

  // Transfer modal
  const transferModal = document.querySelector("[data-transfer-modal]");
  const transferForm = document.querySelector("[data-transfer-form]");
  const transferFromId = document.querySelector("[data-transfer-from-id]");
  const transferFromName = document.querySelector("[data-transfer-from-name]");
  const transferTo = document.querySelector("[data-transfer-to]");
  const transferError = document.querySelector("[data-transfer-error]");
  const transferSubmit = document.querySelector("[data-transfer-submit]");

  let accountsCache = [];
  let currentAccount = null;

  const renderAccounts = (accounts = [], summary = null) => {
    accountsCache = accounts;
    if (summary) {
      if (kpiCash) kpiCash.textContent = fmt(summary.cash);
      if (kpiBank) kpiBank.textContent = fmt(summary.bank);
      if (kpiMobile) kpiMobile.textContent = fmt(summary.mobile);
      if (kpiNet) kpiNet.textContent = fmt(summary.net);
    }
    if (accountsCountBadge) accountsCountBadge.textContent = `${accounts.length} account${accounts.length === 1 ? "" : "s"}`;

    if (!accounts.length) {
      accountsList?.replaceChildren();
      if (accountsEmpty) accountsEmpty.hidden = false;
      return;
    }
    if (accountsEmpty) accountsEmpty.hidden = true;
    if (accountsList) {
      accountsList.innerHTML = accounts.map((a) => {
        const defBadge = a.is_default ? ` <span class="badge-stock in-stock">Default</span>` : "";
        return `
          <button type="button" class="settings-list-row" data-acc-open="${a.id}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;text-align:left;">
            <span style="display:flex;align-items:center;gap:10px;min-width:0;">
              <span class="customer-avatar" style="width:38px;height:38px;font-size:1.1rem;">${typeIcon(a.type)}</span>
              <span style="min-width:0;">
                <span style="display:block;font-weight:800;color:var(--color-navy);font-size:0.94rem;">${esc(a.name)}${defBadge}</span>
                <span style="display:block;font-size:0.78rem;color:var(--color-muted);">${esc(a.type_label)}</span>
              </span>
            </span>
            <strong style="color:var(--color-blue);font-size:0.95rem;white-space:nowrap;">${fmt(a.balance)}</strong>
          </button>`;
      }).join("");
    }
  };

  const loadAccounts = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/accounts.php`);
      const data = await res.json();
      if (res.ok && data.ok) renderAccounts(data.accounts || [], data.summary || null);
    } catch { /* offline */ }
  };

  const renderDetail = (account) => {
    currentAccount = account;
    if (detailName) detailName.textContent = account.name;
    if (detailMeta) {
      const extra = [account.type_label, account.bank_name, account.account_number].filter(Boolean).map(esc).join(" • ");
      detailMeta.textContent = extra;
    }
    if (detailBalance) detailBalance.textContent = fmt(account.balance);

    const txns = account.transactions || [];
    if (!txns.length) {
      detailTxns?.replaceChildren();
      if (detailTxnsEmpty) detailTxnsEmpty.hidden = false;
    } else {
      if (detailTxnsEmpty) detailTxnsEmpty.hidden = true;
      if (detailTxns) {
        detailTxns.innerHTML = txns.map((t) => {
          const inbound = t.direction === "in";
          const sign = inbound ? "+" : "−";
          const color = inbound ? "#16a34a" : "#dc2626";
          const sub = [t.type_label, t.notes].filter(Boolean).map(esc).join(" • ");
          return `
            <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:10px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
              <div style="min-width:0;text-align:left;">
                <div style="font-weight:700;color:var(--color-navy);font-size:0.88rem;">${esc(t.type_label)}</div>
                <div style="font-size:0.75rem;color:var(--color-muted);">${esc(sub)}${sub ? " • " : ""}${formatDate(t.created_at)}</div>
              </div>
              <strong style="color:${color};white-space:nowrap;">${sign}${fmt(t.amount)}</strong>
            </div>`;
        }).join("");
      }
    }
  };

  const openAccountDetail = async (id) => {
    try {
      const res = await fetch(`${getBasePath()}api/accounts.php?id=${encodeURIComponent(id)}`);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Account not found.");
      renderDetail(data.account);
      if (detailWorkspace) detailWorkspace.hidden = false;
    } catch (err) {
      await showAppModal("Bank & Cash", err.message || "Please try again.");
    }
  };

  const openAccountForm = (account = null) => {
    if (!accountForm) return;
    accountForm.reset();
    if (accountFormError) accountFormError.hidden = true;
    if (accountFormId) accountFormId.value = account ? String(account.id) : "";
    if (accountFormTitle) accountFormTitle.textContent = account ? "Edit Account" : "Add Account";
    if (account) {
      accountForm.elements.name.value = account.name || "";
      accountForm.elements.bank_name.value = account.bank_name || "";
      accountForm.elements.account_number.value = account.account_number || "";
      if (accountOpeningField) accountOpeningField.hidden = true; // opening balance immutable after creation
      if (accountFormDefault) { accountFormDefault.checked = !!account.is_default; accountFormDefault.disabled = !!account.is_default; }
    } else {
      if (accountOpeningField) accountOpeningField.hidden = false;
      if (accountFormDefault) accountFormDefault.disabled = false;
    }
    // Sync the styled account-type select (dispatch updates its label, active state and bank fields).
    if (accountFormType) {
      const desiredType = account ? (account.type || "cash") : "cash";
      accountFormType.value = desiredType;
      accountFormType.setAttribute("value", desiredType);
      accountFormType.dispatchEvent(new Event("change", { bubbles: true }));
    }
    toggleBankFields();
    if (accountFormModal) accountFormModal.hidden = false;
  };

  const toggleBankFields = () => {
    const t = accountFormType?.value || "cash";
    if (accountBankFields) accountBankFields.hidden = (t === "cash");
  };
  accountFormType?.addEventListener("change", toggleBankFields);

  const openMoney = (action, account) => {
    if (!moneyForm || !account) return;
    moneyForm.reset();
    if (moneyError) moneyError.hidden = true;
    if (moneyAction) moneyAction.value = action;
    if (moneyAccountId) moneyAccountId.value = String(account.id);
    if (moneyAccountName) moneyAccountName.textContent = account.name;
    const titles = { deposit: "Deposit", withdraw: "Withdraw", expense: "Record Expense" };
    if (moneyTitle) moneyTitle.textContent = titles[action] || "Transaction";
    if (moneySubmit) moneySubmit.textContent = titles[action] || "Confirm";
    if (moneyModal) moneyModal.hidden = false;
  };

  const openTransfer = (account) => {
    if (!transferForm || !account) return;
    transferForm.reset();
    if (transferError) transferError.hidden = true;
    if (transferFromId) transferFromId.value = String(account.id);
    if (transferFromName) transferFromName.textContent = account.name;
    if (transferTo) {
      transferTo.innerHTML = accountsCache
        .filter((a) => String(a.id) !== String(account.id))
        .map((a) => `<option value="${a.id}">${esc(a.name)} (${esc(a.type_label)})</option>`)
        .join("");
    }
    if (transferModal) transferModal.hidden = false;
  };

  const loadActivity = async () => {
    try {
      const res = await fetch(`${getBasePath()}api/accounts.php?action=activity`);
      const data = await res.json();
      if (!res.ok || !data.ok) return;
      const rows = data.activity || [];
      if (!rows.length) {
        activityList?.replaceChildren();
        if (activityEmpty) activityEmpty.hidden = false;
        return;
      }
      if (activityEmpty) activityEmpty.hidden = true;
      if (activityList) {
        activityList.innerHTML = rows.map((t) => {
          const inbound = t.direction === "in";
          const sign = inbound ? "+" : "−";
          const color = inbound ? "#16a34a" : "#dc2626";
          const sub = [t.account_name, t.type_label].filter(Boolean).map(esc).join(" • ");
          return `
            <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:10px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
              <div style="min-width:0;text-align:left;">
                <div style="font-weight:700;color:var(--color-navy);font-size:0.88rem;">${esc(sub)}</div>
                <div style="font-size:0.75rem;color:var(--color-muted);">${esc(t.notes || "")}${t.notes ? " • " : ""}${formatDate(t.created_at)}</div>
              </div>
              <strong style="color:${color};white-space:nowrap;">${sign}${fmt(t.amount)}</strong>
            </div>`;
        }).join("");
      }
    } catch { /* offline */ }
  };

  // ---- Wiring ----
  document.querySelectorAll("[data-open-accounts-workspace]").forEach((btn) => btn.addEventListener("click", () => openWorkspace(accountsWorkspace, loadAccounts)));
  document.querySelector("[data-accounts-workspace-close]")?.addEventListener("click", () => closeWorkspace(accountsWorkspace));
  document.querySelectorAll("[data-open-activity-workspace]").forEach((btn) => btn.addEventListener("click", () => openWorkspace(activityWorkspace, loadActivity)));
  document.querySelector("[data-activity-workspace-close]")?.addEventListener("click", () => closeWorkspace(activityWorkspace));
  document.querySelector("[data-account-detail-close]")?.addEventListener("click", () => closeWorkspace(detailWorkspace));
  document.querySelectorAll("[data-open-create-account]").forEach((btn) => btn.addEventListener("click", () => openAccountForm(null)));
  document.querySelector("[data-account-form-close]")?.addEventListener("click", () => { if (accountFormModal) accountFormModal.hidden = true; });
  document.querySelector("[data-money-close]")?.addEventListener("click", () => { if (moneyModal) moneyModal.hidden = true; });
  document.querySelector("[data-transfer-close]")?.addEventListener("click", () => { if (transferModal) transferModal.hidden = true; });

  accountsList?.addEventListener("click", (e) => {
    const id = e.target.closest("[data-acc-open]")?.dataset.accOpen;
    if (id) openAccountDetail(id);
  });

  // Detail action buttons operate on currentAccount.
  document.querySelector("[data-acc-deposit]")?.addEventListener("click", () => currentAccount && openMoney("deposit", currentAccount));
  document.querySelector("[data-acc-withdraw]")?.addEventListener("click", () => currentAccount && openMoney("withdraw", currentAccount));
  document.querySelector("[data-acc-expense]")?.addEventListener("click", () => currentAccount && openMoney("expense", currentAccount));
  document.querySelector("[data-acc-transfer]")?.addEventListener("click", () => currentAccount && openTransfer(currentAccount));
  document.querySelector("[data-acc-edit]")?.addEventListener("click", () => currentAccount && openAccountForm(currentAccount));
  document.querySelector("[data-acc-setdefault]")?.addEventListener("click", async () => {
    if (!currentAccount) return;
    const body = new FormData(); body.set("action", "set_default"); body.set("account_id", String(currentAccount.id));
    try {
      const res = await fetch(`${getBasePath()}api/accounts.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not update.");
      renderAccounts(data.accounts || [], data.summary || null);
      await openAccountDetail(currentAccount.id);
    } catch (err) { await showAppModal("Bank & Cash", err.message || "Please try again."); }
  });
  document.querySelector("[data-acc-delete]")?.addEventListener("click", async () => {
    if (!currentAccount) return;
    const confirmed = await showConfirmModal({ title: "Delete account", message: `Delete "${currentAccount.name}"? Its transaction history will be removed. This cannot be undone.`, confirmLabel: "Delete", danger: true });
    if (!confirmed) return;
    const body = new FormData(); body.set("action", "delete"); body.set("account_id", String(currentAccount.id));
    try {
      const res = await fetch(`${getBasePath()}api/accounts.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not delete.");
      renderAccounts(data.accounts || [], data.summary || null);
      closeWorkspace(detailWorkspace);
    } catch (err) { await showAppModal("Bank & Cash", err.message || "Please try again."); }
  });

  accountForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (accountFormError) accountFormError.hidden = true;
    const name = accountForm.elements.name.value.trim();
    if (!name) {
      if (accountFormError) { accountFormError.textContent = "Account name is required."; accountFormError.hidden = false; }
      return;
    }
    if (accountFormSubmit) accountFormSubmit.disabled = true;
    try {
      const id = accountFormId?.value || "";
      const body = new FormData();
      body.set("action", id ? "update" : "create");
      if (id) body.set("account_id", id);
      body.set("name", name);
      body.set("type", accountFormType?.value || "cash");
      body.set("bank_name", accountForm.elements.bank_name.value.trim());
      body.set("account_number", accountForm.elements.account_number.value.trim());
      if (!id) body.set("opening_balance", String(parseFloat(String(accountForm.elements.opening_balance.value).replace(/[^0-9.\-]/g, "")) || 0));
      if (accountFormDefault?.checked) body.set("is_default", "1");
      const res = await fetch(`${getBasePath()}api/accounts.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not save account.");
      renderAccounts(data.accounts || [], data.summary || null);
      if (accountFormModal) accountFormModal.hidden = true;
      if (id && currentAccount && String(currentAccount.id) === String(id) && detailWorkspace && !detailWorkspace.hidden) {
        await openAccountDetail(id);
      }
    } catch (err) {
      if (accountFormError) { accountFormError.textContent = err.message || "Could not save account."; accountFormError.hidden = false; }
    } finally {
      if (accountFormSubmit) accountFormSubmit.disabled = false;
    }
  });

  moneyForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (moneyError) moneyError.hidden = true;
    const amount = parseFloat(String(moneyForm.elements.amount.value).replace(/[^0-9.\-]/g, "")) || 0;
    if (amount <= 0) {
      if (moneyError) { moneyError.textContent = "Enter an amount greater than zero."; moneyError.hidden = false; }
      return;
    }
    if (moneySubmit) moneySubmit.disabled = true;
    try {
      const body = new FormData();
      body.set("action", moneyAction?.value || "deposit");
      body.set("account_id", moneyAccountId?.value || "");
      body.set("amount", String(amount));
      body.set("notes", moneyForm.elements.notes.value.trim());
      const res = await fetch(`${getBasePath()}api/accounts.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not record transaction.");
      renderAccounts(data.accounts || [], data.summary || null);
      if (moneyModal) moneyModal.hidden = true;
      if (currentAccount) await openAccountDetail(currentAccount.id);
    } catch (err) {
      if (moneyError) { moneyError.textContent = err.message || "Could not record transaction."; moneyError.hidden = false; }
    } finally {
      if (moneySubmit) moneySubmit.disabled = false;
    }
  });

  transferForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (transferError) transferError.hidden = true;
    const toId = transferTo?.value || "";
    const amount = parseFloat(String(transferForm.elements.amount.value).replace(/[^0-9.\-]/g, "")) || 0;
    if (!toId) {
      if (transferError) { transferError.textContent = "Choose an account to transfer to."; transferError.hidden = false; }
      return;
    }
    if (amount <= 0) {
      if (transferError) { transferError.textContent = "Enter an amount greater than zero."; transferError.hidden = false; }
      return;
    }
    if (transferSubmit) transferSubmit.disabled = true;
    try {
      const body = new FormData();
      body.set("action", "transfer");
      body.set("account_id", transferFromId?.value || "");
      body.set("to_account_id", toId);
      body.set("amount", String(amount));
      body.set("notes", transferForm.elements.notes.value.trim());
      const res = await fetch(`${getBasePath()}api/accounts.php`, { method: "POST", body });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.message || "Could not complete transfer.");
      renderAccounts(data.accounts || [], data.summary || null);
      if (transferModal) transferModal.hidden = true;
      if (currentAccount) await openAccountDetail(currentAccount.id);
    } catch (err) {
      if (transferError) { transferError.textContent = err.message || "Could not complete transfer."; transferError.hidden = false; }
    } finally {
      if (transferSubmit) transferSubmit.disabled = false;
    }
  });

  // ---- Init ----
  loadAccounts();
};

const setupRealEstatePage = () => {
  const page = document.querySelector("[data-realestate-page]");
  if (!page) return;

  const RE = `${getBasePath()}api/realestate.php`;
  const currency = getStoredBusinessState().selectedBusiness?.currency || "TZS";
  const fmt = (a) => `${(Number(a) || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })} ${currency}`;
  const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const num = (v) => parseFloat(String(v ?? "").replace(/[^0-9.\-]/g, "")) || 0;
  const todayStr = () => new Date().toISOString().slice(0, 10);
  const formatDate = (dateStr) => {
    if (!dateStr) return "";
    try { const d = new Date(String(dateStr).replace(" ", "T")); return isNaN(d.getTime()) ? dateStr : d.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" }); } catch { return dateStr; }
  };
  const chargeLabel = (t) => (t === "daily" ? "/day" : "/month");

  const openWorkspace = (el, refresh) => { if (el) { el.hidden = false; if (typeof refresh === "function") refresh(); } };
  const closeWorkspace = (el) => { if (el) el.hidden = true; };
  const api = async (opts) => {
    const res = await fetch(RE + (opts.query || ""), opts.body ? { method: "POST", body: opts.body } : {});
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.message || "Request failed.");
    return data;
  };

  // ---- Summary / KPIs ----
  const kpiProperties = document.querySelector("[data-kpi-properties]");
  const kpiUnits = document.querySelector("[data-kpi-units]");
  const kpiTenancies = document.querySelector("[data-kpi-tenancies]");
  const kpiRent = document.querySelector("[data-kpi-rent]");
  const propertiesCountBadge = document.querySelector("[data-properties-count-badge]");
  const tenanciesCountBadge = document.querySelector("[data-tenancies-count-badge]");
  const loadSummary = async () => {
    try {
      const { summary: s } = await api({ query: "?resource=summary" });
      if (kpiProperties) kpiProperties.textContent = s.properties;
      if (kpiUnits) kpiUnits.textContent = `${s.units_occupied} / ${s.units_total}`;
      if (kpiTenancies) kpiTenancies.textContent = s.active_tenancies;
      if (kpiRent) kpiRent.textContent = fmt(s.rent_collected_month);
      if (propertiesCountBadge) propertiesCountBadge.textContent = `${s.properties} propert${s.properties === 1 ? "y" : "ies"}`;
      if (tenanciesCountBadge) tenanciesCountBadge.textContent = `${s.active_tenancies} active`;
    } catch { /* offline */ }
  };

  // ======================= PROPERTIES =======================
  const propertiesWorkspace = document.querySelector("[data-properties-workspace]");
  const propertiesList = document.querySelector("[data-properties-list]");
  const propertiesEmpty = document.querySelector("[data-properties-empty]");
  const propertyDetailWorkspace = document.querySelector("[data-property-detail-workspace]");
  const propertyDetailName = document.querySelector("[data-property-detail-name]");
  const propertyDetailMeta = document.querySelector("[data-property-detail-meta]");
  const unitsList = document.querySelector("[data-units-list]");
  const unitsEmpty = document.querySelector("[data-units-empty]");

  const propertyFormModal = document.querySelector("[data-property-form-modal]");
  const propertyForm = document.querySelector("[data-property-form]");
  const propertyFormTitle = document.querySelector("[data-property-form-title]");
  const propertyFormId = document.querySelector("[data-property-form-id]");
  const propertyFormError = document.querySelector("[data-property-form-error]");
  const propertyFormSubmit = document.querySelector("[data-property-form-submit]");

  const unitFormModal = document.querySelector("[data-unit-form-modal]");
  const unitForm = document.querySelector("[data-unit-form]");
  const unitFormTitle = document.querySelector("[data-unit-form-title]");
  const unitFormId = document.querySelector("[data-unit-form-id]");
  const unitFormPropertyId = document.querySelector("[data-unit-form-property-id]");
  const unitFormError = document.querySelector("[data-unit-form-error]");
  const unitFormSubmit = document.querySelector("[data-unit-form-submit]");

  let propertyCache = [];
  let unitCache = [];
  let currentProperty = null;

  const renderProperties = (list) => {
    propertyCache = list;
    if (!list.length) { propertiesList?.replaceChildren(); if (propertiesEmpty) propertiesEmpty.hidden = false; return; }
    if (propertiesEmpty) propertiesEmpty.hidden = true;
    propertiesList.innerHTML = list.map((p) => `
      <button type="button" class="settings-list-row" data-prop-open="${p.id}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;text-align:left;">
        <span style="display:flex;align-items:center;gap:10px;min-width:0;">
          <span class="customer-avatar" style="width:38px;height:38px;">${svgMarkup("building")}</span>
          <span style="min-width:0;">
            <span style="display:block;font-weight:800;color:var(--color-navy);font-size:0.94rem;">${esc(p.name)}</span>
            <span style="display:block;font-size:0.78rem;color:var(--color-muted);">${esc(p.type)}${p.location ? " • " + esc(p.location) : ""}</span>
          </span>
        </span>
        <span style="font-size:0.8rem;color:var(--color-muted);white-space:nowrap;">${p.occupied_count}/${p.units_count} units</span>
      </button>`).join("");
  };
  const loadProperties = async () => { try { const d = await api({ query: "?resource=properties" }); renderProperties(d.properties || []); } catch {} };

  const renderUnits = (list) => {
    unitCache = list;
    if (!list.length) { unitsList?.replaceChildren(); if (unitsEmpty) unitsEmpty.hidden = false; return; }
    if (unitsEmpty) unitsEmpty.hidden = true;
    unitsList.innerHTML = list.map((u) => {
      const occupied = u.status === "occupied";
      const badge = occupied
        ? `<span class="badge-stock in-stock">Occupied</span>`
        : `<span class="badge-stock low-stock">Vacant</span>`;
      const tenant = occupied && u.tenant_name ? `<div style="font-size:0.75rem;color:var(--color-muted);">Tenant: ${esc(u.tenant_name)}</div>` : "";
      return `
        <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
          <div style="min-width:0;text-align:left;">
            <div style="font-weight:800;color:var(--color-navy);font-size:0.92rem;">${esc(u.name)} ${badge}</div>
            <div style="font-size:0.8rem;color:var(--color-blue);font-weight:700;">${fmt(u.rate)}<span style="color:var(--color-muted);font-weight:500;">${chargeLabel(u.charge_type)}</span></div>
            ${tenant}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;flex:0 0 auto;">
            <button type="button" class="btn btn-outline btn-sm" data-unit-edit="${u.id}">Edit</button>
            <button type="button" class="btn btn-danger-outline btn-sm" data-unit-delete="${u.id}">Delete</button>
          </div>
        </div>`;
    }).join("");
  };
  const loadUnits = async (propertyId) => { try { const d = await api({ query: `?resource=units&property_id=${propertyId}` }); renderUnits(d.units || []); } catch {} };

  const openPropertyDetail = async (id) => {
    try {
      const d = await api({ query: `?id=${id}` });
      currentProperty = d.property;
      if (propertyDetailName) propertyDetailName.textContent = d.property.name;
      if (propertyDetailMeta) propertyDetailMeta.textContent = [d.property.type, d.property.location].filter(Boolean).join(" • ") || "No location set";
      renderUnits(d.property.units || []);
      if (propertyDetailWorkspace) propertyDetailWorkspace.hidden = false;
    } catch (err) { await showAppModal("Real Estate", err.message); }
  };

  const openPropertyForm = (p = null) => {
    propertyForm.reset();
    if (propertyFormError) propertyFormError.hidden = true;
    if (propertyFormId) propertyFormId.value = p ? String(p.id) : "";
    if (propertyFormTitle) propertyFormTitle.textContent = p ? "Edit Property" : "Add Property";
    if (p) {
      propertyForm.elements.name.value = p.name || "";
      propertyForm.elements.location.value = p.location || "";
      propertyForm.elements.notes.value = p.notes || "";
    }
    // Sync the styled Type select (dispatch updates its label + active state).
    const typeInput = propertyForm.elements.type;
    if (typeInput) {
      const desiredType = p ? (p.type || "residential") : "residential";
      typeInput.value = desiredType;
      typeInput.setAttribute("value", desiredType);
      typeInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (propertyFormModal) propertyFormModal.hidden = false;
  };
  const openUnitForm = (u = null) => {
    unitForm.reset();
    if (unitFormError) unitFormError.hidden = true;
    if (unitFormId) unitFormId.value = u ? String(u.id) : "";
    if (unitFormPropertyId) unitFormPropertyId.value = String(currentProperty?.id || "");
    if (unitFormTitle) unitFormTitle.textContent = u ? "Edit Unit" : "Add Unit";
    if (u) {
      unitForm.elements.name.value = u.name || "";
      unitForm.elements.charge_type.value = u.charge_type || "monthly";
      unitForm.elements.rate.value = u.rate ? groupThousands(String(u.rate)) : "";
      unitForm.elements.notes.value = u.notes || "";
    }
    if (unitFormModal) unitFormModal.hidden = false;
  };
  attachThousandsFormatting(unitForm?.elements.rate);

  document.querySelectorAll("[data-open-properties-workspace]").forEach((b) => b.addEventListener("click", () => openWorkspace(propertiesWorkspace, loadProperties)));
  document.querySelector("[data-properties-workspace-close]")?.addEventListener("click", () => closeWorkspace(propertiesWorkspace));
  document.querySelector("[data-property-detail-close]")?.addEventListener("click", () => { closeWorkspace(propertyDetailWorkspace); loadProperties(); loadSummary(); });
  document.querySelectorAll("[data-open-create-property]").forEach((b) => b.addEventListener("click", () => openPropertyForm(null)));
  document.querySelector("[data-property-form-close]")?.addEventListener("click", () => { if (propertyFormModal) propertyFormModal.hidden = true; });
  document.querySelectorAll("[data-open-create-unit]").forEach((b) => b.addEventListener("click", () => { if (currentProperty) openUnitForm(null); }));
  document.querySelector("[data-unit-form-close]")?.addEventListener("click", () => { if (unitFormModal) unitFormModal.hidden = true; });

  propertiesList?.addEventListener("click", (e) => { const id = e.target.closest("[data-prop-open]")?.dataset.propOpen; if (id) openPropertyDetail(id); });
  document.querySelector("[data-prop-edit]")?.addEventListener("click", () => currentProperty && openPropertyForm(currentProperty));
  document.querySelector("[data-prop-delete]")?.addEventListener("click", async () => {
    if (!currentProperty) return;
    const ok = await showConfirmModal({ title: "Delete property", message: `Delete "${currentProperty.name}" and its units? This cannot be undone.`, confirmLabel: "Delete", danger: true });
    if (!ok) return;
    try { const b = new FormData(); b.set("action", "property_delete"); b.set("property_id", String(currentProperty.id)); await api({ body: b }); closeWorkspace(propertyDetailWorkspace); await loadProperties(); await loadSummary(); }
    catch (err) { await showAppModal("Real Estate", err.message); }
  });

  unitsList?.addEventListener("click", async (e) => {
    const editId = e.target.closest("[data-unit-edit]")?.dataset.unitEdit;
    const delId = e.target.closest("[data-unit-delete]")?.dataset.unitDelete;
    if (editId) { const u = unitCache.find((x) => String(x.id) === String(editId)); if (u) openUnitForm(u); return; }
    if (delId) {
      const u = unitCache.find((x) => String(x.id) === String(delId));
      const ok = await showConfirmModal({ title: "Delete unit", message: `Delete "${u?.name || "this unit"}"?`, confirmLabel: "Delete", danger: true });
      if (!ok) return;
      try { const b = new FormData(); b.set("action", "unit_delete"); b.set("unit_id", delId); const d = await api({ body: b }); renderUnits(d.units || []); await loadSummary(); }
      catch (err) { await showAppModal("Real Estate", err.message); }
    }
  });

  propertyForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (propertyFormError) propertyFormError.hidden = true;
    const name = propertyForm.elements.name.value.trim();
    if (!name) { if (propertyFormError) { propertyFormError.textContent = "Property name is required."; propertyFormError.hidden = false; } return; }
    if (propertyFormSubmit) propertyFormSubmit.disabled = true;
    try {
      const id = propertyFormId.value;
      const b = new FormData();
      b.set("action", id ? "property_update" : "property_create");
      if (id) b.set("property_id", id);
      b.set("name", name);
      b.set("type", propertyForm.elements.type.value);
      b.set("location", propertyForm.elements.location.value.trim());
      b.set("notes", propertyForm.elements.notes.value.trim());
      const d = await api({ body: b });
      renderProperties(d.properties || []);
      if (propertyFormModal) propertyFormModal.hidden = true;
      if (id && currentProperty && String(currentProperty.id) === String(id) && propertyDetailWorkspace && !propertyDetailWorkspace.hidden) await openPropertyDetail(id);
      await loadSummary();
    } catch (err) { if (propertyFormError) { propertyFormError.textContent = err.message; propertyFormError.hidden = false; } }
    finally { if (propertyFormSubmit) propertyFormSubmit.disabled = false; }
  });

  unitForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (unitFormError) unitFormError.hidden = true;
    const name = unitForm.elements.name.value.trim();
    if (!name) { if (unitFormError) { unitFormError.textContent = "Unit name is required."; unitFormError.hidden = false; } return; }
    if (unitFormSubmit) unitFormSubmit.disabled = true;
    try {
      const id = unitFormId.value;
      const b = new FormData();
      b.set("action", id ? "unit_update" : "unit_create");
      if (id) b.set("unit_id", id);
      b.set("property_id", unitFormPropertyId.value);
      b.set("name", name);
      b.set("charge_type", unitForm.elements.charge_type.value);
      b.set("rate", String(num(unitForm.elements.rate.value)));
      b.set("notes", unitForm.elements.notes.value.trim());
      const d = await api({ body: b });
      renderUnits(d.units || []);
      if (unitFormModal) unitFormModal.hidden = true;
      await loadSummary();
    } catch (err) { if (unitFormError) { unitFormError.textContent = err.message; unitFormError.hidden = false; } }
    finally { if (unitFormSubmit) unitFormSubmit.disabled = false; }
  });

  // ======================= TENANCIES =======================
  const tenanciesWorkspace = document.querySelector("[data-tenancies-workspace]");
  const tenanciesListEl = document.querySelector("[data-tenancies-list]");
  const tenanciesEmpty = document.querySelector("[data-tenancies-empty]");
  const tenancyFormModal = document.querySelector("[data-tenancy-form-modal]");
  const tenancyForm = document.querySelector("[data-tenancy-form]");
  const tenancyUnitWrap = document.querySelector("[data-tenancy-unit-select]");
  const tenancyUnitSel = document.querySelector("[data-tenancy-unit]"); // hidden value input
  const tenancyUnitHint = document.querySelector("[data-tenancy-unit-hint]");
  const tenancyTenantWrap = document.querySelector("[data-tenancy-tenant-select]");
  const tenancyTenantSel = document.querySelector("[data-tenancy-tenant]"); // hidden value input
  const newTenantFields = document.querySelector("[data-new-tenant-fields]");
  const newTenantName = document.querySelector("[data-new-tenant-name]");
  const newTenantPhone = document.querySelector("[data-new-tenant-phone]");
  const tenancyFormError = document.querySelector("[data-tenancy-form-error]");
  const tenancyFormSubmit = document.querySelector("[data-tenancy-form-submit]");
  let tenancyCache = [];
  let vacantUnitsCache = [];

  const renderTenancies = (list) => {
    tenancyCache = list;
    const active = list.filter((t) => t.status === "active");
    if (tenanciesCountBadge) tenanciesCountBadge.textContent = `${active.length} active`;
    if (!list.length) { tenanciesListEl?.replaceChildren(); if (tenanciesEmpty) tenanciesEmpty.hidden = false; return; }
    if (tenanciesEmpty) tenanciesEmpty.hidden = true;
    tenanciesListEl.innerHTML = list.map((t) => {
      const isActive = t.status === "active";
      const badge = isActive ? `<span class="badge-stock in-stock">Active</span>` : `<span class="badge-stock out-of-stock">Ended</span>`;
      const endBtn = isActive ? `<button type="button" class="btn btn-danger-outline btn-sm" data-tenancy-end="${t.id}">End</button>` : "";
      return `
        <div class="settings-list-row" data-tenancy-open="${t.id}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;">
          <div style="min-width:0;text-align:left;">
            <div style="font-weight:800;color:var(--color-navy);font-size:0.92rem;">${esc(t.tenant_name)} ${badge}</div>
            <div style="font-size:0.78rem;color:var(--color-muted);">${esc(t.property_name)} • ${esc(t.unit_name)}</div>
            <div style="font-size:0.78rem;color:var(--color-blue);font-weight:700;">${fmt(t.rate)}<span style="color:var(--color-muted);font-weight:500;">${chargeLabel(t.charge_type)}</span> • from ${formatDate(t.start_date)}</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex:0 0 auto;">${endBtn}<svg class="svg-ico" viewBox="0 0 512 512" aria-hidden="true" style="width:16px;height:16px;color:var(--color-muted);"><path d="M184 112l144 144-144 144" /></svg></div>
        </div>`;
    }).join("");
  };
  const loadTenancies = async () => { try { const d = await api({ query: "?resource=tenancies" }); renderTenancies(d.tenancies || []); } catch {} };

  const openTenancyForm = async () => {
    tenancyForm.reset();
    if (tenancyFormError) tenancyFormError.hidden = true;
    if (newTenantFields) newTenantFields.hidden = true;
    tenancyForm.elements.start_date.value = todayStr();
    try {
      const [unitsD, custD] = await Promise.all([
        api({ query: "?resource=units" }),
        fetch(`${getBasePath()}api/customers.php`).then((r) => r.json()).catch(() => ({ customers: [] })),
      ]);
      vacantUnitsCache = (unitsD.units || []).filter((u) => u.status === "vacant");
      const unitOptions = vacantUnitsCache.map((u) => ({
        value: String(u.id),
        label: `${u.property_name} — ${u.name} (${fmt(u.rate)}${chargeLabel(u.charge_type)})`,
      }));
      setSearchSelectOptions(tenancyUnitWrap, unitOptions, vacantUnitsCache.length ? "Choose a vacant unit..." : "No vacant units", "");
      updateUnitHint();

      const customers = custD.customers || [];
      const tenantOptions = customers
        .map((c) => ({ value: String(c.id), label: `${c.full_name}${c.phone ? ` (${c.phone})` : ""}` }))
        .concat([{ value: "__new__", label: "➕ New tenant…" }]);
      setSearchSelectOptions(tenancyTenantWrap, tenantOptions, "Choose a tenant...", "");
      if (newTenantFields) newTenantFields.hidden = true;
    } catch { /* ignore */ }
    if (tenancyFormModal) tenancyFormModal.hidden = false;
  };
  const updateUnitHint = () => {
    const u = vacantUnitsCache.find((x) => String(x.id) === String(tenancyUnitSel?.value));
    if (tenancyUnitHint) tenancyUnitHint.textContent = u ? `Rent: ${fmt(u.rate)}${chargeLabel(u.charge_type)}` : "";
  };
  tenancyUnitSel?.addEventListener("change", updateUnitHint);
  tenancyTenantSel?.addEventListener("change", () => { if (newTenantFields) newTenantFields.hidden = tenancyTenantSel.value !== "__new__"; });
  attachThousandsFormatting(tenancyForm?.elements.deposit);

  document.querySelectorAll("[data-open-tenancies-workspace]").forEach((b) => b.addEventListener("click", () => openWorkspace(tenanciesWorkspace, loadTenancies)));
  document.querySelector("[data-tenancies-workspace-close]")?.addEventListener("click", () => closeWorkspace(tenanciesWorkspace));
  document.querySelectorAll("[data-open-create-tenancy]").forEach((b) => b.addEventListener("click", openTenancyForm));
  document.querySelector("[data-tenancy-form-close]")?.addEventListener("click", () => { if (tenancyFormModal) tenancyFormModal.hidden = true; });

  tenanciesListEl?.addEventListener("click", async (e) => {
    const endId = e.target.closest("[data-tenancy-end]")?.dataset.tenancyEnd;
    if (endId) {
      const ok = await showConfirmModal({ title: "End tenancy", message: "End this tenancy? The unit will be marked vacant.", confirmLabel: "End tenancy", danger: true });
      if (!ok) return;
      try { const b = new FormData(); b.set("action", "tenancy_end"); b.set("tenancy_id", endId); await api({ body: b }); await loadTenancies(); await loadSummary(); }
      catch (err) { await showAppModal("Real Estate", err.message); }
      return;
    }
    const openId = e.target.closest("[data-tenancy-open]")?.dataset.tenancyOpen;
    if (openId) openTenancyDetail(openId);
  });

  tenancyForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (tenancyFormError) tenancyFormError.hidden = true;
    const unitId = tenancyUnitSel?.value;
    if (!unitId) { if (tenancyFormError) { tenancyFormError.textContent = "Select a vacant unit."; tenancyFormError.hidden = false; } return; }
    if (tenancyFormSubmit) tenancyFormSubmit.disabled = true;
    try {
      let customerId = tenancyTenantSel.value;
      if (customerId === "__new__") {
        const nm = newTenantName.value.trim();
        if (!nm) throw new Error("Enter the new tenant's name.");
        const nb = new FormData(); nb.set("action", "tenant_quick_create"); nb.set("full_name", nm); nb.set("phone", newTenantPhone.value.trim());
        const nd = await api({ body: nb });
        customerId = String(nd.customer.id);
      }
      if (!customerId) throw new Error("Select a tenant.");
      const b = new FormData();
      b.set("action", "tenancy_create");
      b.set("unit_id", unitId);
      b.set("customer_id", customerId);
      b.set("start_date", tenancyForm.elements.start_date.value || todayStr());
      b.set("deposit", String(num(tenancyForm.elements.deposit.value)));
      b.set("notes", tenancyForm.elements.notes.value.trim());
      await api({ body: b });
      if (tenancyFormModal) tenancyFormModal.hidden = true;
      await loadTenancies(); await loadSummary();
    } catch (err) { if (tenancyFormError) { tenancyFormError.textContent = err.message; tenancyFormError.hidden = false; } }
    finally { if (tenancyFormSubmit) tenancyFormSubmit.disabled = false; }
  });

  // ======================= RENT =======================
  const rentWorkspace = document.querySelector("[data-rent-workspace]");
  const paymentsList = document.querySelector("[data-payments-list]");
  const paymentsEmpty = document.querySelector("[data-payments-empty]");
  const rentFormModal = document.querySelector("[data-rent-form-modal]");
  const rentForm = document.querySelector("[data-rent-form]");
  const rentTenancyWrap = document.querySelector("[data-rent-tenancy-select]");
  const rentTenancySel = document.querySelector("[data-rent-tenancy]"); // hidden value input
  const rentAccountWrap = document.querySelector("[data-rent-account-select]");
  const rentAccountSel = document.querySelector("[data-rent-account]"); // hidden value input
  const rentPeriodWrap = document.querySelector("[data-rent-period-wrap]");
  const rentPeriodDisplay = document.querySelector("[data-rent-period-display]");
  const rentPeriodPop = document.querySelector("[data-rent-period-pop]");
  const rentPeriodFrom = document.querySelector("[data-rent-period-from]");
  const rentPeriodTo = document.querySelector("[data-rent-period-to]");
  const rentPeriodHint = document.querySelector("[data-rent-period-hint]");
  const rentAmountInput = document.querySelector("[data-rent-amount]");
  const rentFormError = document.querySelector("[data-rent-form-error]");
  const rentFormSubmit = document.querySelector("[data-rent-form-submit]");
  let activeTenanciesCache = [];

  // Whole months + leftover days between two dates (b >= a).
  const diffMonthsDays = (a, b) => {
    let months = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
    const anchor = new Date(a); anchor.setMonth(anchor.getMonth() + months);
    if (anchor > b) { months--; anchor.setMonth(anchor.getMonth() - 1); }
    const days = Math.round((b - anchor) / 86400000);
    return { months, days };
  };
  const spanText = (months, days) => {
    const parts = [];
    if (months) parts.push(`${months} month${months !== 1 ? "s" : ""}`);
    if (days) parts.push(`${days} day${days !== 1 ? "s" : ""}`);
    return parts.join(" ");
  };
  // Remaining/overdue time from a "paid until" date, by charge frequency.
  const remainingInfo = (paidUntil, chargeType) => {
    if (!paidUntil) return { text: "Not paid", cls: "out-of-stock" };
    const end = parseDate(paidUntil);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    if (!end) return { text: "—", cls: "" };
    if (end >= today) {
      if (chargeType === "daily") {
        const d = Math.round((end - today) / 86400000);
        return { text: d <= 0 ? "Due today" : `${d} day${d !== 1 ? "s" : ""} left`, cls: d <= 3 ? "low-stock" : "in-stock" };
      }
      const { months, days } = diffMonthsDays(today, end);
      const txt = spanText(months, days);
      return { text: txt ? `${txt} left` : "Due today", cls: (months === 0 && days <= 3) ? "low-stock" : "in-stock" };
    }
    if (chargeType === "daily") {
      const d = Math.round((today - end) / 86400000);
      return { text: `Overdue ${d} day${d !== 1 ? "s" : ""}`, cls: "out-of-stock" };
    }
    const { months, days } = diffMonthsDays(end, today);
    return { text: `Overdue by ${spanText(months, days) || "0 days"}`, cls: "out-of-stock" };
  };

  const renderRentOverview = (list) => {
    if (!list.length) { paymentsList?.replaceChildren(); if (paymentsEmpty) paymentsEmpty.hidden = false; return; }
    if (paymentsEmpty) paymentsEmpty.hidden = true;
    paymentsList.innerHTML = list.map((r) => {
      const rem = r.status === "active" ? remainingInfo(r.paid_until, r.charge_type) : { text: "Ended", cls: "out-of-stock" };
      const lastLine = r.last_paid_date
        ? `Last paid ${formatDate(r.last_paid_date)}${r.last_amount != null ? " · " + fmt(r.last_amount) : ""}`
        : "No payments yet";
      const paidUntilLine = r.paid_until ? ` · paid to ${formatDate(r.paid_until)}` : "";
      return `
        <button type="button" class="settings-list-row" data-rent-open="${r.tenancy_id}" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;cursor:pointer;text-align:left;">
          <div style="min-width:0;">
            <div style="font-weight:800;color:var(--color-navy);font-size:0.92rem;">${esc(r.tenant_name)}</div>
            <div style="font-size:0.78rem;color:var(--color-muted);">${esc(r.property_name)} • ${esc(r.unit_name)}</div>
            <div style="font-size:0.75rem;color:var(--color-muted);">${esc(lastLine)}${paidUntilLine}</div>
          </div>
          <span class="badge-stock ${rem.cls}" style="white-space:nowrap;flex:0 0 auto;">${esc(rem.text)}</span>
        </button>`;
    }).join("");
  };
  const loadRentOverview = async () => { try { const d = await api({ query: "?resource=rent_overview" }); renderRentOverview(d.tenants || []); } catch {} };

  // --- Period (contract) date-range + amount two-way helpers ---
  const parseDate = (s) => { if (!s) return null; const d = new Date(`${s}T00:00:00`); return isNaN(d.getTime()) ? null : d; };
  const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
  const addMonths = (d, n) => { const r = new Date(d); const day = r.getDate(); r.setMonth(r.getMonth() + n); if (r.getDate() < day) r.setDate(0); return r; };
  const addDays = (d, n) => { const r = new Date(d); r.setDate(r.getDate() + n); return r; };
  const periodsBetween = (from, to, ct) => {
    if (!from || !to || to <= from) return 0;
    if (ct === "daily") return Math.max(1, Math.round((to - from) / 86400000));
    return Math.max(1, (to.getFullYear() - from.getFullYear()) * 12 + (to.getMonth() - from.getMonth()));
  };
  const endFromPeriods = (from, n, ct) => (ct === "daily" ? addDays(from, n) : addMonths(from, n));
  const currentRentTenancy = () => activeTenanciesCache.find((x) => String(x.id) === String(rentTenancySel?.value));
  const periodUnit = (ct, n) => `${n} ${ct === "daily" ? "day" : "month"}${n > 1 ? "s" : ""}`;
  let rentSyncing = false;

  const updatePeriodDisplay = () => {
    if (!rentPeriodDisplay) return;
    const f = rentPeriodFrom?.value;
    const t = rentPeriodTo?.value;
    rentPeriodDisplay.value = f && t ? `${f}  →  ${t}` : (f ? `${f}  →  …` : "");
  };
  const setPeriodHint = (ten, n, amt) => {
    if (rentPeriodHint) rentPeriodHint.textContent = (ten && n > 0) ? `${periodUnit(ten.charge_type, n)} × ${fmt(ten.rate)} = ${fmt(amt)}` : "";
  };
  // Date range changed -> adjust the amount.
  const recalcFromRange = () => {
    if (rentSyncing) return;
    const ten = currentRentTenancy();
    updatePeriodDisplay();
    if (!ten) return;
    const n = periodsBetween(parseDate(rentPeriodFrom.value), parseDate(rentPeriodTo.value), ten.charge_type);
    if (n > 0) {
      const amt = Math.round(ten.rate * n * 100) / 100;
      rentSyncing = true;
      if (rentAmountInput) rentAmountInput.value = groupThousands(String(amt));
      rentSyncing = false;
      setPeriodHint(ten, n, amt);
    } else {
      setPeriodHint(null, 0, 0);
    }
  };
  // Amount changed -> adjust the contract end date.
  const recalcFromAmount = () => {
    if (rentSyncing) return;
    const ten = currentRentTenancy();
    if (!ten || !(ten.rate > 0)) return;
    const amt = num(rentAmountInput.value);
    const n = Math.max(1, Math.round(amt / ten.rate));
    let from = parseDate(rentPeriodFrom.value);
    if (!from) { from = new Date(); rentPeriodFrom.value = isoDate(from); }
    rentSyncing = true;
    rentPeriodTo.value = isoDate(endFromPeriods(from, n, ten.charge_type));
    rentSyncing = false;
    updatePeriodDisplay();
    setPeriodHint(ten, n, amt);
  };
  const onRentTenancyChange = () => {
    const ten = currentRentTenancy();
    const from = new Date();
    if (rentPeriodFrom) rentPeriodFrom.value = isoDate(from);
    if (!ten) { if (rentPeriodTo) rentPeriodTo.value = ""; updatePeriodDisplay(); setPeriodHint(null, 0, 0); return; }
    rentSyncing = true;
    if (rentPeriodTo) rentPeriodTo.value = isoDate(endFromPeriods(from, 1, ten.charge_type));
    if (rentAmountInput) rentAmountInput.value = groupThousands(String(ten.rate));
    rentSyncing = false;
    updatePeriodDisplay();
    setPeriodHint(ten, 1, ten.rate);
  };

  const openRentForm = async (preselectTenancyId = "") => {
    rentForm.reset();
    if (rentFormError) rentFormError.hidden = true;
    rentForm.elements.paid_date.value = todayStr();
    if (rentPeriodFrom) rentPeriodFrom.value = "";
    if (rentPeriodTo) rentPeriodTo.value = "";
    if (rentPeriodPop) rentPeriodPop.hidden = true;
    updatePeriodDisplay();
    if (rentPeriodHint) rentPeriodHint.textContent = "";
    try {
      const [tenD, accRes] = await Promise.all([
        api({ query: "?resource=tenancies" }),
        fetch(`${getBasePath()}api/accounts.php`).then((r) => r.json()).catch(() => ({ accounts: [] })),
      ]);
      activeTenanciesCache = (tenD.tenancies || []).filter((t) => t.status === "active");
      const tenancyOptions = activeTenanciesCache.map((t) => ({
        value: String(t.id),
        label: `${t.tenant_name} — ${t.property_name}/${t.unit_name} (${fmt(t.rate)}${chargeLabel(t.charge_type)})`,
      }));
      const hasPreselect = preselectTenancyId && activeTenanciesCache.some((t) => String(t.id) === String(preselectTenancyId));
      const firstTenancy = hasPreselect ? String(preselectTenancyId) : (activeTenanciesCache[0] ? String(activeTenanciesCache[0].id) : "");
      setSearchSelectOptions(rentTenancyWrap, tenancyOptions, activeTenanciesCache.length ? "Choose a tenancy..." : "No active tenancies", firstTenancy);

      const accounts = accRes.accounts || [];
      const accountOptions = accounts.map((a) => ({ value: String(a.id), label: `${a.name} (${a.type_label})` }));
      const defaultAcc = accounts.find((a) => a.is_default);
      setSearchSelectOptions(rentAccountWrap, accountOptions, "Default account", defaultAcc ? String(defaultAcc.id) : "");

      onRentTenancyChange(); // prefill period + amount for the first tenancy
    } catch { /* ignore */ }
    if (rentFormModal) rentFormModal.hidden = false;
  };

  rentTenancySel?.addEventListener("change", onRentTenancyChange);
  rentPeriodFrom?.addEventListener("change", recalcFromRange);
  rentPeriodTo?.addEventListener("change", recalcFromRange);
  rentAmountInput?.addEventListener("input", () => {
    rentAmountInput.value = groupThousands(rentAmountInput.value);
    recalcFromAmount();
  });
  rentPeriodDisplay?.addEventListener("click", () => { if (rentPeriodPop) rentPeriodPop.hidden = !rentPeriodPop.hidden; });
  document.addEventListener("click", (e) => {
    if (rentPeriodWrap && rentPeriodPop && !rentPeriodPop.hidden && !rentPeriodWrap.contains(e.target)) rentPeriodPop.hidden = true;
  });

  document.querySelectorAll("[data-open-rent-workspace]").forEach((b) => b.addEventListener("click", () => openWorkspace(rentWorkspace, loadRentOverview)));
  document.querySelector("[data-rent-workspace-close]")?.addEventListener("click", () => closeWorkspace(rentWorkspace));
  document.querySelectorAll("[data-open-record-rent]").forEach((b) => b.addEventListener("click", () => openRentForm()));
  document.querySelector("[data-rent-form-close]")?.addEventListener("click", () => { if (rentFormModal) rentFormModal.hidden = true; });

  paymentsList?.addEventListener("click", (e) => {
    const id = e.target.closest("[data-rent-open]")?.dataset.rentOpen;
    if (id) openTenancyDetail(id);
  });

  rentForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (rentFormError) rentFormError.hidden = true;
    const tenancyId = rentTenancySel?.value;
    const amount = num(rentAmountInput ? rentAmountInput.value : rentForm.elements.amount.value);
    if (!tenancyId) { if (rentFormError) { rentFormError.textContent = "Select a tenancy."; rentFormError.hidden = false; } return; }
    if (amount <= 0) { if (rentFormError) { rentFormError.textContent = "Enter an amount greater than zero."; rentFormError.hidden = false; } return; }
    const periodFrom = rentPeriodFrom?.value || "";
    const periodTo = rentPeriodTo?.value || "";
    const periodLabel = (periodFrom && periodTo) ? `${periodFrom} → ${periodTo}` : periodFrom;
    if (rentFormSubmit) rentFormSubmit.disabled = true;
    try {
      const b = new FormData();
      b.set("action", "rent_payment_create");
      b.set("tenancy_id", tenancyId);
      b.set("amount", String(amount));
      b.set("period_label", periodLabel);
      b.set("period_start", periodFrom);
      b.set("period_end", periodTo);
      b.set("paid_date", rentForm.elements.paid_date.value || todayStr());
      if (rentAccountSel?.value) b.set("account_id", rentAccountSel.value);
      b.set("notes", rentForm.elements.notes.value.trim());
      await api({ body: b });
      if (rentFormModal) rentFormModal.hidden = true;
      await loadRentOverview(); await loadSummary();
      if (tenancyDetailWorkspace && !tenancyDetailWorkspace.hidden) await openTenancyDetail(tenancyId);
    } catch (err) { if (rentFormError) { rentFormError.textContent = err.message; rentFormError.hidden = false; } }
    finally { if (rentFormSubmit) rentFormSubmit.disabled = false; }
  });

  // ======================= TENANCY DETAIL (shared) =======================
  const tenancyDetailWorkspace = document.querySelector("[data-tenancy-detail-workspace]");
  const tdTitle = document.querySelector("[data-tenancy-detail-title]");
  const tdTenant = document.querySelector("[data-td-tenant]");
  const tdStatus = document.querySelector("[data-td-status]");
  const tdMeta = document.querySelector("[data-td-meta]");
  const tdRate = document.querySelector("[data-td-rate]");
  const tdTotal = document.querySelector("[data-td-total]");
  const tdPaidUntil = document.querySelector("[data-td-paiduntil]");
  const tdRemaining = document.querySelector("[data-td-remaining]");
  const tdRecordBtn = document.querySelector("[data-td-record]");
  const tdEndBtn = document.querySelector("[data-td-end]");
  const tdPayments = document.querySelector("[data-td-payments]");
  const tdPaymentsEmpty = document.querySelector("[data-td-payments-empty]");
  let currentTenancyDetail = null;

  const renderTenancyDetail = (t) => {
    currentTenancyDetail = t;
    const active = t.status === "active";
    if (tdTitle) tdTitle.textContent = t.tenant_name;
    if (tdTenant) tdTenant.textContent = t.tenant_name;
    if (tdStatus) { tdStatus.textContent = active ? "Active" : "Ended"; tdStatus.className = `badge-stock ${active ? "in-stock" : "out-of-stock"}`; }
    if (tdMeta) tdMeta.textContent = [`${t.property_name} • ${t.unit_name}`, t.tenant_phone, `from ${formatDate(t.start_date)}`].filter(Boolean).join(" • ");
    if (tdRate) tdRate.textContent = `${fmt(t.rate)}${chargeLabel(t.charge_type)}`;
    if (tdTotal) tdTotal.textContent = fmt(t.total_paid || 0);
    if (tdPaidUntil) tdPaidUntil.textContent = t.paid_until ? formatDate(t.paid_until) : "—";
    if (tdRemaining) {
      const rem = active ? remainingInfo(t.paid_until, t.charge_type) : { text: "Ended" };
      tdRemaining.textContent = rem.text;
    }
    if (tdRecordBtn) tdRecordBtn.hidden = !active;
    if (tdEndBtn) tdEndBtn.hidden = !active;

    const list = t.payments || [];
    if (!list.length) { tdPayments?.replaceChildren(); if (tdPaymentsEmpty) tdPaymentsEmpty.hidden = false; return; }
    if (tdPaymentsEmpty) tdPaymentsEmpty.hidden = true;
    if (tdPayments) {
      tdPayments.innerHTML = list.map((pmt) => {
        const period = (pmt.period_start && pmt.period_end)
          ? `${formatDate(pmt.period_start)} → ${formatDate(pmt.period_end)}`
          : (pmt.period_label || "");
        const sub = [period, `paid ${formatDate(pmt.paid_date)}`, pmt.account_name].filter(Boolean).map(esc).join(" • ");
        return `
          <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:10px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
            <div style="min-width:0;text-align:left;">
              <div style="font-weight:700;color:var(--color-navy);font-size:0.88rem;">${fmt(pmt.amount)}</div>
              <div style="font-size:0.75rem;color:var(--color-muted);">${sub}</div>
            </div>
            <button type="button" class="btn btn-danger-outline btn-sm" data-td-payment-delete="${pmt.id}" aria-label="Delete">${svgMarkup("close", { size: 14 })}</button>
          </div>`;
      }).join("");
    }
  };

  const openTenancyDetail = async (id) => {
    try {
      const d = await api({ query: `?resource=tenancy&id=${encodeURIComponent(id)}` });
      renderTenancyDetail(d.tenancy);
      if (tenancyDetailWorkspace) tenancyDetailWorkspace.hidden = false;
    } catch (err) { await showAppModal("Real Estate", err.message); }
  };

  document.querySelector("[data-tenancy-detail-close]")?.addEventListener("click", () => {
    closeWorkspace(tenancyDetailWorkspace);
    loadRentOverview(); loadTenancies(); loadSummary();
  });
  tdRecordBtn?.addEventListener("click", () => { if (currentTenancyDetail) openRentForm(String(currentTenancyDetail.id)); });
  tdEndBtn?.addEventListener("click", async () => {
    if (!currentTenancyDetail) return;
    const ok = await showConfirmModal({ title: "End tenancy", message: "End this tenancy? The unit will be marked vacant.", confirmLabel: "End tenancy", danger: true });
    if (!ok) return;
    try { const b = new FormData(); b.set("action", "tenancy_end"); b.set("tenancy_id", String(currentTenancyDetail.id)); await api({ body: b }); await openTenancyDetail(currentTenancyDetail.id); await loadSummary(); }
    catch (err) { await showAppModal("Real Estate", err.message); }
  });
  tdPayments?.addEventListener("click", async (e) => {
    const delId = e.target.closest("[data-td-payment-delete]")?.dataset.tdPaymentDelete;
    if (!delId || !currentTenancyDetail) return;
    const ok = await showConfirmModal({ title: "Delete rent payment", message: "Delete this payment? It will be removed from the account balance too.", confirmLabel: "Delete", danger: true });
    if (!ok) return;
    try { const b = new FormData(); b.set("action", "rent_payment_delete"); b.set("payment_id", delId); await api({ body: b }); await openTenancyDetail(currentTenancyDetail.id); await loadSummary(); }
    catch (err) { await showAppModal("Real Estate", err.message); }
  });

  // ======================= STAFF =======================
  const staffWorkspace = document.querySelector("[data-staff-workspace]");
  const staffListEl = document.querySelector("[data-staff-list]");
  const staffEmpty = document.querySelector("[data-staff-empty]");
  const staffFormModal = document.querySelector("[data-staff-form-modal]");
  const staffForm = document.querySelector("[data-staff-form]");
  const staffFormTitle = document.querySelector("[data-staff-form-title]");
  const staffFormId = document.querySelector("[data-staff-form-id]");
  const staffRoleWrap = document.querySelector("[data-staff-role-select]");
  const staffRoleSel = document.querySelector("[data-staff-role]"); // hidden value input
  const staffPropertyWrap = document.querySelector("[data-staff-property-select]");
  const staffPropertySel = document.querySelector("[data-staff-property]"); // hidden value input
  const staffFormError = document.querySelector("[data-staff-form-error]");
  const staffFormSubmit = document.querySelector("[data-staff-form-submit]");
  let staffCache = [];

  const renderStaff = (list) => {
    staffCache = list;
    if (!list.length) { staffListEl?.replaceChildren(); if (staffEmpty) staffEmpty.hidden = false; return; }
    if (staffEmpty) staffEmpty.hidden = true;
    staffListEl.innerHTML = list.map((s) => `
      <div class="settings-list-row" style="display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;padding:12px 14px;background:#fff;border:1px solid var(--color-line);border-radius:10px;">
        <div style="min-width:0;text-align:left;">
          <div style="font-weight:800;color:var(--color-navy);font-size:0.92rem;text-transform:capitalize;">${esc(s.name)}</div>
          <div style="font-size:0.78rem;color:var(--color-muted);text-transform:capitalize;">${esc(s.role)}${s.phone ? " • " + esc(s.phone) : ""}${s.property_name ? " • " + esc(s.property_name) : ""}</div>
        </div>
        <div style="display:flex;gap:6px;flex:0 0 auto;">
          <button type="button" class="btn btn-outline btn-sm" data-staff-edit="${s.id}">Edit</button>
          <button type="button" class="btn btn-danger-outline btn-sm" data-staff-delete="${s.id}">Delete</button>
        </div>
      </div>`).join("");
  };
  const loadStaff = async () => { try { const d = await api({ query: "?resource=staff" }); renderStaff(d.staff || []); } catch {} };

  const openStaffForm = async (s = null) => {
    staffForm.reset();
    if (staffFormError) staffFormError.hidden = true;
    if (staffFormId) staffFormId.value = s ? String(s.id) : "";
    if (staffFormTitle) staffFormTitle.textContent = s ? "Edit Staff" : "Add Staff";
    if (s) {
      staffForm.elements.name.value = s.name || "";
      staffForm.elements.phone.value = s.phone || "";
      staffForm.elements.notes.value = s.notes || "";
    }
    // Role select (static options) — sync the styled widget to the current value.
    const roleVal = s ? (s.role || "caretaker") : "caretaker";
    if (staffRoleSel) {
      staffRoleSel.value = roleVal;
      staffRoleSel.setAttribute("value", roleVal);
      staffRoleSel.dispatchEvent(new Event("change", { bubbles: true }));
    }
    // Property select — populate from the live property list.
    try {
      const d = await api({ query: "?resource=properties" });
      const propOptions = [{ value: "", label: "— None —" }].concat((d.properties || []).map((p) => ({ value: String(p.id), label: p.name })));
      setSearchSelectOptions(staffPropertyWrap, propOptions, "— None —", s && s.property_id ? String(s.property_id) : "");
    } catch { /* ignore */ }
    if (staffFormModal) staffFormModal.hidden = false;
  };

  document.querySelectorAll("[data-open-staff-workspace]").forEach((b) => b.addEventListener("click", () => openWorkspace(staffWorkspace, loadStaff)));
  document.querySelector("[data-staff-workspace-close]")?.addEventListener("click", () => closeWorkspace(staffWorkspace));
  document.querySelectorAll("[data-open-create-staff]").forEach((b) => b.addEventListener("click", () => openStaffForm(null)));
  document.querySelector("[data-staff-form-close]")?.addEventListener("click", () => { if (staffFormModal) staffFormModal.hidden = true; });

  staffListEl?.addEventListener("click", async (e) => {
    const editId = e.target.closest("[data-staff-edit]")?.dataset.staffEdit;
    const delId = e.target.closest("[data-staff-delete]")?.dataset.staffDelete;
    if (editId) { const s = staffCache.find((x) => String(x.id) === String(editId)); if (s) openStaffForm(s); return; }
    if (delId) {
      const s = staffCache.find((x) => String(x.id) === String(delId));
      const ok = await showConfirmModal({ title: "Remove staff", message: `Remove "${s?.name || "this staff member"}"?`, confirmLabel: "Remove", danger: true });
      if (!ok) return;
      try { const b = new FormData(); b.set("action", "staff_delete"); b.set("staff_id", delId); await api({ body: b }); await loadStaff(); }
      catch (err) { await showAppModal("Real Estate", err.message); }
    }
  });

  staffForm?.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (staffFormError) staffFormError.hidden = true;
    const name = staffForm.elements.name.value.trim();
    if (!name) { if (staffFormError) { staffFormError.textContent = "Staff name is required."; staffFormError.hidden = false; } return; }
    if (staffFormSubmit) staffFormSubmit.disabled = true;
    try {
      const id = staffFormId.value;
      const b = new FormData();
      b.set("action", id ? "staff_update" : "staff_create");
      if (id) b.set("staff_id", id);
      b.set("name", name);
      b.set("phone", staffForm.elements.phone.value.trim());
      b.set("role", staffForm.elements.role.value);
      b.set("property_id", staffPropertySel?.value || "0");
      b.set("notes", staffForm.elements.notes.value.trim());
      await api({ body: b });
      if (staffFormModal) staffFormModal.hidden = true;
      await loadStaff();
    } catch (err) { if (staffFormError) { staffFormError.textContent = err.message; staffFormError.hidden = false; } }
    finally { if (staffFormSubmit) staffFormSubmit.disabled = false; }
  });

  // ---- Init ----
  loadSummary();
};

const setupSupportModal = () => {
  const modal = document.querySelector("[data-support-modal]");
  if (!modal) return;
  const openButtons = document.querySelectorAll("[data-open-support]");
  const closeButtons = document.querySelectorAll("[data-support-modal-close]");

  openButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      modal.hidden = false;
    });
  });

  closeButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      modal.hidden = true;
    });
  });

  modal.addEventListener("click", (e) => {
    if (e.target === modal) modal.hidden = true;
  });
};

const setupDashboardPage = () => {
  const page = document.querySelector("[data-dashboard-page]");
  if (!page) return;

  const fmt = (amount, cur = "TZS") => {
    const val = Number(amount) || 0;
    return `${cur} ${val.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`;
  };
  const escText = (value) => String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  }[char]));
  const setText = (selector, value) => {
    const el = document.querySelector(selector);
    if (el) el.textContent = value;
  };
  const methodLabel = (method) => {
    const key = String(method || "").toLowerCase();
    if (key === "mobile" || key === "mobile_money") return "Lipa kwa simu";
    if (key === "bank" || key === "card") return "Bank/Card";
    if (key === "credit") return "Credit";
    return "Cash";
  };
  const shortTime = (value) => {
    if (!value) return "";
    const d = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(d.getTime())) return "";
    return d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  };

  const renderSales = (sales = []) => {
    const list = document.querySelector("[data-dashboard-sales-list]");
    if (!list) return;
    const recent = sales.slice(0, 4);
    if (!recent.length) {
      list.innerHTML = '<p class="dashboard-empty">No recent POS sales yet.</p>';
      return;
    }
    list.innerHTML = recent.map((sale) => `
      <div class="dashboard-list-row">
        <div>
          <strong>${escText(sale.receipt_number || "POS Sale")}</strong>
          <span>${escText(sale.customer_name || "Walk-in Customer")} &bull; ${methodLabel(sale.payment_method)}${shortTime(sale.created_at) ? ` &bull; ${shortTime(sale.created_at)}` : ""}</span>
        </div>
        <b>${fmt(sale.total_amount)}</b>
      </div>
    `).join("");
  };

  const renderStock = (items = []) => {
    const list = document.querySelector("[data-dashboard-stock-list]");
    if (!list) return;
    const low = items.filter((item) => item.is_low_stock).slice(0, 4);
    if (!low.length) {
      list.innerHTML = '<p class="dashboard-empty">No low-stock products.</p>';
      return;
    }
    list.innerHTML = low.map((item) => {
      const max = Math.max(Number(item.min_stock_alert) || 1, Number(item.current_stock) || 0, 1);
      const pct = Math.max(4, Math.min(100, ((Number(item.current_stock) || 0) / max) * 100));
      return `
        <div class="dashboard-stock-row">
          <div>
            <strong>${escText(item.name)}</strong>
            <span>${Number(item.current_stock) || 0} ${escText(item.unit || "pcs")} left &bull; reorder at ${Number(item.min_stock_alert) || 0}</span>
          </div>
          <i style="--stock-width:${pct}%"></i>
        </div>
      `;
    }).join("");
  };

  const loadDashboard = async () => {
    try {
      const [invoiceRes, posRes, stockRes, accountRes] = await Promise.allSettled([
        fetch(`${getBasePath()}api/sales.php`),
        fetch(`${getBasePath()}api/sales.php?history=pos`),
        fetch(`${getBasePath()}api/items.php`),
        fetch(`${getBasePath()}api/accounts.php`),
      ]);

      if (invoiceRes.status === "fulfilled" && invoiceRes.value.ok) {
        const data = await invoiceRes.value.json();
        const stats = data.stats || {};
        setText("[data-dashboard-sales-today]", fmt(stats.sales_today));
        setText("[data-dashboard-sales-month]", fmt(stats.sales_month));
        setText("[data-dashboard-sales-count]", `${stats.txn_today || 0} sales recorded`);
        setText("[data-dashboard-amount-due]", fmt(stats.amount_due));
        setText("[data-dashboard-overdue]", `${stats.overdue || 0} overdue invoices`);
      }

      if (posRes.status === "fulfilled" && posRes.value.ok) {
        const data = await posRes.value.json();
        renderSales(data.sales || []);
      }

      if (stockRes.status === "fulfilled" && stockRes.value.ok) {
        const data = await stockRes.value.json();
        setText("[data-dashboard-low-stock]", String(data.stats?.low_stock || 0));
        setText("[data-dashboard-stock-note]", `${data.stats?.total || 0} products tracked`);
        renderStock(data.items || []);
      }

      if (accountRes.status === "fulfilled" && accountRes.value.ok) {
        const data = await accountRes.value.json();
        const summary = data.summary || {};
        setText("[data-dashboard-cash]", fmt(summary.cash));
        setText("[data-dashboard-bank]", fmt(summary.bank));
        setText("[data-dashboard-mobile]", fmt(summary.mobile));
        setText("[data-dashboard-net]", fmt(summary.net));
      }

      fitAmounts(document);
    } catch {
      /* Keep the static empty states when offline or unauthenticated. */
    }
  };

  loadDashboard();
};

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-lang]").forEach((button) => {
    button.addEventListener("click", () => setLanguage(button.dataset.lang));
  });

  setLanguage(getSavedLanguage()).catch(() => setLanguage(DEFAULT_LANGUAGE));
  setupBottomSheet();
  setupQuickPanel();
  setupSupportModal();
  setupSearchSelects();
  setupPasswordTools();
  setupLoginFlow();
  setupLocationSelects().finally(setupRegisterFlow);
  setupSettingsPage();
  setupCustomersPage();
  setupSuppliersPage();
  setupCompanyUsersPage();
  setupStockPage();
  setupSalesPage();
  setupBankPage();
  setupRealEstatePage();
  setupDashboardPage();
  initAmountAutosize();
  updateConnectionStatus();
  registerServiceWorker();
});

window.addEventListener("online", updateConnectionStatus);
window.addEventListener("offline", updateConnectionStatus);
