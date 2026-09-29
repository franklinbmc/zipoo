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
  actions.dataset.appModalExtra = "";

  const cancelButton = document.createElement("button");
  cancelButton.type = "button";
  cancelButton.className = "btn btn-secondary";
  cancelButton.textContent = cancelLabel;

  closeButton.textContent = confirmLabel;
  closeButton.className = danger ? "btn btn-danger full" : "btn btn-primary full";
  actions.append(cancelButton, closeButton);
  dialog.append(actions);

  const finish = (value) => {
    modal.hidden = true;
    closeButton.removeEventListener("click", confirm);
    cancelButton.removeEventListener("click", cancel);
    actions.remove();
    closeButton.textContent = "OK";
    closeButton.className = "btn btn-primary full";
    resolve(value);
  };
  const confirm = () => finish(true);
  const cancel = () => finish(false);

  titleEl.textContent = title;
  messageEl.textContent = message;
  modal.hidden = false;
  closeButton.addEventListener("click", confirm);
  cancelButton.addEventListener("click", cancel);
  cancelButton.focus();
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

  if (!panel || !openButton || !closeButton) {
    return;
  }

  const openPanel = () => {
    panel.hidden = false;
    openButton.setAttribute("aria-expanded", "true");
    closeButton.focus();
  };

  const closePanel = () => {
    panel.hidden = true;
    openButton.setAttribute("aria-expanded", "false");
    closeCompanyMenu();
    openButton.focus();
  };

  const toggleCompanyMenu = () => {
    const { knownBusinesses } = getStoredBusinessState();
    if (knownBusinesses.length <= 1 || !companySelectWrap) return;
    const willOpen = companySelectWrap.hidden;
    companySelectWrap.hidden = !willOpen;
    companySwitcher?.classList.toggle("is-open", willOpen);
    companyCurrent?.setAttribute("aria-expanded", willOpen ? "true" : "false");
  };

  openButton.addEventListener("click", openPanel);
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

      form.append(idInput, nameRow, typeRow, regionRow, districtRow, planRow, statusRow, errorMsg, actions);
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

  render();
  refreshAccount().then(render);
  refreshStoredBusinesses().then(() => {
    render();
    renderBusinessManager();
  });
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

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("[data-lang]").forEach((button) => {
    button.addEventListener("click", () => setLanguage(button.dataset.lang));
  });

  setLanguage(getSavedLanguage()).catch(() => setLanguage(DEFAULT_LANGUAGE));
  setupBottomSheet();
  setupQuickPanel();
  setupSearchSelects();
  setupPasswordTools();
  setupLoginFlow();
  setupLocationSelects().finally(setupRegisterFlow);
  setupSettingsPage();
  updateConnectionStatus();
  registerServiceWorker();
});

window.addEventListener("online", updateConnectionStatus);
window.addEventListener("offline", updateConnectionStatus);
