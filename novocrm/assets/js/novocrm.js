/* NOVOCRM — comportamento da interface. JS mínimo; htmx e SortableJS vêm de assets/vendor. */
(function () {
  'use strict';

  var root = document.documentElement;
  var body = document.body;
  var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

  /* ---------- armazenamento (com try/catch) ---------- */
  var store = {
    get: function (key) { try { return window.localStorage.getItem(key); } catch (_) { return null; } },
    set: function (key, value) { try { window.localStorage.setItem(key, value); } catch (_) { /* ignora */ } },
    remove: function (key) { try { window.localStorage.removeItem(key); } catch (_) { /* ignora */ } }
  };

  /* ---------- CSRF: sempre no corpo da requisição ---------- */
  function csrf() {
    var meta = $('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  /* ---------- camadas (modal, drawer, busca): foco, Esc e Tab preso ---------- */
  var layers = [];
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function focusables(el) {
    return $$(FOCUSABLE, el).filter(function (n) { return !n.hidden && n.offsetParent !== null; });
  }

  function openLayer(layer) {
    layer.opener = document.activeElement;
    layers.push(layer);
    layer.show();
    window.setTimeout(function () {
      var target = (layer.initial && layer.initial()) || focusables(layer.el)[0] || layer.el;
      if (target && target.focus) target.focus();
    }, 0);
  }

  function closeLayer(layer) {
    var i = layers.indexOf(layer);
    if (i === -1) return;
    layers.splice(i, 1);
    layer.hide();
    if (layer.opener && document.contains(layer.opener) && layer.opener.focus) layer.opener.focus();
  }

  document.addEventListener('keydown', function (e) {
    var top = layers[layers.length - 1];
    if (!top) return;
    if (e.key === 'Escape' && !top.persistent) {
      e.preventDefault();
      closeLayer(top);
      return;
    }
    if (e.key === 'Tab') {
      var items = focusables(top.el);
      if (!items.length) { e.preventDefault(); return; }
      var first = items[0];
      var last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !top.el.contains(document.activeElement))) {
        e.preventDefault(); last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !top.el.contains(document.activeElement))) {
        e.preventDefault(); first.focus();
      }
    }
  });

  /* ---------- tema ---------- */
  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    var meta = $('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0E1622' : '#003060');
  }

  var themeBtn = $('[data-nc-theme-toggle]');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      store.set('nc:theme', next);
      applyTheme(next);
    });
  }

  // Preferência "Seguir sistema" (nenhum valor salvo) acompanha mudanças do SO.
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (ev) {
      var saved = store.get('nc:theme');
      if (saved !== 'light' && saved !== 'dark') applyTheme(ev.matches ? 'dark' : 'light');
    });
  }

  /* ---------- sidebar ---------- */
  var mobileQuery = window.matchMedia('(max-width: 899px)');
  var sidebar = $('#nc-sidebar');
  var scrim = $('.nc-scrim');
  var collapseBtn = $('[data-nc-sidebar-toggle]');

  function syncCollapseLabel() {
    if (!collapseBtn) return;
    var collapsed = root.getAttribute('data-sidebar') === 'collapsed';
    var label = collapsed ? 'Expandir menu' : 'Recolher menu';
    collapseBtn.setAttribute('aria-label', label);
    collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    collapseBtn.title = label + ' ( [ )';
  }

  function toggleSidebar() {
    var next = root.getAttribute('data-sidebar') === 'collapsed' ? 'expanded' : 'collapsed';
    root.setAttribute('data-sidebar', next);
    store.set('nc:sidebar', next);
    syncCollapseLabel();
  }

  var navLayer = {
    el: sidebar,
    show: function () { root.setAttribute('data-nav-open', 'true'); if (scrim) scrim.hidden = false; },
    hide: function () { root.removeAttribute('data-nav-open'); if (scrim) scrim.hidden = true; },
    initial: function () { return $('.nc-nav-item.is-active', sidebar) || $('.nc-nav-item', sidebar); }
  };

  if (collapseBtn) collapseBtn.addEventListener('click', toggleSidebar);
  $$('[data-nc-sidebar-open]').forEach(function (b) {
    b.addEventListener('click', function () { openLayer(navLayer); });
  });
  $$('[data-nc-sidebar-close]').forEach(function (b) {
    b.addEventListener('click', function () { closeLayer(navLayer); });
  });
  mobileQuery.addEventListener('change', function () { closeLayer(navLayer); });
  syncCollapseLabel();

  /* ---------- modal (aviso / erro / confirmação) ---------- */
  var modalEl = $('[data-nc-modal]');
  var modalCard = modalEl && $('.nc-modal', modalEl);
  var modalOk = modalEl && $('[data-nc-modal-ok]', modalEl);
  var modalCancel = modalEl && $('[data-nc-modal-cancel]', modalEl);
  var modalResolve = null;
  var modalField = modalEl && $('[data-nc-modal-field]', modalEl);
  var modalInput = modalEl && $('[data-nc-modal-input]', modalEl);
  var modalFieldError = modalEl && $('[data-nc-modal-field-error]', modalEl);
  var activeField = null;

  var modalLayer = modalEl && {
    el: modalCard,
    show: function () { modalEl.hidden = false; },
    hide: function () {
      modalEl.hidden = true;
      var r = modalResolve; modalResolve = null;
      if (r) r(false); // fechar por Esc/fundo = cancelar
    },
    initial: function () { return activeField ? modalInput : modalOk; }
  };

  var TONES = {
    info: 'Informação', success: 'Sucesso', warning: 'Atenção',
    danger: 'Não foi possível concluir', confirm: 'Confirmação'
  };

  function showModal(opts) {
    if (!modalEl) return Promise.resolve(false);
    if (layers.indexOf(modalLayer) !== -1) closeLayer(modalLayer);
    var tone = opts.tone || 'info';
    modalCard.setAttribute('data-tone', tone === 'confirm' ? 'warning' : tone);
    $('[data-nc-modal-kicker]', modalEl).textContent = TONES[tone] || TONES.info;
    $('[data-nc-modal-title]', modalEl).textContent = opts.title || 'Aviso';
    $('[data-nc-modal-message]', modalEl).textContent = opts.message || '';
    modalOk.textContent = opts.okLabel || 'Entendi';
    modalOk.className = 'nc-btn ' + (opts.danger ? 'nc-btn-danger' : 'nc-btn-primary');
    modalCancel.hidden = !opts.cancel;
    modalCancel.textContent = opts.cancelLabel || 'Cancelar';
    activeField = opts.field || null;
    modalField.hidden = !activeField;
    modalFieldError.hidden = true;
    modalInput.value = '';
    if (activeField) {
      $('[data-nc-modal-field-label]', modalEl).textContent = activeField.label || 'Motivo';
      modalInput.placeholder = activeField.placeholder || '';
    }
    return new Promise(function (resolve) {
      modalResolve = resolve;
      openLayer(modalLayer);
    });
  }

  if (modalEl) {
    modalOk.addEventListener('click', function () {
      var value = true;
      if (activeField) {
        value = modalInput.value.trim();
        if (activeField.required && !value) {
          modalFieldError.textContent = activeField.requiredMessage || 'Preencha este campo para continuar.';
          modalFieldError.hidden = false;
          modalInput.focus();
          return;
        }
      }
      var r = modalResolve; modalResolve = null;
      closeLayer(modalLayer);
      if (r) r(value);
    });
    modalCancel.addEventListener('click', function () { closeLayer(modalLayer); });
    modalEl.addEventListener('mousedown', function (e) {
      if (e.target === modalEl && modalCancel.hidden === false) closeLayer(modalLayer);
    });
  }

  function alertModal(title, message, tone) {
    return showModal({ title: title, message: message, tone: tone || 'danger' });
  }

  /* ---------- toast ---------- */
  var toasts = $('[data-nc-toasts]');
  function toast(message, type) {
    if (!toasts || !message) return;
    var el = document.createElement('div');
    el.className = 'nc-toast';
    el.setAttribute('data-type', type || 'success');
    var text = document.createElement('div');
    text.className = 'nc-toast-text';
    var strong = document.createElement('strong');
    strong.textContent = { success: 'Sucesso', warning: 'Atenção', danger: 'Erro', info: 'Informação' }[type || 'success'] || 'Informação';
    var span = document.createElement('span');
    span.textContent = message;
    text.appendChild(strong); text.appendChild(span);
    var close = document.createElement('button');
    close.type = 'button'; close.className = 'nc-icon-btn'; close.setAttribute('aria-label', 'Fechar notificação');
    close.textContent = '×';
    function remove() { if (el.parentNode) el.parentNode.removeChild(el); }
    close.addEventListener('click', remove);
    el.appendChild(text); el.appendChild(close);
    toasts.appendChild(el);
    window.setTimeout(remove, 6000);
  }

  // Toast disparado pelo servidor via HX-Trigger: {"nc:toast":{"type":"success","message":"..."}}
  document.addEventListener('nc:toast', function (e) {
    var d = e.detail || {};
    if (d.type === 'danger' || d.type === 'error') alertModal('Não foi possível concluir', d.message, 'danger');
    else toast(d.message, d.type);
  });

  // Mensagem "flash" da sessão (sucesso → toast; erro → modal).
  var flash = $('[data-nc-flash]');
  if (flash) {
    var ft = flash.getAttribute('data-type');
    var fm = flash.getAttribute('data-message');
    if (ft === 'danger' || ft === 'error') alertModal('Não foi possível concluir', fm, 'danger');
    else if (ft === 'warning') alertModal('Atenção', fm, 'warning');
    else toast(fm, ft === 'success' ? 'success' : 'info');
  }

  /* ---------- Central de Importação: recalcula os valores a mapear (sem expor o token CSRF na URL) ---------- */
  function mapRefresh(form) {
    var data = new FormData(form);
    var params = new URLSearchParams();
    data.forEach(function (value, key) {
      if (key === 'csrf_token') return;
      params.append(key, String(value));
    });
    window.location.href = form.getAttribute('data-nc-refresh-url') + '?' + params.toString();
  }
  document.addEventListener('change', function (e) {
    var sel = e.target.closest && e.target.closest('select[data-nc-map]');
    if (sel && sel.form) mapRefresh(sel.form);
  });
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-nc-map-refresh]');
    if (btn && btn.form) { e.preventDefault(); mapRefresh(btn.form); }
  });

  /* ---------- confirmação para ações destrutivas ---------- */
  // Elementos htmx com data-nc-confirm="mensagem": pedem confirmação em modal antes de enviar.
  document.addEventListener('htmx:confirm', function (e) {
    var elt = e.detail.elt;
    var msg = elt && elt.getAttribute && elt.getAttribute('data-nc-confirm');
    if (!msg) return;
    e.preventDefault();
    showModal({
      title: elt.getAttribute('data-nc-confirm-title') || 'Confirmar ação',
      message: msg, tone: 'confirm', cancel: true, danger: true,
      okLabel: elt.getAttribute('data-nc-confirm-ok') || 'Confirmar'
    }).then(function (ok) { if (ok) e.detail.issueRequest(true); });
  });

  // Links/botões comuns (sem htmx) com data-nc-confirm.
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-nc-confirm]');
    if (!el || el.hasAttribute('hx-post') || el.hasAttribute('hx-delete') || el.hasAttribute('hx-get')) return;
    if (el.dataset.ncConfirmed === '1') { delete el.dataset.ncConfirmed; return; }
    e.preventDefault();
    showModal({
      title: el.getAttribute('data-nc-confirm-title') || 'Confirmar ação',
      message: el.getAttribute('data-nc-confirm'), tone: 'confirm', cancel: true, danger: true,
      okLabel: el.getAttribute('data-nc-confirm-ok') || 'Confirmar'
    }).then(function (ok) {
      if (!ok) return;
      el.dataset.ncConfirmed = '1';
      el.click();
    });
  });

  /* ---------- drawer ---------- */
  var drawer = $('[data-nc-drawer]');
  var drawerBackdrop = $('.nc-drawer-backdrop');
  var drawerBody = $('[data-nc-drawer-body]');
  var drawerTitle = $('[data-nc-drawer-title]');

  var drawerLayer = drawer && {
    el: drawer,
    show: function () {
      drawer.hidden = false; drawerBackdrop.hidden = false;
      drawer.setAttribute('aria-hidden', 'false');
    },
    hide: function () {
      drawer.hidden = true; drawerBackdrop.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
      drawerBody.innerHTML = '';
      if (drawerPrevUrl) { try { window.history.replaceState(null, '', drawerPrevUrl); } catch (_) { /* ignora */ } drawerPrevUrl = null; }
      if (drawerDirty) { drawerDirty = false; window.location.reload(); }
    }
  };

  function openDrawer(title) {
    if (!drawerLayer) return;
    if (title) drawerTitle.textContent = title;
    if (layers.indexOf(drawerLayer) === -1) openLayer(drawerLayer);
  }
  function closeDrawer() { if (drawerLayer) closeLayer(drawerLayer); }

  // Fichas abrem com hx-push-url; ao fechar o drawer, devolve a URL da lista.
  var drawerPrevUrl = null;
  var drawerDirty = false;
  // Ações dentro da ficha (ganhar, perder, notas…) marcam a lista como desatualizada: recarrega ao fechar.
  document.addEventListener('nc:dirty', function () { drawerDirty = true; });
  document.addEventListener('htmx:beforeRequest', function (e) {
    if (drawerBody && e.detail.target === drawerBody && e.detail.elt.hasAttribute('hx-push-url') && !drawerPrevUrl) {
      drawerPrevUrl = window.location.href;
    }
  });

  $$('[data-nc-drawer-close]').forEach(function (b) { b.addEventListener('click', closeDrawer); });

  // Qualquer conteúdo carregado dentro do corpo do drawer o abre.
  // O fragmento pode declarar o título com data-drawer-title na raiz.
  document.addEventListener('htmx:afterSwap', function (e) {
    if (!drawerBody || e.detail.target !== drawerBody) return;
    var holder = drawerBody.querySelector('[data-drawer-title]');
    openDrawer(holder ? holder.getAttribute('data-drawer-title') : '');
  });

  /* ---------- busca global ⌘K / Ctrl+K ---------- */
  var searchEl = $('[data-nc-search]');
  var searchInput = $('[data-nc-search-input]');
  var searchResults = $('#nc-search-results');
  var searchCard = searchEl && $('.nc-search', searchEl);

  var searchLayer = searchEl && {
    el: searchCard,
    show: function () { searchEl.hidden = false; },
    hide: function () { searchEl.hidden = true; },
    initial: function () { return searchInput; }
  };

  function openSearch() {
    if (!searchLayer || layers.indexOf(searchLayer) !== -1) return;
    openLayer(searchLayer);
    searchInput.select();
  }

  $$('[data-nc-search-open]').forEach(function (b) { b.addEventListener('click', openSearch); });
  if (searchEl) {
    searchEl.addEventListener('mousedown', function (e) { if (e.target === searchEl) closeLayer(searchLayer); });
  }

  function searchItems() { return $$('.nc-search-item', searchResults); }
  function selectSearch(index) {
    var items = searchItems();
    if (!items.length) { searchInput.removeAttribute('aria-activedescendant'); return; }
    var i = (index + items.length) % items.length;
    items.forEach(function (it, n) {
      it.setAttribute('role', 'option');
      it.id = it.id || 'nc-search-opt-' + n;
      it.setAttribute('aria-selected', n === i ? 'true' : 'false');
    });
    searchInput.setAttribute('aria-activedescendant', items[i].id);
    items[i].scrollIntoView({ block: 'nearest' });
  }
  function selectedSearchIndex() {
    return searchItems().findIndex(function (it) { return it.getAttribute('aria-selected') === 'true'; });
  }

  if (searchInput) {
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); selectSearch(selectedSearchIndex() + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); selectSearch(selectedSearchIndex() - 1); }
      else if (e.key === 'Enter') {
        var items = searchItems();
        var i = selectedSearchIndex();
        if (items.length && items[i >= 0 ? i : 0]) { e.preventDefault(); items[i >= 0 ? i : 0].click(); }
      }
    });
  }
  document.addEventListener('htmx:afterSwap', function (e) {
    if (e.detail.target === searchResults) selectSearch(0);
  });

  var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
  $$('[data-nc-kbd]').forEach(function (k) { k.textContent = isMac ? '⌘ K' : 'Ctrl K'; });

  function typing(target) {
    return target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (searchLayer && layers.indexOf(searchLayer) !== -1) closeLayer(searchLayer); else openSearch();
      return;
    }
    if (e.key === '[' && !e.ctrlKey && !e.metaKey && !e.altKey && !typing(e.target) && !layers.length && !mobileQuery.matches) {
      e.preventDefault();
      toggleSidebar();
    }
  });

  /* ---------- abas internas das fichas ---------- */
  function selectTab(tab) {
    var list = tab.closest('[data-nc-tabs]');
    var scope = list.parentNode;
    $$('[role="tab"]', list).forEach(function (t) {
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
      t.classList.toggle('is-active', on);
    });
    $$('[data-nc-panel]', scope).forEach(function (p) { p.hidden = p.id !== tab.getAttribute('aria-controls'); });
  }
  document.addEventListener('click', function (e) {
    var tab = e.target.closest('[data-nc-tabs] [role="tab"]');
    if (tab) selectTab(tab);
  });
  document.addEventListener('keydown', function (e) {
    var tab = e.target.closest && e.target.closest('[data-nc-tabs] [role="tab"]');
    if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
    var tabs = $$('[role="tab"]', tab.closest('[data-nc-tabs]'));
    var next = tabs[(tabs.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    e.preventDefault(); next.focus(); selectTab(next);
  });

  /* ---------- filtros que enviam o formulário ao mudar ---------- */
  document.addEventListener('change', function (e) {
    var el = e.target.closest('[data-nc-autosubmit]');
    if (el && el.form) el.form.submit();
  });

  /* ---------- preferências de aparência (Configurações) ---------- */
  function currentPref(key) {
    if (key === 'theme') { var t = store.get('nc:theme'); return t === 'light' || t === 'dark' ? t : 'system'; }
    if (key === 'density') return store.get('nc:density') === 'compact' ? 'compact' : 'comfortable';
    return store.get('nc:sidebar') === 'collapsed' ? 'collapsed' : 'expanded';
  }
  function applyPref(key, value) {
    if (key === 'theme') {
      if (value === 'system') {
        store.remove('nc:theme');
        applyTheme(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      } else { store.set('nc:theme', value); applyTheme(value); }
    } else if (key === 'density') {
      store.set('nc:density', value); root.setAttribute('data-density', value);
    } else {
      store.set('nc:sidebar', value); root.setAttribute('data-sidebar', value); syncCollapseLabel();
    }
  }
  $$('input[data-nc-pref]').forEach(function (input) {
    var key = input.getAttribute('data-nc-pref');
    input.checked = input.value === currentPref(key);
    input.addEventListener('change', function () { if (input.checked) applyPref(key, input.value); });
  });

  /* ---------- atualizar ---------- */
  $$('[data-nc-refresh]').forEach(function (b) {
    b.addEventListener('click', function () { window.location.reload(); });
  });

  /* ---------- aviso de expiração de sessão (mesma lógica da raiz) ---------- */
  var timeoutMs = Number(body.dataset.sessionTimeoutMs || 0);
  var warningMs = Number(body.dataset.warningBeforeTimeoutMs || 0);
  var refreshUrl = body.dataset.sessionRefreshUrl || '/session_refresh.php';
  var logoutUrl = body.dataset.logoutUrl || '/logout.php';
  var scope = body.dataset.securityScope || 'dnb_crm';
  var sessionModal = $('[data-nc-session-modal]');
  var sessionCountdown = $('[data-nc-session-countdown]');
  var sessionContinue = $('[data-nc-session-continue]');
  var lastActivityKey = scope + ':lastActivityTimestamp';

  var warningVisible = false;
  var logoutStarted = false;
  var lastActivity = Date.now();
  var activityThrottle = 0;

  var sessionLayer = sessionModal && {
    el: $('.nc-modal', sessionModal),
    persistent: true,
    show: function () { sessionModal.hidden = false; },
    hide: function () { sessionModal.hidden = true; },
    initial: function () { return sessionContinue; }
  };

  function remember(ts) {
    try { window.sessionStorage.setItem(lastActivityKey, String(ts)); } catch (_) { /* ignora */ }
  }

  function markActivity() {
    if (warningVisible || logoutStarted) return;
    var now = Date.now();
    if (now - activityThrottle < 1000) return;
    activityThrottle = now;
    lastActivity = now;
    remember(now);
  }

  function logoutForInactivity() {
    if (logoutStarted) return;
    logoutStarted = true;
    var target = window.location.pathname + window.location.search + window.location.hash;
    window.location.assign(logoutUrl + (logoutUrl.indexOf('?') >= 0 ? '&' : '?') +
      'automatic=1&redirect_to=' + encodeURIComponent(target));
  }

  function showWarning() {
    if (!sessionLayer || warningVisible) return;
    warningVisible = true;
    openLayer(sessionLayer);
  }
  function hideWarning() {
    if (!sessionLayer) return;
    warningVisible = false;
    closeLayer(sessionLayer);
  }

  function continueSession() {
    if (!sessionContinue || sessionContinue.disabled) return;
    var label = sessionContinue.textContent;
    sessionContinue.disabled = true;
    sessionContinue.textContent = 'Renovando sessão…';
    var data = new URLSearchParams();
    data.set('csrf_token', csrf());
    window.fetch(refreshUrl, {
      method: 'POST', body: data, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (payload) {
        if (!res.ok || !payload || !payload.ok) throw new Error('expired');
        lastActivity = Date.now();
        remember(lastActivity);
        hideWarning();
      });
    }).catch(logoutForInactivity).then(function () {
      sessionContinue.disabled = false;
      sessionContinue.textContent = label;
    });
  }

  if (timeoutMs > 0 && warningMs > 0 && warningMs < timeoutMs) {
    remember(lastActivity);
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (name) {
      window.addEventListener(name, markActivity, { passive: true });
    });
    if (sessionContinue) sessionContinue.addEventListener('click', continueSession);

    window.setInterval(function () {
      if (logoutStarted) return;
      var remaining = timeoutMs - (Date.now() - lastActivity);
      if (remaining <= 0) { logoutForInactivity(); return; }
      if (remaining <= warningMs) {
        showWarning();
        if (sessionCountdown) sessionCountdown.textContent = Math.max(0, Math.ceil(remaining / 1000)) + 's';
      } else if (warningVisible) {
        hideWarning();
      }
    }, 1000);
  }

  /* ---------- htmx: CSRF no corpo e tratamento de erros ---------- */
  document.addEventListener('htmx:configRequest', function (e) {
    var verb = (e.detail.verb || 'get').toLowerCase();
    if (verb !== 'get') e.detail.parameters['csrf_token'] = csrf();
  });

  // Respostas 422 podem trazer o formulário com os erros: deixa o htmx trocar o conteúdo.
  document.addEventListener('htmx:beforeSwap', function (e) {
    if (e.detail.xhr && e.detail.xhr.status === 422) {
      e.detail.shouldSwap = true;
      e.detail.isError = false;
    }
  });

  document.addEventListener('htmx:responseError', function (e) {
    var xhr = e.detail.xhr;
    var status = xhr ? xhr.status : 0;
    if (status === 419) {
      showModal({
        title: 'Sessão expirada', tone: 'warning', okLabel: 'Atualizar página',
        message: 'Sessão expirada, atualize a página e tente novamente.'
      }).then(function () { window.location.reload(); });
    } else if (status === 401) {
      logoutForInactivity();
    } else if (status === 403) {
      alertModal('Acesso negado', 'Você não tem permissão para realizar esta ação.', 'danger');
    } else if (status === 404) {
      alertModal('Não encontrado', 'O item solicitado não existe mais. Atualize a página.', 'danger');
    } else {
      var text = '';
      try { text = (xhr.responseText || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 240); } catch (_) { /* ignora */ }
      alertModal('Não foi possível concluir', text || 'Ocorreu um erro inesperado. Tente novamente.', 'danger');
    }
  });

  document.addEventListener('htmx:sendError', function () {
    alertModal('Sem conexão', 'Não foi possível falar com o servidor. Verifique sua internet e tente novamente.', 'danger');
  });

  /* ---------- POST com CSRF no corpo (fetch) ---------- */
  function post(url, data) {
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    body.set('csrf_token', csrf());
    return window.fetch(url, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (res) {
      return res.text().then(function (text) {
        if (res.status === 419) {
          showModal({ title: 'Sessão expirada', tone: 'warning', okLabel: 'Atualizar página',
            message: 'Sessão expirada, atualize a página e tente novamente.' }).then(function () { window.location.reload(); });
          return { ok: false, handled: true, text: text };
        }
        if (res.status === 401) { logoutForInactivity(); return { ok: false, handled: true, text: text }; }
        return { ok: res.ok, status: res.status, text: text };
      });
    }, function () {
      alertModal('Sem conexão', 'Não foi possível falar com o servidor. Verifique sua internet e tente novamente.', 'danger');
      return { ok: false, handled: true, text: '' };
    });
  }

  /* ---------- kanban (SortableJS) ---------- */
  function moneyShort(v) {
    var a = Math.abs(v);
    var f = function (n) { return n.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); };
    if (a >= 1000000) return 'R$ ' + f(v / 1000000) + ' mi';
    if (a >= 1000) return 'R$ ' + f(v / 1000) + ' mil';
    return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function initKanban() {
    var board = $('[data-nc-kanban]');
    if (!board || !window.Sortable) return;
    var moveUrl = board.getAttribute('data-move-url');
    var actionUrl = board.getAttribute('data-action-url');

    function recount() {
      $$('.nc-kanban-col', board).forEach(function (col) {
        var cards = $$('.nc-deal-card', col);
        var sum = cards.reduce(function (t, c) { return t + (parseFloat(c.getAttribute('data-amount')) || 0); }, 0);
        $('[data-count]', col).textContent = String(cards.length);
        $('[data-sum]', col).textContent = moneyShort(sum);
      });
    }

    function restore(evt) {
      evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] || null);
      recount();
      refreshBoard(board);
    }

    var common = { group: 'deals', animation: 150, ghostClass: 'sortable-ghost', delay: 150, delayOnTouchOnly: true, fallbackTolerance: 4 };

    $$('.nc-kanban-list', board).forEach(function (list) {
      Sortable.create(list, Object.assign({}, common, {
        onStart: function () { document.body.classList.add('nc-dragging'); },
        onEnd: function (evt) {
          document.body.classList.remove('nc-dragging');
          if (evt.to.hasAttribute('data-zone')) return; // tratado em onAdd da zona
          if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
          var order = $$('.nc-deal-card', evt.to).map(function (c) { return c.getAttribute('data-id'); }).join(',');
          recount();
          refreshBoard(board);
          post(moveUrl, { id: evt.item.getAttribute('data-id'), stage_id: evt.to.getAttribute('data-stage-id'), order: order })
            .then(function (r) {
              if (r.ok) return;
              restore(evt);
              if (!r.handled) alertModal('Não foi possível mover', r.text || 'Tente novamente.', 'danger');
            });
        }
      }));
    });

    $$('.nc-zone-list', board.parentNode).forEach(function (zone) {
      Sortable.create(zone, {
        group: { name: 'deals', pull: false, put: true },
        sort: false,
        onAdd: function (evt) {
          var item = evt.item;
          var id = item.getAttribute('data-id');
          var kind = zone.getAttribute('data-zone');
          restore(evt); // o card volta à coluna de origem até a confirmação do servidor
          function done(message) {
            if (item.parentNode) item.parentNode.removeChild(item);
            recount();
            toast(message, 'success');
          }
          function fail(r) { if (!r.handled) alertModal('Não foi possível concluir', r.text || 'Tente novamente.', 'danger'); }
          if (kind === 'won') {
            post(actionUrl, { id: id, action: 'status_won', json: '1' }).then(function (r) {
              if (r.ok) done('Oportunidade marcada como Ganha.'); else fail(r);
            });
          } else {
            showModal({
              title: 'Registrar perda', tone: 'danger', cancel: true, danger: true, okLabel: 'Registrar perda',
              message: 'Informe o motivo da perda para manter o rastreio comercial.',
              field: { label: 'Motivo da perda', required: true, placeholder: 'Descreva por que a oportunidade foi perdida…',
                requiredMessage: 'Informe o motivo da perda para manter o rastreio comercial.' }
            }).then(function (reason) {
              if (!reason) return;
              post(actionUrl, { id: id, action: 'status_lost', loss_reason: reason, json: '1' }).then(function (r) {
                if (r.ok) done('Perda registrada com o motivo.'); else fail(r);
              });
            });
          }
        }
      });
    });
  }
  /* Atualiza contagens, probabilidade da etapa e estado das setas ← → de todos os cards. */
  function refreshBoard(board) {
    var lists = $$('.nc-kanban-list', board);
    $$('.nc-kanban-col', board).forEach(function (col) {
      var cards = $$('.nc-deal-card', col);
      var sum = cards.reduce(function (t, c) { return t + (parseFloat(c.getAttribute('data-amount')) || 0); }, 0);
      $('[data-count]', col).textContent = String(cards.length);
      $('[data-sum]', col).textContent = moneyShort(sum);
    });
    lists.forEach(function (list, i) {
      var p = list.getAttribute('data-prob');
      $$('.nc-deal-card', list).forEach(function (card) {
        var slot = $('[data-prob-slot]', card);
        if (slot) { slot.textContent = p ? p + '% de chance' : ''; slot.hidden = !p; }
        var prev = $('[data-nc-move="prev"]', card);
        var next = $('[data-nc-move="next"]', card);
        if (prev) prev.disabled = i === 0;
        if (next) next.disabled = i === lists.length - 1;
      });
    });
  }

  initKanban();
  $$('[data-nc-kanban]').forEach(refreshBoard);

  /* ---------- kanban: setas ← → para mover de etapa (teclado e celular) ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-nc-move]');
    if (!btn || btn.disabled) return;
    var board = btn.closest('[data-nc-kanban]');
    var card = btn.closest('.nc-deal-card');
    if (!board || !card) return;
    var lists = $$('.nc-kanban-list', board);
    var from = card.parentNode;
    var to = lists[lists.indexOf(from) + (btn.getAttribute('data-nc-move') === 'next' ? 1 : -1)];
    if (!to) return;
    var oldIndex = Array.prototype.indexOf.call(from.children, card);
    to.insertBefore(card, to.firstChild);
    var order = $$('.nc-deal-card', to).map(function (c) { return c.getAttribute('data-id'); }).join(',');
    refreshBoard(board);
    btn.focus();
    post(board.getAttribute('data-move-url'), { id: card.getAttribute('data-id'), stage_id: to.getAttribute('data-stage-id'), order: order })
      .then(function (r) {
        if (r.ok) { toast('Movida para ' + (to.getAttribute('data-stage-name') || 'a etapa') + '.', 'success'); return; }
        from.insertBefore(card, from.children[oldIndex] || null);
        refreshBoard(board);
        if (!r.handled) alertModal('Não foi possível mover', r.text || 'Tente novamente.', 'danger');
      });
  });

  /* ---------- filtro local por texto (ex.: "Buscar representante...") ---------- */
  // <input data-nc-filter="#lista"> esconde os [data-nc-filter-item] cujo data-nc-filter-text não contém o termo.
  document.addEventListener('input', function (e) {
    var input = e.target.closest && e.target.closest('[data-nc-filter]');
    if (!input) return;
    var scope = document.querySelector(input.getAttribute('data-nc-filter'));
    if (!scope) return;
    var term = input.value.trim().toLowerCase();
    var shown = 0;
    $$('[data-nc-filter-item]', scope).forEach(function (item) {
      var hit = !term || (item.getAttribute('data-nc-filter-text') || item.textContent.toLowerCase()).indexOf(term) !== -1;
      item.hidden = !hit;
      if (hit) shown++;
    });
    var empty = scope.querySelector('[data-nc-filter-empty]');
    if (empty) empty.hidden = shown > 0 || !term;
  });

  /* ---------- tooltip dos gráficos SVG (Evolução de Faturamento) ---------- */
  // Cada zona de passagem do mouse (.nc-evo-hit) traz data-tip="Título|linha 1|linha 2".
  (function initChartTips() {
    var tip = null;
    function ensureTip(wrap) {
      if (!tip) { tip = document.createElement('div'); tip.className = 'nc-evo-tip'; tip.setAttribute('role', 'status'); }
      if (tip.parentNode !== wrap) wrap.appendChild(tip);
      return tip;
    }
    function show(hit) {
      var wrap = hit.closest('.nc-evo-wrap');
      if (!wrap) return;
      var parts = (hit.getAttribute('data-tip') || '').split('|');
      var t = ensureTip(wrap);
      t.textContent = '';
      parts.forEach(function (p, i) {
        var node = document.createElement(i === 0 ? 'strong' : 'span');
        node.textContent = p;
        t.appendChild(node);
        if (i > 0 && i < parts.length - 1) t.appendChild(document.createElement('br'));
      });
      var box = wrap.getBoundingClientRect();
      var r = hit.getBoundingClientRect();
      var x = r.left - box.left + r.width / 2;
      t.style.left = Math.min(Math.max(x, 70), Math.max(70, box.width - 70)) + 'px';
      t.style.top = Math.max(r.top - box.top + 12, 30) + 'px';
      t.hidden = false;
      $$('.nc-evo-hit.is-active', wrap).forEach(function (n) { n.classList.remove('is-active'); });
      hit.classList.add('is-active');
    }
    function hide(wrap) {
      if (tip) tip.hidden = true;
      if (wrap) $$('.nc-evo-hit.is-active', wrap).forEach(function (n) { n.classList.remove('is-active'); });
    }
    document.addEventListener('pointerover', function (e) {
      var hit = e.target.closest && e.target.closest('.nc-evo-hit');
      if (hit) show(hit);
    });
    document.addEventListener('pointerout', function (e) {
      var hit = e.target.closest && e.target.closest('.nc-evo-hit');
      if (hit && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.nc-evo-hit'))) hide(hit.closest('.nc-evo-wrap'));
    });
  })();

  /* ---------- seletor de empresa com autocomplete ---------- */
  // Só o ID escolhido (campo oculto) é enviado: o texto digitado serve apenas para buscar.
  document.addEventListener('click', function (e) {
    var item = e.target.closest && e.target.closest('.nc-picker-item');
    if (item) {
      var picker = item.closest('[data-nc-picker]');
      if (!picker) return;
      picker.querySelector('[data-nc-picker-value]').value = item.getAttribute('data-id') || '';
      picker.querySelector('[data-nc-picker-label]').textContent = item.getAttribute('data-label') || '';
      picker.querySelector('[data-nc-picker-chosen]').hidden = false;
      picker.querySelector('[data-nc-picker-search]').hidden = true;
      picker.querySelector('.nc-picker-list').innerHTML = '';
      picker.querySelector('[data-nc-picker-input]').value = '';
      return;
    }
    var clear = e.target.closest && e.target.closest('[data-nc-picker-clear]');
    if (clear) {
      var p = clear.closest('[data-nc-picker]');
      if (!p) return;
      p.querySelector('[data-nc-picker-value]').value = '';
      p.querySelector('[data-nc-picker-chosen]').hidden = true;
      p.querySelector('[data-nc-picker-search]').hidden = false;
      p.querySelector('[data-nc-picker-input]').focus();
    }
  });
  // Enter no campo de busca escolhe a primeira sugestão (e não envia o formulário).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.closest || !e.target.closest('[data-nc-picker-input]')) return;
    e.preventDefault();
    var first = e.target.closest('[data-nc-picker]').querySelector('.nc-picker-item');
    if (first) first.click();
  });

  /* ---------- "selecionar todos" em tabelas com caixas de seleção ---------- */
  document.addEventListener('change', function (e) {
    var all = e.target.closest && e.target.closest('[data-nc-check-all]');
    if (!all) return;
    var scope = document.querySelector(all.getAttribute('data-nc-check-all'));
    if (!scope) return;
    $$('input[type="checkbox"][name="ids[]"]', scope).forEach(function (c) { c.checked = all.checked; });
  });

  /* ---------- API pública (usada pelas telas das próximas fases) ---------- */
  window.NC = {
    csrf: csrf,
    post: post,
    toast: toast,
    alert: alertModal,
    confirm: function (opts) { return showModal(Object.assign({ tone: 'confirm', cancel: true, danger: true, okLabel: 'Confirmar' }, opts)); },
    drawer: { open: openDrawer, close: closeDrawer },
    store: store
  };
})();
