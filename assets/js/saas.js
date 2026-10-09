(() => {
  if (/\bZipooAndroid\/\d+(?:\.\d+)?\b/.test(navigator.userAgent || "")) {
    document.documentElement.classList.add("is-zipoo-android-webview");
  }
})();

const getBasePath = () => "/";
const SAAS_SW_TRANSLATIONS = {
  "Hide password": "Ficha nenosiri",
  "Show password": "Onyesha nenosiri",
  "Unable to login.": "Imeshindikana kuingia.",
  "Unable to reach the reset service. Please check the connection and try again.": "Imeshindikana kufikia huduma ya kuweka upya. Tafadhali angalia muunganisho kisha ujaribu tena.",
  "Please try again.": "Tafadhali jaribu tena.",
  "Done.": "Imekamilika.",
  "Reset link is missing its token.": "Kiungo cha kuweka upya hakina tokeni.",
  "The reset service was routed to the app page. Please refresh and try again.": "Huduma ya kuweka upya imeelekezwa kwenye ukurasa wa programu. Tafadhali sasisha kisha ujaribu tena.",
  "Reset service returned an unreadable response.": "Huduma ya kuweka upya imerudisha majibu yasiyosomeka.",
  "No owner": "Hakuna mmiliki",
  "Business": "Biashara",
  "Starter": "Mwanzo",
  "Active": "Hai",
  "Inactive": "Haifanyi kazi",
  "Plan": "Mpango",
  "Status": "Hali",
  "View": "Tazama",
  "Save": "Hifadhi",
  "Enter": "Ingia",
  "Owner": "Mmiliki",
  "Users": "Watumiaji",
  "Items": "Bidhaa",
  "Sales": "Mauzo",
  "Location": "Eneo",
  "Registered staff": "Wafanyakazi waliosajiliwa",
  "Stock and services": "Stoku na huduma",
  "Recorded total": "Jumla iliyorekodiwa",
  "Unknown": "Haijulikani",
  "No notes": "Hakuna maelezo",
  "businesses": "biashara",
  "users": "watumiaji",
  "Edit": "Hariri",
  "Delete": "Futa",
  "Delete this plan? Used plans cannot be deleted.": "Futa mpango huu? Mipango inayotumika haiwezi kufutwa.",
  "Saved.": "Imehifadhiwa.",
  "Unable to save.": "Imeshindikana kuhifadhi.",
  "Deleted.": "Imefutwa.",
  "Unable to delete.": "Imeshindikana kufuta.",
  "Saving...": "Inahifadhi...",
  "Settings saved.": "Mipangilio imehifadhiwa.",
  "Unable to save settings.": "Imeshindikana kuhifadhi mipangilio.",
  "Expand sidebar": "Panua menyu ya pembeni",
  "Collapse sidebar": "Kunja menyu ya pembeni",
  "Opening Zipoo": "Inafungua Zipoo",
  "Go to login": "Nenda kuingia",
  "JavaScript is required to open Zipoo.": "JavaScript inahitajika kufungua Zipoo.",
  "Invoice": "Ankara",
  "Invoices": "Ankara",
  "invoices": "ankara"
};

const getSavedLanguage = () => {
  const saved = localStorage.getItem("zipoo.language");
  return ["en", "sw"].includes(saved) ? saved : "en";
};

const translateAutoText = (value, language) => {
  const text = String(value ?? "");
  if (language !== "sw") return text;
  const trimmed = text.trim();
  if (!trimmed) return text;
  const translated = SAAS_SW_TRANSLATIONS[trimmed];
  if (translated) return text.replace(trimmed, translated);
  const invoicesMatch = trimmed.match(/^(\d+)\s+invoices?$/i);
  if (invoicesMatch) return text.replace(trimmed, `Ankara ${invoicesMatch[1]}`);
  return text;
};

const applyAutoTranslations = (language = getSavedLanguage(), root = document.body) => {
  if (!root) return;
  const elementRoot = root.nodeType === Node.ELEMENT_NODE ? root : root.parentElement;
  if (!elementRoot) return;
  const translateElement = (element) => {
    if (!element || element.closest?.("script, style, textarea, [contenteditable='true']")) return;
    if (element.childNodes.length === 1 && element.firstChild?.nodeType === Node.TEXT_NODE) {
      if (!element.dataset.i18nAutoText) element.dataset.i18nAutoText = element.textContent;
      element.textContent = translateAutoText(element.dataset.i18nAutoText, language);
    }
    ["placeholder", "aria-label", "title", "value"].forEach((attr) => {
      if (!element.hasAttribute?.(attr)) return;
      if (attr === "value" && !["BUTTON", "INPUT"].includes(element.tagName)) return;
      const dataKey = `i18nAuto${attr.replace(/(^|-)([a-z])/g, (_, __, c) => c.toUpperCase())}`;
      if (!element.dataset[dataKey]) element.dataset[dataKey] = element.getAttribute(attr) || "";
      const translated = translateAutoText(element.dataset[dataKey], language);
      if (element.getAttribute(attr) !== translated) element.setAttribute(attr, translated);
    });
  };
  document.documentElement.lang = language;
  translateElement(elementRoot);
  elementRoot.querySelectorAll("*").forEach(translateElement);
};

const initAutoTranslationObserver = () => {
  try {
    const observer = new MutationObserver((mutations) => {
      const language = getSavedLanguage();
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) applyAutoTranslations(language, node);
        });
        if (mutation.type === "attributes" && mutation.target?.nodeType === Node.ELEMENT_NODE) {
          applyAutoTranslations(language, mutation.target);
        }
      });
    });
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ["placeholder", "aria-label", "title", "value"],
    });
  } catch {
    /* Best-effort translation for older browsers. */
  }
};

const applyTheme = (theme) => {
  const nextTheme = theme === "dark" ? "dark" : "light";
  document.documentElement.dataset.theme = nextTheme;
  localStorage.setItem("zipoo.saas.theme", nextTheme);
  document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
    button.querySelector(".theme-sun").hidden = nextTheme === "dark";
    button.querySelector(".theme-moon").hidden = nextTheme !== "dark";
  });
};

const setupThemeToggle = () => {
  applyTheme(localStorage.getItem("zipoo.saas.theme") || "light");
  document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      const nextTheme = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
      applyTheme(nextTheme);
    });
  });
};

const setupPasswordToggles = () => {
  document.querySelectorAll("[data-password-field]").forEach((field) => {
    const input = field.querySelector("input");
    const toggle = field.querySelector("[data-password-toggle]");
    const eye = toggle?.querySelector(".icon-eye");
    const eyeOff = toggle?.querySelector(".icon-eye-off");

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

const setupSaasLogin = () => {
  const form = document.querySelector("[data-saas-login]");
  if (!form) {
    return;
  }

  const error = form.querySelector("[data-saas-error]");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    error.hidden = true;

    const response = await fetch(form.action, {
      method: "POST",
      body: new FormData(form),
    });
    const payload = await response.json();

    if (!response.ok || !payload.ok) {
      error.textContent = payload.message || "Unable to login.";
      error.hidden = false;
      return;
    }

    window.location.href = `${getBasePath()}saas/dashboard`;
  });
};

const setupSaasPasswordReset = () => {
  const forgotForm = document.querySelector("[data-saas-forgot]");
  const resetForm = document.querySelector("[data-saas-reset]");
  const resetEndpoint = `${getBasePath()}api/saas-password-reset.php`;

  const parseResetResponse = async (response) => {
    const text = await response.text();
    try {
      return text ? JSON.parse(text) : {};
    } catch {
      const normalized = text.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
      if (/Opening Zipoo|JavaScript is required to open Zipoo/i.test(normalized)) {
        return { ok: false, message: "The reset service was routed to the app page. Please refresh and try again." };
      }
      return { ok: false, message: normalized || "Reset service returned an unreadable response." };
    }
  };

  const wireForm = (form, onSuccess) => {
    if (!form) return;
    const error = form.querySelector("[data-saas-error]");
    const success = form.querySelector("[data-saas-success]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (error) error.hidden = true;
      if (success) success.hidden = true;

      let response;
      try {
        response = await fetch(`${resetEndpoint}?_=${Date.now()}`, {
          method: "POST",
          headers: {
            "Accept": "application/json",
          },
          body: new FormData(form),
          cache: "no-store",
          credentials: "same-origin",
        });
      } catch (networkError) {
        if (error) {
          error.textContent = "Unable to reach the reset service. Please check the connection and try again.";
          error.hidden = false;
        }
        return;
      }

      const payload = await parseResetResponse(response);

      if (!response.ok || !payload.ok) {
        if (error) {
          error.textContent = payload.message || "Please try again.";
          error.hidden = false;
        }
        return;
      }

      if (success) {
        success.textContent = payload.message || "Done.";
        success.hidden = false;
      }
      if (typeof onSuccess === "function") onSuccess(payload);
    });
  };

  wireForm(forgotForm);

  if (resetForm) {
    const tokenInput = resetForm.querySelector("[data-reset-token]");
    const token = new URLSearchParams(window.location.search).get("token") || "";
    if (tokenInput) tokenInput.value = token;
    if (!token) {
      const error = resetForm.querySelector("[data-saas-error]");
      if (error) {
        error.textContent = "Reset link is missing its token.";
        error.hidden = false;
      }
    }
    wireForm(resetForm, () => {
      window.setTimeout(() => {
        window.location.href = `${getBasePath()}saas/login`;
      }, 1200);
    });
  }
};

const renderBusinesses = (businesses) => {
  const list = document.querySelector("[data-business-list]");
  if (!list) {
    return;
  }

  list.replaceChildren(...businesses.map((business) => {
    const row = document.createElement("article");
    row.className = "saas-business-row";
    row.innerHTML = `
      <div>
        <strong>${business.business_name}</strong>
        <span>${business.owner_name || "No owner"} · ${business.phone || ""}</span>
      </div>
      <span>${business.plan_name}</span>
      <b>${business.account_status}</b>
    `;
    return row;
  }));
};

const money = (value) => new Intl.NumberFormat("en-TZ", {
  maximumFractionDigits: 0,
}).format(Number(value || 0));

const textOrDash = (value) => {
  const text = String(value ?? "").trim();
  return text || "-";
};

const applyPlatformBrand = (platform = {}) => {
  if (platform.platform_icon) {
    document.querySelectorAll('link[rel="icon"], link[rel="apple-touch-icon"]').forEach((link) => {
      link.setAttribute("href", platform.platform_icon);
    });
  }

  if (platform.platform_name) {
    document.querySelectorAll(".brand-name").forEach((name) => {
      name.textContent = platform.platform_name;
    });
  }

  if (platform.platform_logo) {
    document.querySelectorAll(".saas-sidebar .brand").forEach((brand) => {
      const mark = brand.querySelector(".brand-mark");
      const name = brand.querySelector(".brand-name");
      if (name) {
        name.hidden = true;
      }
      if (mark) {
        mark.innerHTML = "";
        const image = document.createElement("img");
        image.src = platform.platform_logo;
        image.alt = platform.platform_name || "Platform logo";
        mark.appendChild(image);
        mark.classList.add("has-image", "wide-logo");
      }
    });
  }
};

const setupSaasDashboard = async () => {
  const metrics = document.querySelector("[data-saas-metrics]");
  if (!metrics) {
    return;
  }

  const response = await fetch(`${getBasePath()}api/saas-summary.php`);
  if (response.status === 401) {
    window.location.href = `${getBasePath()}saas/login`;
    return;
  }

  const payload = await response.json();
  if (!response.ok || !payload.ok) {
    return;
  }

  Object.entries(payload.summary).forEach(([key, value]) => {
    const node = document.querySelector(`[data-metric="${key}"]`);
    if (node) {
      node.textContent = value;
    }
  });

  let businesses = payload.businesses || [];
  renderBusinesses(businesses);

  document.querySelector("[data-business-search]")?.addEventListener("input", (event) => {
    const query = event.target.value.trim().toLowerCase();
    renderBusinesses(businesses.filter((business) => {
      return `${business.business_name} ${business.owner_name} ${business.phone}`.toLowerCase().includes(query);
    }));
  });
};

const renderAdminRows = (items, type) => {
  const list = document.querySelector(type === "businesses" ? "[data-businesses-page-list]" : "[data-users-page-list]");
  if (!list) {
    return;
  }

  list.replaceChildren(...items.map((item) => {
    const row = document.createElement("article");
    row.className = "admin-data-row";
    if (type === "businesses") {
      row.dataset.businessRow = item.id;
      row.innerHTML = `
        <div><strong>${item.business_name}</strong><span>${item.business_type || "Business"} · ${item.region_name || ""} ${item.district_name || ""}</span></div>
        <div><strong>${item.owner_name || "No owner"}</strong><span>${item.phone || ""} ${item.email || ""}</span></div>
        <label><span>Plan</span><input name="plan_name" value="${item.plan_name || "Starter"}"></label>
        <label><span>Status</span><select class="css-formatted-select" name="account_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <div class="admin-row-actions">
          <button type="button" data-business-view="${item.id}">View</button>
          <button type="button" data-business-save="${item.id}">Save</button>
        </div>
      `;
      row.querySelector('[name="account_status"]').value = item.account_status || "active";
    } else {
      row.innerHTML = `
        <div><strong>${item.full_name}</strong><span>${item.phone || ""} ${item.email || ""}</span></div>
        <div><strong>${item.business_name || "No business"}</strong><span>${item.created_at || ""}</span></div>
        <b>${item.account_status || "active"}</b>
        <div class="admin-row-actions">
          <button type="button" data-impersonate-user="${item.id}">Enter</button>
        </div>
      `;
    }
    return row;
  }));
};

const setupAdminListPage = async () => {
  const businessesList = document.querySelector("[data-businesses-page-list]");
  const usersList = document.querySelector("[data-users-page-list]");
  if (!businessesList && !usersList) {
    return;
  }

  const type = businessesList ? "businesses" : "users";
  const endpoint = `${getBasePath()}api/saas-${type}.php`;
  const status = document.querySelector("[data-admin-list-status]");
  const load = async (query = "") => {
    const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}`);
    if (response.status === 401) {
      window.location.href = `${getBasePath()}saas/login`;
      return;
    }
    const payload = await response.json();
    if (response.ok && payload.ok) {
      renderAdminRows(payload[type] || [], type);
    }
  };

  await load();
  document.querySelector("[data-admin-search]")?.addEventListener("input", (event) => {
    load(event.target.value.trim());
  });

  businessesList?.addEventListener("click", async (event) => {
    const save = event.target.closest("[data-business-save]");
    const view = event.target.closest("[data-business-view]");
    const businessId = save?.dataset.businessSave || view?.dataset.businessView;
    if (!businessId) return;

    if (save) {
      const row = save.closest("[data-business-row]");
      const form = new FormData();
      form.set("action", "update_business");
      form.set("business_id", businessId);
      form.set("plan_name", row.querySelector('[name="plan_name"]').value);
      form.set("account_status", row.querySelector('[name="account_status"]').value);
      const response = await fetch(endpoint, { method: "POST", body: form });
      const payload = await response.json();
      if (status) {
        status.hidden = false;
        status.classList.toggle("error", !response.ok || !payload.ok);
        status.textContent = payload.message || (response.ok ? "Saved." : "Unable to save.");
      }
      if (response.ok && payload.ok) await load(document.querySelector("[data-admin-search]")?.value.trim() || "");
      return;
    }

    const response = await fetch(`${endpoint}?id=${encodeURIComponent(businessId)}`);
    const payload = await response.json();
    if (response.ok && payload.ok) {
      openBusinessSnapshot(payload);
    }
  });

  usersList?.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-impersonate-user]");
    if (!button) return;
    const body = new FormData();
    body.set("action", "start");
    body.set("user_id", button.dataset.impersonateUser);
    button.disabled = true;
    const response = await fetch(`${getBasePath()}api/saas-impersonate.php`, { method: "POST", body });
    const payload = await response.json();
    button.disabled = false;

    if (!response.ok || !payload.ok) {
      if (status) {
        status.hidden = false;
        status.classList.add("error");
        status.textContent = payload.message || "Unable to enter client account.";
      } else {
        window.alert(payload.message || "Unable to enter client account.");
      }
      return;
    }

    localStorage.setItem("zipoo.isLoggedIn", "true");
    localStorage.setItem("zipoo.user", JSON.stringify(payload.user || {}));
    localStorage.setItem("zipoo.businesses", JSON.stringify(payload.businesses || []));
    if (payload.user?.business_id) {
      localStorage.setItem("zipoo.currentBusinessId", String(payload.user.business_id));
    }
    if (payload.impersonation) {
      localStorage.setItem("zipoo.impersonation", JSON.stringify(payload.impersonation));
    }
    window.location.href = payload.redirect || `${getBasePath()}dashboard`;
  });
};

const openBusinessSnapshot = (payload) => {
  const modal = document.querySelector("[data-business-modal]");
  if (!modal) return;
  const business = payload.business || {};
  const stats = payload.stats || {};
  modal.querySelector("[data-business-modal-title]").textContent = business.business_name || "Business";
  modal.querySelector("[data-business-modal-body]").innerHTML = `
    <div class="business-snapshot-grid">
      <article><span>Owner</span><strong>${business.owner_name || "No owner"}</strong><small>${business.phone || ""} ${business.email || ""}</small></article>
      <article><span>Plan</span><strong>${business.plan_name || "Starter"}</strong><small>${business.account_status || "active"}</small></article>
      <article><span>Users</span><strong>${stats.users || 0}</strong><small>Registered staff</small></article>
      <article><span>Items</span><strong>${stats.items || 0}</strong><small>Stock and services</small></article>
      <article><span>Sales</span><strong>TZS ${money(stats.sales)}</strong><small>Recorded total</small></article>
      <article><span>Location</span><strong>${business.region_name || "Unknown"}</strong><small>${business.district_name || ""}</small></article>
    </div>
  `;
  modal.hidden = false;
};

const setupSaasModals = () => {
  document.querySelectorAll("[data-modal-close]").forEach((button) => {
    button.addEventListener("click", () => {
      button.closest("[data-business-modal]")?.setAttribute("hidden", "");
    });
  });
};

const renderPlans = (plans) => {
  const list = document.querySelector("[data-plans-list]");
  if (!list) return;
  list.replaceChildren(...plans.map((plan) => {
    const row = document.createElement("article");
    row.className = "plan-row";
    row.dataset.planId = plan.id;
    row.innerHTML = `
      <div><strong>${plan.plan_name}</strong><span>${plan.notes || "No notes"} · ${plan.business_count || 0} businesses</span></div>
      <b>TZS ${money(plan.monthly_price)}</b>
      <span>${plan.user_limit} users</span>
      <span>${plan.business_limit} businesses</span>
      <em>${plan.status}</em>
      <div class="admin-row-actions">
        <button type="button" data-plan-edit="${plan.id}">Edit</button>
        <button type="button" data-plan-delete="${plan.id}">Delete</button>
      </div>
    `;
    row.querySelector("[data-plan-edit]").addEventListener("click", () => {
      const form = document.querySelector("[data-plan-form]");
      form.plan_id.value = plan.id;
      form.plan_name.value = plan.plan_name || "";
      form.monthly_price.value = plan.monthly_price || "0";
      form.user_limit.value = plan.user_limit || "1";
      form.business_limit.value = plan.business_limit || "1";
      form.status.value = plan.status || "active";
      form.notes.value = plan.notes || "";
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
    return row;
  }));
};

const setupPlansPage = async () => {
  const form = document.querySelector("[data-plan-form]");
  const list = document.querySelector("[data-plans-list]");
  if (!form || !list) return;
  const status = form.querySelector("[data-plan-status]");
  const endpoint = `${getBasePath()}api/saas-plans.php`;

  const load = async () => {
    const response = await fetch(endpoint);
    if (response.status === 401) {
      window.location.href = `${getBasePath()}saas/login`;
      return;
    }
    const payload = await response.json();
    if (response.ok && payload.ok) renderPlans(payload.plans || []);
  };

  await load();

  document.querySelector("[data-new-plan]")?.addEventListener("click", () => {
    form.reset();
    form.plan_id.value = "";
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const response = await fetch(endpoint, { method: "POST", body: new FormData(form) });
    const payload = await response.json();
    if (status) {
      status.hidden = false;
      status.classList.toggle("error", !response.ok || !payload.ok);
      status.textContent = payload.message || (response.ok ? "Saved." : "Unable to save.");
    }
    if (response.ok && payload.ok) {
      form.reset();
      form.plan_id.value = "";
      renderPlans(payload.plans || []);
    }
  });

  list.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-plan-delete]");
    if (!button) return;
    if (!window.confirm("Delete this plan? Used plans cannot be deleted.")) return;
    const body = new FormData();
    body.set("action", "delete");
    body.set("plan_id", button.dataset.planDelete);
    const response = await fetch(endpoint, { method: "POST", body });
    const payload = await response.json();
    if (status) {
      status.hidden = false;
      status.classList.toggle("error", !response.ok || !payload.ok);
      status.textContent = payload.message || (response.ok ? "Deleted." : "Unable to delete.");
    }
    if (response.ok && payload.ok) renderPlans(payload.plans || []);
  });
};

const setupSaasBillingPage = async () => {
  const root = document.querySelector("[data-saas-billing-page]");
  if (!root) return;

  const endpoint = `${getBasePath()}api/saas-billing.php`;
  const status = root.querySelector("[data-saas-billing-status]");
  const accountsList = root.querySelector("[data-billing-accounts-list]");
  const invoicesList = root.querySelector("[data-billing-invoices-list]");
  const transactionsList = root.querySelector("[data-lipa-transactions-list]");
  const devicesList = root.querySelector("[data-lipa-devices-list]");
  const tokenBox = root.querySelector("[data-lipa-device-token]");

  const setStatus = (message, isError = false) => {
    if (!status) return;
    status.hidden = false;
    status.classList.toggle("error", isError);
    status.textContent = message;
  };

  const postAction = async (body) => {
    const response = await fetch(endpoint, { method: "POST", body });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      setStatus(payload.message || "Unable to save.", true);
      return null;
    }
    setStatus(payload.message || "Saved.");
    render(payload);
    return payload;
  };

  const render = (payload) => {
    const accounts = payload.accounts || [];
    const invoices = payload.invoices || [];
    const transactions = payload.lipa_transactions || [];
    const devices = payload.lipa_devices || [];
    const settings = payload.lipa_settings || {};

    root.querySelector("[data-billing-metric='accounts']").textContent = accounts.length;
    root.querySelector("[data-billing-metric='open_invoices']").textContent = invoices.filter((invoice) => ["pending_payment", "pending_review"].includes(invoice.status)).length;
    root.querySelector("[data-billing-metric='pending_lipa']").textContent = transactions.filter((item) => item.status === "pending").length;
    root.querySelector("[data-billing-metric='sms_balance']").textContent = money(accounts.reduce((total, item) => total + Number(item.sms_balance || 0), 0));

    accountsList.replaceChildren(...accounts.map((account) => {
      const row = document.createElement("article");
      row.className = "admin-data-row";
      row.innerHTML = `
        <div><strong></strong><span></span></div>
        <div><strong></strong><span></span></div>
        <b></b>
        <div class="admin-row-actions">
          <button type="button" data-create-invoice="${account.id}">Invoice</button>
          <button type="button" data-adjust-sms="${account.id}">Add SMS</button>
        </div>
      `;
      const blocks = row.querySelectorAll("div");
      blocks[0].querySelector("strong").textContent = account.account_name || `Account #${account.id}`;
      blocks[0].querySelector("span").textContent = `${account.business_count || 0} businesses · ${account.plan_name || "No plan"}`;
      blocks[1].querySelector("strong").textContent = textOrDash(account.owner_name);
      blocks[1].querySelector("span").textContent = `${textOrDash(account.owner_phone)} · SMS ${money(account.sms_balance)}`;
      row.querySelector("b").textContent = `${account.open_invoices || 0} open`;
      return row;
    }));

    invoicesList.replaceChildren(...invoices.map((invoice) => {
      const row = document.createElement("article");
      row.className = "admin-data-row";
      row.innerHTML = `
        <div><strong></strong><span></span></div>
        <div><strong></strong><span></span></div>
        <b></b>
        <div class="admin-row-actions"></div>
      `;
      row.querySelector("strong").textContent = invoice.invoice_number || `Invoice #${invoice.id}`;
      row.querySelector("span").textContent = `${invoice.owner_name || invoice.account_name || "Account"} · ${invoice.due_date || ""}`;
      row.querySelectorAll("strong")[1].textContent = `TZS ${money(invoice.amount)}`;
      row.querySelectorAll("span")[1].textContent = invoice.payment_reference || "No reference yet";
      row.querySelector("b").textContent = invoice.status || "pending";
      if (invoice.status !== "paid") {
        const button = document.createElement("button");
        button.type = "button";
        button.dataset.confirmInvoice = invoice.id;
        button.textContent = "Confirm";
        row.querySelector(".admin-row-actions").appendChild(button);
      }
      const deleteButton = document.createElement("button");
      deleteButton.type = "button";
      deleteButton.dataset.deleteInvoice = invoice.id;
      deleteButton.textContent = "Delete";
      row.querySelector(".admin-row-actions").appendChild(deleteButton);
      return row;
    }));

    transactionsList.replaceChildren(...transactions.map((item) => {
      const row = document.createElement("article");
      row.className = "admin-data-row";
      row.innerHTML = `
        <div><strong></strong><span></span></div>
        <div><strong></strong><span></span></div>
        <b></b>
        <div class="admin-row-actions"></div>
      `;
      row.querySelector("strong").textContent = item.reference || `Transaction #${item.id}`;
      row.querySelector("span").textContent = `${item.sender || ""} · ${item.received_at || ""}`;
      row.querySelectorAll("strong")[1].textContent = `TZS ${money(item.amount)}`;
      row.querySelectorAll("span")[1].textContent = item.body || item.note || "";
      row.querySelector("b").textContent = item.status || "pending";
      if (!["matched", "ignored"].includes(item.status)) {
        const button = document.createElement("button");
        button.type = "button";
        button.dataset.ignoreLipaTransaction = item.id;
        button.textContent = "Ignore";
        row.querySelector(".admin-row-actions").appendChild(button);
      }
      return row;
    }));

    devicesList.replaceChildren(...devices.map((device) => {
      const row = document.createElement("article");
      row.className = "admin-data-row";
      row.innerHTML = `
        <div><strong></strong><span></span></div>
        <div><strong></strong><span></span></div>
        <b></b>
        <div class="admin-row-actions"></div>
      `;
      row.querySelector("strong").textContent = device.device_name || "Lipa SMS device";
      row.querySelector("span").textContent = device.device_uuid || "";
      row.querySelectorAll("strong")[1].textContent = device.last_seen_at ? `Last seen ${device.last_seen_at}` : "Not seen yet";
      row.querySelectorAll("span")[1].textContent = device.created_at || "";
      row.querySelector("b").textContent = device.status || "active";
      if (device.status !== "revoked") {
        const button = document.createElement("button");
        button.type = "button";
        button.dataset.revokeLipaDevice = device.id;
        button.textContent = "Revoke";
        row.querySelector(".admin-row-actions").appendChild(button);
      }
      return row;
    }));

    const numbers = (() => {
      try {
        return (JSON.parse(settings.lipa_numbers || "[]") || []).map((item) => [item.network, item.number, item.name].filter(Boolean).join(" | ")).join("\n");
      } catch {
        return settings.lipa_numbers || "";
      }
    })();
    const form = root.querySelector("[data-lipa-settings-form]");
    if (form) {
      form.enabled.checked = settings.lipa_payment_enabled === "1";
      form.auto_approve.checked = settings.lipa_auto_approve === "1";
      form.auto_approve_max.value = settings.lipa_auto_approve_max || "0";
      form.allowed_senders.value = settings.lipa_allowed_senders || "";
      form.numbers.value = numbers;
    }
  };

  const response = await fetch(endpoint);
  if (response.status === 401) {
    window.location.href = `${getBasePath()}saas/login`;
    return;
  }
  const payload = await response.json();
  if (response.ok && payload.ok) render(payload);

  root.addEventListener("click", async (event) => {
    const invoice = event.target.closest("[data-create-invoice]");
    const confirm = event.target.closest("[data-confirm-invoice]");
    const adjust = event.target.closest("[data-adjust-sms]");
    const deleteInvoice = event.target.closest("[data-delete-invoice]");
    const revoke = event.target.closest("[data-revoke-lipa-device]");
    const ignore = event.target.closest("[data-ignore-lipa-transaction]");
    const body = new FormData();

    if (invoice) {
      body.set("action", "create_invoice");
      body.set("account_id", invoice.dataset.createInvoice);
      await postAction(body);
      return;
    }
    if (confirm) {
      body.set("action", "confirm_invoice");
      body.set("invoice_id", confirm.dataset.confirmInvoice);
      await postAction(body);
      return;
    }
    if (deleteInvoice) {
      if (!window.confirm("Delete this invoice? Paid invoices and their payment record will also be removed.")) return;
      body.set("action", "delete_invoice");
      body.set("invoice_id", deleteInvoice.dataset.deleteInvoice);
      await postAction(body);
      return;
    }
    if (adjust) {
      const count = window.prompt("SMS count to add or subtract", "100");
      if (!count) return;
      const reference = window.prompt("Reference", "SaaS SMS allocation") || "SaaS SMS allocation";
      body.set("action", "adjust_sms");
      body.set("account_id", adjust.dataset.adjustSms);
      body.set("sms_count", count);
      body.set("reference", reference);
      await postAction(body);
      return;
    }
    if (revoke) {
      body.set("action", "revoke_lipa_device");
      body.set("device_id", revoke.dataset.revokeLipaDevice);
      await postAction(body);
      return;
    }
    if (ignore) {
      body.set("action", "ignore_lipa_transaction");
      body.set("transaction_id", ignore.dataset.ignoreLipaTransaction);
      await postAction(body);
    }
  });

  root.querySelector("[data-lipa-settings-form]")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    body.set("action", "save_lipa_settings");
    await postAction(body);
  });

  root.querySelector("[data-lipa-device-form]")?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    body.set("action", "create_lipa_device");
    const payload = await postAction(body);
    if (payload?.device_token && tokenBox) {
      tokenBox.hidden = false;
      tokenBox.textContent = `Bearer token: ${payload.device_token}`;
    }
    event.currentTarget.reset();
  });
};

const setupSaasLogout = () => {
  document.querySelector("[data-saas-logout]")?.addEventListener("click", async () => {
    await fetch(`${getBasePath()}api/saas-logout.php`, { method: "POST" });
    window.location.href = `${getBasePath()}saas/login`;
  });
};

const setupAdminMenu = () => {
  document.querySelectorAll("[data-admin-menu]").forEach((menu) => {
    const toggle = menu.querySelector("[data-admin-menu-toggle]");
    const panel = menu.querySelector("[data-admin-menu-panel]");
    if (!toggle || !panel) {
      return;
    }

    const close = () => {
      panel.hidden = true;
      toggle.setAttribute("aria-expanded", "false");
    };

    toggle.addEventListener("click", () => {
      const isOpen = !panel.hidden;
      panel.hidden = isOpen;
      toggle.setAttribute("aria-expanded", String(!isOpen));
    });

    document.addEventListener("click", (event) => {
      if (!menu.contains(event.target)) {
        close();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        close();
      }
    });
  });
};

const setupNotifications = () => {
  document.querySelectorAll("[data-notifications]").forEach((box) => {
    const toggle = box.querySelector("[data-notifications-toggle]");
    const panel = box.querySelector("[data-notifications-panel]");
    if (!toggle || !panel) {
      return;
    }

    const close = () => {
      panel.hidden = true;
      toggle.setAttribute("aria-expanded", "false");
    };

    toggle.addEventListener("click", () => {
      const isOpen = !panel.hidden;
      panel.hidden = isOpen;
      toggle.setAttribute("aria-expanded", String(!isOpen));
    });

    document.addEventListener("click", (event) => {
      if (!box.contains(event.target)) {
        close();
      }
    });
  });
};

const setupSidebarToggle = () => {
  const layout = document.querySelector(".saas-admin-layout");
  const toggle = document.querySelector("[data-sidebar-toggle]");
  if (!layout || !toggle) {
    return;
  }

  const applyCollapsed = (collapsed) => {
    layout.classList.toggle("sidebar-collapsed", collapsed);
    toggle.setAttribute("aria-expanded", String(!collapsed));
    toggle.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
    localStorage.setItem("zipoo.saas.sidebarCollapsed", collapsed ? "1" : "0");
  };

  applyCollapsed(localStorage.getItem("zipoo.saas.sidebarCollapsed") === "1");
  toggle.addEventListener("click", () => {
    applyCollapsed(!layout.classList.contains("sidebar-collapsed"));
  });
};

const switchTabs = (container, target, targetAttr, panelAttr) => {
  container.querySelectorAll(`[${targetAttr}]`).forEach((button) => {
    button.classList.toggle("active", button.getAttribute(targetAttr) === target);
  });
  container.querySelectorAll(`[${panelAttr}]`).forEach((panel) => {
    const isActive = panel.getAttribute(panelAttr) === target;
    panel.hidden = !isActive;
    panel.classList.toggle("active", isActive);
  });
};

const setupSettingsTabs = () => {
  document.querySelectorAll("[data-settings-tabs]").forEach((settings) => {
    settings.querySelectorAll("[data-tab-target]").forEach((button) => {
      button.addEventListener("click", () => {
        switchTabs(settings, button.dataset.tabTarget, "data-tab-target", "data-tab-panel");
      });
    });

    settings.querySelectorAll("[data-subtab-target]").forEach((button) => {
      button.addEventListener("click", () => {
        const panel = button.closest("[data-tab-panel]");
        if (panel) {
          switchTabs(panel, button.dataset.subtabTarget, "data-subtab-target", "data-subtab-panel");
        }
      });
    });
  });
};

const setupDefinitionLists = async () => {
  const regionList = document.querySelector("[data-region-definition-list]");
  const districtList = document.querySelector("[data-district-definition-list]");
  if (!regionList || !districtList) {
    return;
  }

  const response = await fetch(`${getBasePath()}api/locations.php`);
  const payload = await response.json();
  if (!response.ok || !payload.ok) {
    return;
  }

  const regions = payload.regions || [];
  document.querySelectorAll("[data-definition-region-options]").forEach((select) => {
    const current = select.value;
    select.replaceChildren(...regions.map((region) => {
      const option = document.createElement("option");
      option.value = region.value;
      option.textContent = region.label;
      return option;
    }));
    select.value = current;
  });

  regionList.replaceChildren(...regions.map((region) => {
    const row = document.createElement("article");
    row.className = "definition-row";
    row.innerHTML = `<strong>${region.label}</strong><span>${region.value} · ${region.districts.length} districts</span><button type="button">Edit</button>`;
    row.querySelector("button").addEventListener("click", () => {
      const form = document.querySelector("[data-region-form]");
      form.querySelector("[data-region-mode]").value = "edit";
      form.querySelector("[data-original-region-code]").value = region.value;
      form.region_code.value = region.value;
      form.region_name.value = region.label;
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
    return row;
  }));

  districtList.replaceChildren(...regions.flatMap((region) => region.districts.map((district) => {
    const row = document.createElement("article");
    row.className = "definition-row";
    row.innerHTML = `<strong>${district.label}</strong><span>${district.value} · ${region.label}</span><button type="button">Edit</button>`;
    row.querySelector("button").addEventListener("click", () => {
      const form = document.querySelector("[data-district-form]");
      form.querySelector("[data-district-mode]").value = "edit";
      form.querySelector("[data-original-district-code]").value = district.value;
      form.region_code.value = region.value;
      form.district_code.value = district.value;
      form.district_name.value = district.label;
      form.scrollIntoView({ behavior: "smooth", block: "center" });
    });
    return row;
  })));
};

const setupDefinitionForms = () => {
  document.querySelector("[data-new-region]")?.addEventListener("click", () => {
    const form = document.querySelector("[data-region-form]");
    form.reset();
    form.querySelector("[data-region-mode]").value = "add";
    form.querySelector("[data-original-region-code]").value = "";
  });

  document.querySelector("[data-new-district]")?.addEventListener("click", () => {
    const form = document.querySelector("[data-district-form]");
    form.reset();
    form.querySelector("[data-district-mode]").value = "add";
    form.querySelector("[data-original-district-code]").value = "";
  });

  document.querySelectorAll("[data-region-form], [data-district-form]").forEach((form) => {
    const status = form.querySelector("[data-definition-status]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      status.hidden = false;
      status.classList.remove("error");
      status.textContent = "Saving...";

      const response = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
      });
      const payload = await response.json();
      status.classList.toggle("error", !response.ok || !payload.ok);
      status.textContent = payload.message || (response.ok ? "Saved." : "Unable to save.");
      if (response.ok && payload.ok) {
        form.reset();
        await setupDefinitionLists();
      }
    });
  });
};

const loadSaasSettings = async () => {
  if (!document.querySelector(".saas-admin-layout")) {
    return;
  }

  const response = await fetch(`${getBasePath()}api/saas-settings.php`);
  if (response.status === 401) {
    window.location.href = `${getBasePath()}saas/login`;
    return;
  }

  const payload = await response.json();
  if (!response.ok || !payload.ok) {
    return;
  }

  applyPlatformBrand(payload.settings?.platform || {});

  const fields = document.querySelectorAll("[data-setting]");
  fields.forEach((field) => {
    const [group, key] = field.dataset.setting.split(".");
    const value = payload.settings?.[group]?.[key];
    if (value !== undefined && field.type !== "file") {
      field.value = value;
    }
  });
};

const setupSaasSettingsForms = () => {
  document.querySelectorAll("[data-settings-form], [data-settings-test-form]").forEach((form) => {
    const status = form.querySelector("[data-settings-status]");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (status) {
        status.hidden = false;
        status.classList.remove("error");
        status.textContent = "Saving...";
      }

      const response = await fetch(form.getAttribute("action"), {
        method: "POST",
        body: new FormData(form),
      });
      const payload = await response.json();

      if (status) {
        status.classList.toggle("error", !response.ok || !payload.ok);
        status.textContent = payload.message || (response.ok ? "Settings saved." : "Unable to save settings.");
      }

      if (response.ok && payload.ok) {
        form.querySelectorAll('input[type="file"]').forEach((input) => {
          input.value = "";
        });
        await loadSaasSettings();
      }
    });
  });
};

document.addEventListener("DOMContentLoaded", () => {
  applyAutoTranslations();
  initAutoTranslationObserver();
  setupThemeToggle();
  setupPasswordToggles();
  setupSaasLogin();
  setupSaasPasswordReset();
  setupSaasDashboard();
  setupAdminListPage();
  setupSaasModals();
  setupPlansPage();
  setupSaasBillingPage();
  setupSaasLogout();
  setupAdminMenu();
  setupNotifications();
  setupSidebarToggle();
  setupSettingsTabs();
  setupDefinitionLists();
  setupDefinitionForms();
  loadSaasSettings();
  setupSaasSettingsForms();
});
