(function () {
  'use strict';

  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = '/assets/crm-enhancements.css?v=3.4.1';
  document.head.appendChild(css);

  const normalize = (value) => String(value || '').trim().toLowerCase();
  const isAdmin = () => Boolean(document.querySelector('a[href*="usuarios.php"],a[href*="configuracoes.php"]'));
  const onQuotesPage = () => location.pathname.endsWith('/orcamentos.php') || location.pathname === '/orcamentos.php';

  function addProfileLink() {
    const user = document.querySelector('.dnb-user');
    const identity = user?.querySelector('.dnb-user__identity');
    if (!user || !identity || user.querySelector('.dnb-profile-link')) return;
    const link = document.createElement('a');
    link.href = '/perfil.php';
    link.className = 'dnb-profile-link';
    link.textContent = 'Meu perfil';
    identity.appendChild(link);
    const avatar = user.querySelector('.dnb-user__avatar');
    if (avatar) {
      avatar.style.cursor = 'pointer';
      avatar.title = 'Abrir meu perfil';
      avatar.addEventListener('click', () => { location.href = '/perfil.php'; });
    }
  }

  function hideAdminOnlyNavigation() {
    if (isAdmin()) return;
    document.querySelectorAll('a[href*="auditoria.php"]').forEach((link) => {
      const row = link.closest('.dnb-nav__row');
      (row || link).classList.add('dnb-card-hidden-by-role');
    });
  }

  const tutorials = {
    'painel.php': ['Painel · visão geral', 'Acompanhe o que é relevante para o seu papel no CRM.', '<h3>Como usar</h3><ol><li>Escolha o mês de referência no topo.</li><li>Vendedores visualizam somente o próprio Farol de metas.</li><li>ADM e Supervisor visualizam os KPIs automáticos de bonificação.</li><li>Corrija divergências sempre no registro de origem.</li></ol>'],
    'orcamentos.php': ['Orçamentos · oportunidades', 'Crie, filtre e movimente oportunidades pelo funil comercial.', '<h3>Fluxo recomendado</h3><ol><li>Cadastre a oportunidade no bloco superior.</li><li>Use os filtros do Kanban; o quadro é atualizado sem recarregar a página.</li><li>Arraste o cartão ou use o seletor da etapa.</li><li>Importação Cotador e RD Station são exclusivas do ADM.</li></ol>'],
    'pedidos.php': ['Pedidos · vendas e faturamento', 'Registre a evolução comercial depois que a oportunidade vira pedido.', '<h3>O que fazer</h3><ol><li>Confirme responsável, modalidade e valores.</li><li>Atualize faturamento e informações financeiras conforme o processo real.</li><li>Pedidos faturados elegíveis alimentam a comissão.</li><li>Evite duplicidade: atualize o registro já existente.</li></ol>'],
    'visitas.php': ['Visitas · atividade comercial', 'Registre visitas e prospecções que fazem parte dos indicadores.', '<h3>Boas práticas</h3><ol><li>Registre a atividade no dia em que ocorreu.</li><li>Vincule corretamente vendedor e tipo.</li><li>Use observações objetivas.</li><li>Atividades elegíveis entram nos indicadores do CRM.</li></ol>']
  };

  function tutorialDialog() {
    let dialog = document.querySelector('[data-crm-info-dialog]');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.className = 'dnb-info-dialog';
    dialog.dataset.crmInfoDialog = '1';
    dialog.innerHTML = '<div class="dnb-info-dialog__head"><div><strong data-info-title></strong><small data-info-lead></small></div><button type="button" class="dnb-info-dialog__close" aria-label="Fechar">×</button></div><div class="dnb-info-dialog__body" data-info-body></div>';
    document.body.appendChild(dialog);
    dialog.querySelector('.dnb-info-dialog__close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    return dialog;
  }

  function openTutorial(file) {
    const data = tutorials[file];
    if (!data) return;
    const dialog = tutorialDialog();
    dialog.querySelector('[data-info-title]').textContent = data[0];
    dialog.querySelector('[data-info-lead]').textContent = data[1];
    dialog.querySelector('[data-info-body]').innerHTML = data[2];
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }

  function addCrmInfoButtons() {
    const group = Array.from(document.querySelectorAll('.dnb-nav__group')).find((item) => normalize(item.querySelector('.dnb-nav__eyebrow')?.textContent) === 'crm');
    if (!group) return;
    group.querySelectorAll(':scope > .dnb-nav__link').forEach((link) => {
      const file = new URL(link.href, location.href).pathname.split('/').pop().toLowerCase();
      if (!tutorials[file]) return;
      const row = document.createElement('div');
      row.className = 'dnb-nav__row';
      link.before(row);
      row.appendChild(link);
      const info = document.createElement('button');
      info.type = 'button';
      info.className = 'dnb-nav__info';
      info.textContent = 'i';
      info.title = 'Como funciona';
      info.setAttribute('aria-label', 'Como funciona ' + link.textContent.trim());
      info.addEventListener('click', () => openTutorial(file));
      row.appendChild(info);
    });
  }

  function findCardByText(text) {
    const target = normalize(text);
    const node = Array.from(document.querySelectorAll('span,h2,h3,strong')).find((item) => normalize(item.textContent).includes(target));
    return node?.closest('section,.bg-white.rounded-xl') || null;
  }

  function reorderOpportunityAboveFilters() {
    if (!onQuotesPage()) return;
    const filterHeading = Array.from(document.querySelectorAll('h2')).find((h) => normalize(h.textContent) === 'filtros do kanban');
    const filterCard = filterHeading?.closest('section,.bg-white.rounded-xl');
    const opportunityCard = findCardByText('nova oportunidade / orçamento');
    if (filterCard && opportunityCard && filterCard.parentNode === opportunityCard.parentNode) filterCard.before(opportunityCard);
  }

  function hideAdminOnlyOpportunityTools() {
    if (isAdmin() || !onQuotesPage()) return;
    ['importar do cotador dnb', 'sincronizar rd station crm'].forEach((label) => findCardByText(label)?.classList.add('dnb-card-hidden-by-role'));
  }

  function paramsFrom(form) {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => { if (typeof value === 'string') params.set(key, value); });
    return params;
  }

  function bindAjaxBoard(board, refresh) {
    if (!board || board.dataset.crmEnhanced === '1') return;
    board.dataset.crmEnhanced = '1';
    let dragged = null;
    board.querySelectorAll('[data-kanban-card]').forEach((card) => {
      card.addEventListener('dragstart', (event) => {
        dragged = card;
        card.classList.add('is-dragging');
        event.dataTransfer?.setData('text/plain', card.dataset.leadId || '');
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        board.querySelectorAll('[data-kanban-stage]').forEach((col) => col.classList.remove('is-drag-over'));
        dragged = null;
      });
    });
    board.querySelectorAll('[data-kanban-stage]').forEach((column) => {
      column.addEventListener('dragover', (event) => { if (dragged) { event.preventDefault(); column.classList.add('is-drag-over'); } });
      column.addEventListener('dragleave', (event) => { if (!column.contains(event.relatedTarget)) column.classList.remove('is-drag-over'); });
      column.addEventListener('drop', (event) => {
        event.preventDefault();
        column.classList.remove('is-drag-over');
        if (!dragged) return;
        const form = dragged.querySelector('[data-kanban-stage-form]');
        const select = form?.querySelector('[data-kanban-stage-select]');
        const stage = column.dataset.kanbanStage || '';
        if (!form || !select || select.value === stage || !Array.from(select.options).some((o) => o.value === stage)) return;
        select.value = stage;
        form.requestSubmit();
      });
    });
    board.addEventListener('submit', async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || normalize(form.method) !== 'post') return;
      event.preventDefault();
      const button = event.submitter;
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
      try {
        const response = await fetch(form.action || location.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
        if (!response.ok) throw new Error('Não foi possível atualizar a oportunidade.');
        await refresh(false);
      } catch (error) {
        alert(error.message || 'Não foi possível atualizar a oportunidade.');
      } finally {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
      }
    });
  }

  function initKanbanAjaxFilters() {
    if (!onQuotesPage()) return;
    const heading = Array.from(document.querySelectorAll('h2')).find((h) => normalize(h.textContent) === 'filtros do kanban');
    const form = heading?.closest('section,.bg-white.rounded-xl')?.querySelector('form[method="get"]');
    if (!form || form.dataset.crmAjax === '1') return;
    form.dataset.crmAjax = '1';
    const status = document.createElement('span');
    status.className = 'dnb-kanban-ajax-status';
    status.setAttribute('aria-live', 'polite');
    form.appendChild(status);
    let controller = null;
    let debounce = null;

    const refresh = async (updateUrl = true) => {
      controller?.abort();
      controller = new AbortController();
      const params = paramsFrom(form);
      const url = new URL(location.href);
      url.search = params.toString();
      form.classList.add('is-loading');
      status.textContent = 'Atualizando Kanban…';
      try {
        const response = await fetch(url, {credentials: 'same-origin', signal: controller.signal, headers: {'X-Requested-With': 'fetch'}});
        if (!response.ok) throw new Error('Falha ao atualizar os filtros.');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        const incoming = doc.querySelector('[data-opportunity-kanban]');
        const current = document.querySelector('[data-opportunity-kanban]');
        if (!incoming || !current) throw new Error('Kanban não encontrado na resposta.');
        const replacement = document.importNode(incoming, true);
        current.replaceWith(replacement);
        bindAjaxBoard(replacement, refresh);
        if (updateUrl) history.replaceState({}, '', url.pathname + '?' + params.toString());
        status.textContent = 'Filtros aplicados';
        setTimeout(() => { if (status.textContent === 'Filtros aplicados') status.textContent = ''; }, 1500);
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = error.message || 'Não foi possível atualizar.';
      } finally {
        form.classList.remove('is-loading');
      }
    };

    form.addEventListener('submit', (event) => { event.preventDefault(); refresh(true); });
    form.querySelectorAll('select,input:not([type="hidden"]):not([type="search"])').forEach((field) => field.addEventListener('change', () => refresh(true)));
    form.querySelectorAll('input[type="search"]').forEach((field) => field.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => refresh(true), 300);
    }));
    // O quadro inicial já foi inicializado por ui.js. Só reativamos drag/drop
    // nos quadros substituídos via AJAX, evitando listeners duplicados.
  }

  function field(label, name, type, value, hint) {
    const wrap = document.createElement('label');
    const title = document.createElement('span');
    title.textContent = label;
    const input = document.createElement('input');
    input.name = name;
    input.type = type;
    input.value = value || '';
    input.autocomplete = type === 'password' ? 'new-password' : 'off';
    input.className = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
    wrap.append(title, input);
    if (hint) { const small = document.createElement('small'); small.textContent = hint; wrap.appendChild(small); }
    return wrap;
  }

  async function initIntegrationEditor() {
    const panel = document.querySelector('#tab-integracoes');
    if (!panel || panel.querySelector('[data-integration-editor]')) return;
    const card = document.createElement('section');
    card.className = 'bg-white rounded-xl shadow-sm mb-4 p-4 dnb-integration-editor';
    card.dataset.integrationEditor = '1';
    card.innerHTML = '<span class="dnb-eyebrow">Configuração funcional</span><h2 class="dnb-section-title">Editar integrações e tokens</h2><p class="text-sm text-slate-500 mt-1">Segredos ficam somente no servidor. Deixe um token em branco para manter o atual.</p><div data-loading class="text-sm text-slate-500 mt-4">Carregando configuração…</div>';
    panel.prepend(card);
    try {
      const response = await fetch('/api/v1/integrations.php', {credentials: 'same-origin'});
      const state = await response.json();
      if (!response.ok || !state.ok) throw new Error(state.erro || 'Não foi possível carregar as integrações.');
      const form = document.createElement('form');
      form.className = 'mt-4';
      const grid = document.createElement('div');
      grid.className = 'dnb-integration-editor__grid';
      grid.append(
        field('Endpoint da IA (HTTPS)', 'DNB_AI_VPS_ENDPOINT', 'url', state.fields.DNB_AI_VPS_ENDPOINT),
        field('Timeout da IA (segundos)', 'DNB_AI_TIMEOUT', 'number', state.fields.DNB_AI_TIMEOUT),
        field('Token da IA', 'DNB_AI_VPS_TOKEN', 'password', '', state.secrets.DNB_AI_VPS_TOKEN ? 'Definido — deixe em branco para manter.' : 'Ainda não definido.'),
        field('URL de envio WhatsApp (HTTPS)', 'DNB_WHATSAPP_SEND_URL', 'url', state.fields.DNB_WHATSAPP_SEND_URL),
        field('Timeout WhatsApp (segundos)', 'DNB_WHATSAPP_TIMEOUT', 'number', state.fields.DNB_WHATSAPP_TIMEOUT),
        field('Token da API WhatsApp', 'DNB_WHATSAPP_API_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_API_TOKEN ? 'Definido — deixe em branco para manter.' : 'Ainda não definido.'),
        field('Token do webhook', 'DNB_WHATSAPP_WEBHOOK_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_WEBHOOK_TOKEN ? 'Definido — deixe em branco para manter.' : 'Ainda não definido.'),
        field('Token do Cron Job', 'DNB_WHATSAPP_CRON_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_CRON_TOKEN ? 'Definido — deixe em branco para manter.' : 'Ainda não definido.')
      );
      form.appendChild(grid);
      const status = document.createElement('div');
      status.className = 'dnb-integration-status';
      const renderStatus = (s) => { status.innerHTML = '<span class="dnb-integration-pill ' + (s.ai ? 'is-ok' : '') + '">IA: ' + (s.ai ? 'configurada' : 'pendente') + '</span><span class="dnb-integration-pill ' + (s.whatsapp ? 'is-ok' : '') + '">WhatsApp: ' + (s.whatsapp ? 'configurado' : 'pendente') + '</span>'; };
      renderStatus(state.status);
      form.appendChild(status);
      const actions = document.createElement('div');
      actions.className = 'flex items-center flex-wrap gap-2';
      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'rounded-lg px-4 py-2 text-sm font-bold text-white';
      submit.style.background = '#003060';
      submit.textContent = 'Salvar integrações';
      const feedback = document.createElement('span');
      feedback.className = 'dnb-integration-feedback';
      actions.append(submit, feedback);
      form.appendChild(actions);
      card.querySelector('[data-loading]').replaceWith(form);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        feedback.classList.remove('is-error');
        feedback.textContent = 'Salvando…';
        const payload = Object.fromEntries(new FormData(form).entries());
        try {
          const saved = await fetch('/api/v1/integrations.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf}, body: JSON.stringify(payload)});
          const result = await saved.json();
          if (!saved.ok || !result.ok) throw new Error(result.erro || 'Falha ao salvar.');
          feedback.textContent = result.mensagem || 'Integrações atualizadas.';
          form.querySelectorAll('input[type="password"]').forEach((input) => { input.value = ''; });
          renderStatus(result.status);
        } catch (error) {
          feedback.classList.add('is-error');
          feedback.textContent = error.message || 'Não foi possível salvar.';
        } finally { submit.disabled = false; }
      });
    } catch (error) {
      card.querySelector('[data-loading]').textContent = error.message || 'Não foi possível carregar as integrações.';
    }
  }

  addProfileLink();
  addCrmInfoButtons();
  hideAdminOnlyNavigation();
  reorderOpportunityAboveFilters();
  hideAdminOnlyOpportunityTools();
  initKanbanAjaxFilters();
  initIntegrationEditor();
})();
