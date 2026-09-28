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
  modal.hidden = false;
  closeButton.addEventListener("click", close);
  closeButton.focus();
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
  const companySelectWrap = document.querySelector("[data-company-select-wrap]");
  const companySelect = document.querySelector("[data-company-select]");

  const syncQuickBusinessUi = () => {
    const { knownBusinesses, selectedBusiness } = getStoredBusinessState();
    if (currentBusiness) {
      currentBusiness.textContent = selectedBusiness?.business_name || "Business";
    }
    if (companySelect && companySelectWrap) {
      companySelect.replaceChildren(...knownBusinesses.map((business) => {
        const option = document.createElement("option");
        option.value = String(business.id);
        option.textContent = business.business_name || `Business ${business.id}`;
        option.selected = String(business.id) === String(selectedBusiness?.id || "");
        return option;
      }));
      companySelectWrap.hidden = knownBusinesses.length <= 1;
    }
  };

  syncQuickBusinessUi();
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
    openButton.focus();
  };

  openButton.addEventListener("click", openPanel);
  closeButton.addEventListener("click", closePanel);
  panel.addEventListener("click", (event) => {
    if (event.target === panel) {
      closePanel();
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !panel.hidden) {
      closePanel();
    }
  });

  companySelect?.addEventListener("change", async () => {
    const previousValue = String(getStoredBusinessState().selectedBusiness?.id || "");
    companySelect.disabled = true;
    try {
      await switchStoredBusiness(companySelect.value);
      await refreshStoredBusinesses();
      syncQuickBusinessUi();
      closePanel();
    } catch (error) {
      companySelect.value = previousValue;
      await showAppModal("Unable to switch business", error.message || "Please try again.");
    } finally {
      companySelect.disabled = false;
    }
  });

  logoutButton?.addEventListener("click", () => {
    localStorage.removeItem("zipoo.isLoggedIn");
    window.location.href = `${getBasePath()}login`;
  });
};

const setupSettingsPage = () => {
  const page = document.querySelector("[data-settings-page]");
  if (!page) {
    return;
  }

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
  const businessCreateForm = document.querySelector("[data-business-manager-create-form]");
  const businessRegion = document.querySelector("[data-business-region]");
  const businessDistrict = document.querySelector("[data-business-district]");
  const businessError = document.querySelector("[data-business-manager-error]");
  let editField = "";
  let editStep = "request";
  let regions = [];

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
    try {
      const response = await fetch(`${getBasePath()}api/account.php`);
      const payload = await response.json();
      if (response.ok && payload.ok && payload.user) {
        storeAccount(payload.user);
      }
    } catch {
      // Existing local account details are enough for offline rendering.
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
      const row = document.createElement("div");
      row.className = `settings-list-row business-settings-row${isCurrent ? " current" : ""}`;
      const name = document.createElement("span");
      const value = document.createElement("strong");
      name.textContent = business.business_name || `Business ${business.id}`;
      value.textContent = businessDetailText(business, isCurrent);
      row.append(name, value);
      return row;
    }));
  };

  const renderBusinessManager = () => {
    const { knownBusinesses, selectedBusiness } = getStoredBusinessState();
    businessManagerList?.replaceChildren(...knownBusinesses.map((business) => {
      const isCurrent = String(business.id) === String(selectedBusiness?.id || "");
      const row = document.createElement("div");
      row.className = `settings-list-row business-manager-row${isCurrent ? " current" : ""}`;

      const name = document.createElement("span");
      const meta = document.createElement("strong");
      const actions = document.createElement("div");
      name.textContent = business.business_name || `Business ${business.id}`;
      meta.textContent = businessDetailText(business, isCurrent);
      actions.className = "business-manager-actions";

      if (!isCurrent) {
        const setDefault = document.createElement("button");
        setDefault.type = "button";
        setDefault.textContent = "Set default";
        setDefault.addEventListener("click", async () => {
          setDefault.disabled = true;
          try {
            await switchStoredBusiness(String(business.id));
            render();
            renderBusinessManager();
            await showAppModal("Business changed", `${business.business_name || "Business"} is now serving.`);
          } catch (error) {
            await showAppModal("Unable to switch business", error.message || "Please try again.");
          } finally {
            setDefault.disabled = false;
          }
        });

        const deleteButton = document.createElement("button");
        deleteButton.type = "button";
        deleteButton.className = "danger";
        deleteButton.textContent = "Delete";
        deleteButton.addEventListener("click", async () => {
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

        actions.append(setDefault, deleteButton);
      }

      row.append(name, meta, actions);
      return row;
    }));
  };

  const render = () => {
    const { user, selectedBusiness } = getStoredBusinessState();
    if (settingsName) settingsName.textContent = user.full_name || "-";
    if (settingsPhone) settingsPhone.textContent = user.phone || "-";
    if (settingsEmail) settingsEmail.textContent = user.email || "-";
    if (settingsCurrentBusiness) settingsCurrentBusiness.textContent = selectedBusiness?.business_name || "Business";
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
    if (!businessRegion || !businessDistrict || regions.length) {
      return;
    }
    try {
      const response = await fetch(`${getBasePath()}api/locations.php`);
      const payload = await response.json();
      regions = Array.isArray(payload.regions) ? payload.regions : [];
      businessRegion.replaceChildren(...regions.map((region) => {
        const option = document.createElement("option");
        option.value = region.value;
        option.textContent = region.label;
        return option;
      }));
      businessRegion.dispatchEvent(new Event("change"));
    } catch {
      regions = [];
    }
  };

  businessRegion?.addEventListener("change", () => {
    const region = regions.find((item) => item.value === businessRegion.value);
    const districts = Array.isArray(region?.districts) ? region.districts : [];
    businessDistrict?.replaceChildren(...districts.map((district) => {
      const option = document.createElement("option");
      option.value = district.value;
      option.textContent = district.label;
      return option;
    }));
  });

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

  businessOpen?.addEventListener("click", async () => {
    if (businessModal) businessModal.hidden = false;
    renderBusinessManager();
    await loadBusinessLocations();
  });

  businessClose?.addEventListener("click", () => {
    if (businessModal) businessModal.hidden = true;
  });

  businessCreateForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (businessError) businessError.hidden = true;
    const submitButton = businessCreateForm.querySelector('button[type="submit"]');
    const body = new FormData(businessCreateForm);
    body.set("action", "create");
    if (submitButton) submitButton.disabled = true;

    try {
      const response = await fetch(`${getBasePath()}api/businesses.php`, { method: "POST", body });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || "Unable to create business.");
      }
      const { knownBusinesses } = getStoredBusinessState();
      const nextBusinesses = Array.isArray(payload.businesses) ? payload.businesses : [...knownBusinesses, payload.business].filter(Boolean);
      localStorage.setItem("zipoo.businesses", JSON.stringify(nextBusinesses));
      if (payload.business?.id) {
        localStorage.setItem("zipoo.currentBusinessId", String(payload.business.id));
      }
      businessCreateForm.reset();
      businessRegion?.dispatchEvent(new Event("change"));
      render();
      renderBusinessManager();
      await showAppModal("Business created", `${payload.business?.business_name || "New business"} is now serving.`);
    } catch (error) {
      if (businessError) {
        businessError.textContent = error.message || "Unable to create business.";
        businessError.hidden = false;
      }
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });

  render();
  refreshAccount().then(render);
  refreshStoredBusinesses().then(() => {
    render();
    renderBusinessManager();
  });
};
const setupSearchSelects = () => {
  document.querySelectorAll("[data-search-select]").forEach((select) => {
    const trigger = select.querySelector("[data-search-select-trigger]");
    const panel = select.querySelector("[data-search-select-panel]");
    const search = select.querySelector("[data-search-select-search]");
    const valueInput = select.querySelector("[data-search-select-value]");
    const label = select.querySelector("[data-search-select-label]");
    const getOptions = () => [...select.querySelectorAll("[data-search-select-option]")];

    const close = () => {
      panel.hidden = true;
      trigger.setAttribute("aria-expanded", "false");
    };

    const open = () => {
      panel.hidden = false;
      trigger.setAttribute("aria-expanded", "true");
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
      getOptions().forEach((item) => item.classList.toggle("active", item === option));
      valueInput.dispatchEvent(new Event("change", { bubbles: true }));
      close();
      trigger.focus();
    };

    trigger.addEventListener("click", () => {
      panel.hidden ? open() : close();
    });

    search.addEventListener("input", () => {
      const query = search.value.trim().toLowerCase();
      getOptions().forEach((option) => {
        const haystack = `${option.textContent} ${option.dataset.value} ${option.dataset.labelKey}`.toLowerCase();
        option.hidden = Boolean(query) && !haystack.includes(query);
      });
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
        getOptions().forEach((item) => item.classList.toggle("active", item === option));
      }
    });

    document.addEventListener("click", (event) => {
      if (!select.contains(event.target)) {
        close();
      }
    });
  });
};

const setSearchSelectOptions = (select, options, placeholderKey) => {
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
    return button;
  }));

  trigger.disabled = options.length === 0;
  const selected = options.find((option) => option.value === valueInput.value);
  if (selected) {
    label.textContent = selected.label;
  } else {
    valueInput.value = "";
    valueInput.removeAttribute("value");
    label.dataset.i18n = placeholderKey;
    setLanguage(getSavedLanguage());
  }
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
  updateConnectionStatus();
  registerServiceWorker();
});

window.addEventListener("online", updateConnectionStatus);
window.addEventListener("offline", updateConnectionStatus);
