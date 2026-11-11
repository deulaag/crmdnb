(() => {
  const body = document.body;

  const sidebar = document.getElementById('sidebar');
  const toggle = document.querySelector('[data-menu-toggle]');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (event) => {
      if (window.innerWidth > 760) return;
      if (!sidebar.contains(event.target) && !toggle.contains(event.target)) sidebar.classList.remove('open');
    });
  }

  const alert = document.querySelector('[data-autohide]');
  if (alert) {
    window.setTimeout(() => {
      alert.style.transition = 'opacity .25s ease';
      alert.style.opacity = '0';
      window.setTimeout(() => alert.remove(), 300);
    }, 4500);
  }

  document.querySelectorAll('[data-upload-form]').forEach((form) => {
    const input = form.querySelector('input[type="file"]');
    const selected = form.querySelector('[data-upload-selected]');
    const button = form.querySelector('[data-upload-button]');

    if (input && selected) {
      input.addEventListener('change', () => {
        const files = Array.from(input.files || []);
        selected.textContent = files.length
          ? files.map(file => file.name).join(' · ')
          : 'Nenhum PDF selecionado.';
      });
    }

    form.addEventListener('submit', (event) => {
      const files = input ? Array.from(input.files || []) : [];
      if (input && !files.length) {
        event.preventDefault();
        if (selected) selected.textContent = 'Selecione pelo menos um PDF antes de extrair.';
        return;
      }

      if (!button || button.disabled) return;
      button.disabled = true;
      button.dataset.originalLabel = button.textContent;
      button.textContent = button.dataset.loadingLabel || 'Processando...';
    });
  });

  const formatCnpj = (value) => {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 14);
    if (digits.length <= 2) return digits;
    if (digits.length <= 5) return digits.replace(/^(\d{2})(\d+)/, '$1.$2');
    if (digits.length <= 8) return digits.replace(/^(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
    if (digits.length <= 12) return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
    return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d+)/, '$1.$2.$3/$4-$5');
  };

  document.querySelectorAll('[data-cnpj-input]').forEach((input) => {
    input.addEventListener('input', () => {
      input.value = formatCnpj(input.value);
    });
  });

  document.querySelectorAll('[data-cnpj-lookup]').forEach((button) => {
    button.addEventListener('click', async () => {
      const form = button.closest('form') || document;
      const cnpjInput = form.querySelector('[data-cnpj-input]');
      const status = form.querySelector('[data-cnpja-status]');
      const csrf = form.querySelector('input[name="csrf_token"]');
      const digits = (cnpjInput?.value || '').replace(/\D/g, '');

      if (!cnpjInput || digits.length !== 14) {
        if (status) {
          status.className = 'cnpja-status error';
          status.textContent = 'Informe um CNPJ com 14 dígitos.';
        }
        cnpjInput?.focus();
        return;
      }

      const originalLabel = button.textContent;
      button.disabled = true;
      button.textContent = 'Consultando Receita...';

      if (status) {
        status.className = 'cnpja-status loading';
        status.textContent = 'Consultando a CNPJá...';
      }

      try {
        const requestBody = new FormData();
        requestBody.append('cnpj', digits);
        if (csrf) requestBody.append('csrf_token', csrf.value);

        const response = await fetch(button.dataset.endpoint || 'cnpja_lookup.php', {
          method: 'POST',
          body: requestBody,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) {
          throw new Error(payload?.message || 'Não foi possível consultar o CNPJ.');
        }

        const data = payload.data || {};
        Object.entries(data).forEach(([key, value]) => {
          const field = form.querySelector('[data-cnpja-field="' + key + '"]');
          if (!field) return;
          field.value = value ?? '';
          field.dispatchEvent(new Event('change', { bubbles: true }));
        });

        cnpjInput.value = formatCnpj(data.cnpj || digits);

        if (status) {
          const stale = data.stale ? ' · retorno de contingência em cache' : '';
          const source = data.strategy === 'ONLINE' ? 'Receita consultada agora' : 'consulta concluída';
          status.className = 'cnpja-status success';
          status.textContent = source + stale + '.';
        }
      } catch (error) {
        if (status) {
          status.className = 'cnpja-status error';
          status.textContent = error instanceof Error ? error.message : 'Erro ao consultar o CNPJ.';
        }
      } finally {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    });
  });

  const scope = body.dataset.securityScope || 'dnb_crm';
  const routeKey = window.location.pathname + window.location.search + window.location.hash;
  const draftPrefix = scope + ':draft:' + routeKey + ':';
  const eligibleForms = [];

  const serializeDraft = (form) => {
    const draft = {};
    const fields = form.querySelectorAll('input, select, textarea');

    fields.forEach((field) => {
      if (!field.name || field.disabled) return;
      if (field.name === 'csrf_token') return;
      if (['password', 'file', 'submit', 'button'].includes(field.type)) return;

      if (field.type === 'checkbox' || field.type === 'radio') {
        draft[field.name] = field.checked;
        return;
      }

      draft[field.name] = field.value;
    });

    return draft;
  };

  const restoreDraft = (form, draft) => {
    if (!draft || typeof draft !== 'object') return;

    Object.entries(draft).forEach(([name, value]) => {
      const field = Array.from(form.elements).find((element) => element.name === name);
      if (!field || field.disabled) return;
      if (['password', 'file', 'hidden'].includes(field.type) && field.type !== 'checkbox') return;

      if (field.type === 'checkbox' || field.type === 'radio') {
        field.checked = Boolean(value);
      } else {
        field.value = value ?? '';
      }
    });
  };

  document.querySelectorAll('form[method="post"]:not([data-no-draft])').forEach((form, index) => {
    const hasDraftableField = form.querySelector('input:not([type="hidden"]):not([type="file"]):not([type="password"]):not([type="submit"]), textarea');
    if (!hasDraftableField) return;

    const key = draftPrefix + index;
    eligibleForms.push({ form, key });

    try {
      const saved = sessionStorage.getItem(key);
      if (saved) restoreDraft(form, JSON.parse(saved));
    } catch (_) {
    }

    let saveTimer = null;
    const save = () => {
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(() => {
        try {
          sessionStorage.setItem(key, JSON.stringify(serializeDraft(form)));
        } catch (_) {
        }
      }, 250);
    };

    form.addEventListener('input', save);
    form.addEventListener('change', save);
    form.addEventListener('submit', () => {
      try {
        sessionStorage.removeItem(key);
      } catch (_) {
      }
    });
  });

  const persistDrafts = () => {
    eligibleForms.forEach(({ form, key }) => {
      try {
        sessionStorage.setItem(key, JSON.stringify(serializeDraft(form)));
      } catch (_) {
      }
    });
  };

  const timeoutMs = Number(body.dataset.sessionTimeoutMs || 0);
  const warningMs = Number(body.dataset.warningBeforeTimeoutMs || 0);
  const refreshUrl = body.dataset.sessionRefreshUrl || 'session_refresh.php';
  const logoutUrl = body.dataset.logoutUrl || 'logout.php';
  const modal = document.querySelector('[data-session-modal]');
  const countdown = document.querySelector('[data-session-countdown]');
  const continueButton = document.querySelector('[data-session-continue]');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const lastActivityKey = scope + ':lastActivityTimestamp';

  let warningVisible = false;
  let logoutStarted = false;
  let lastActivityTimestamp = Date.now();
  let activityThrottle = 0;

  const setLastActivity = () => {
    if (warningVisible || logoutStarted) return;
    const now = Date.now();
    if (now - activityThrottle < 1000) return;
    activityThrottle = now;
    lastActivityTimestamp = now;
    try {
      sessionStorage.setItem(lastActivityKey, String(now));
    } catch (_) {
    }
  };

  const showWarning = () => {
    if (!modal || warningVisible) return;
    warningVisible = true;
    modal.hidden = false;
    document.documentElement.classList.add('session-warning-open');
    continueButton?.focus();
  };

  const hideWarning = () => {
    if (!modal) return;
    warningVisible = false;
    modal.hidden = true;
    document.documentElement.classList.remove('session-warning-open');
  };

  const logoutForInactivity = () => {
    if (logoutStarted) return;
    logoutStarted = true;
    persistDrafts();

    const target = window.location.pathname + window.location.search + window.location.hash;
    const separator = logoutUrl.includes('?') ? '&' : '?';
    window.location.assign(
      logoutUrl + separator +
      'automatic=1&redirect_to=' + encodeURIComponent(target)
    );
  };

  const continueSession = async () => {
    if (!continueButton || continueButton.disabled) return;

    const original = continueButton.textContent;
    continueButton.disabled = true;
    continueButton.textContent = 'Renovando sessão...';

    try {
      const requestBody = new URLSearchParams();
      requestBody.set('csrf_token', csrfToken);

      const response = await fetch(refreshUrl, {
        method: 'POST',
        body: requestBody,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      });

      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        throw new Error('Sessão expirada');
      }

      lastActivityTimestamp = Date.now();
      try {
        sessionStorage.setItem(lastActivityKey, String(lastActivityTimestamp));
      } catch (_) {
      }

      hideWarning();
    } catch (_) {
      logoutForInactivity();
    } finally {
      continueButton.disabled = false;
      continueButton.textContent = original;
    }
  };

  if (timeoutMs > 0 && warningMs > 0 && warningMs < timeoutMs) {
    try {
      sessionStorage.setItem(lastActivityKey, String(lastActivityTimestamp));
    } catch (_) {
    }

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((eventName) => {
      window.addEventListener(eventName, setLastActivity, { passive: true });
    });

    continueButton?.addEventListener('click', continueSession);

    window.setInterval(() => {
      if (logoutStarted) return;

      const elapsed = Date.now() - lastActivityTimestamp;
      const remaining = timeoutMs - elapsed;

      if (remaining <= 0) {
        logoutForInactivity();
        return;
      }

      if (remaining <= warningMs) {
        showWarning();
        if (countdown) countdown.textContent = Math.max(0, Math.ceil(remaining / 1000)) + 's';
      } else if (warningVisible) {
        hideWarning();
      }
    }, 1000);
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (...args) => {
    const response = await nativeFetch(...args);

    if (response.status === 401 && !logoutStarted) {
      window.setTimeout(logoutForInactivity, 0);
    }

    return response;
  };
})();

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-copy-value]');
  if (!button || !navigator.clipboard) return;
  const label = button.textContent;
  navigator.clipboard.writeText(button.dataset.copyValue || '').then(() => {
    button.textContent = 'Copiado!';
    window.setTimeout(() => { button.textContent = label; }, 2000);
  });
});
