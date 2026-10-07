const getBasePath = () => "/";

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
      row.innerHTML = `
        <div><strong>${item.business_name}</strong><span>${item.business_type || "Business"} · ${item.region_name || ""} ${item.district_name || ""}</span></div>
        <div><strong>${item.owner_name || "No owner"}</strong><span>${item.phone || ""} ${item.email || ""}</span></div>
        <span>${item.plan_name}</span>
        <b>${item.account_status}</b>
      `;
    } else {
      row.innerHTML = `
        <div><strong>${item.full_name}</strong><span>${item.phone || ""} ${item.email || ""}</span></div>
        <div><strong>${item.business_name || "No business"}</strong><span>${item.created_at || ""}</span></div>
        <b>${item.account_status || "active"}</b>
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
  setupThemeToggle();
  setupPasswordToggles();
  setupSaasLogin();
  setupSaasPasswordReset();
  setupSaasDashboard();
  setupAdminListPage();
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
