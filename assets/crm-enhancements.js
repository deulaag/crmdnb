(function () {
  'use strict';

  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = '/assets/crm-enhancements.css?v=3.4.0';
  document.head.appendChild(css);

  const normalize = (value) => String(value || '').trim().toLowerCase();
  const isAdmin = () => Boolean(document.querySelector('a[href*="usuarios.php"],a[href*="configuracoes.php"]'));

  function addProfileLink() {
    const user = document.querySelector('.dnb-user');
    if (!user || user.querySelector('.dnb-profile-link')) return;
    const identity = user.querySelector('.dnb-user__identity');
    if (!identity) return;
    const link = document.createElement('a');
    link.href = '/perfil.php';
    link.className = 'dnb-profile-link';
    link.textContent = 'Meu perfil';
    identity.appendChild(link);
    const avatar = user.querySelector('.dnb-user__avatar');
    if (avatar) {
      avatar.style.cursor = 'pointer';
      avatar.setAttribute('title', 'Abrir meu perfil');
      avatar.addEventListener('click', () => { window.location.href = '/perfil.php'; });
    }
  }

  const tutorials = {
    'painel.php': {
      title: 'Painel · visão geral',
      lead: 'Acompanhe o que é relevante para o seu papel no CRM.',
      body: '<h3>Como usar</h3><ol><li>Escolha o mês de referência no topo.</li><li>Vendedores visualizam o próprio Farol de metas, com dados vinculados ao seu usuário.</li><li>ADM e Supervisor visualizam os KPIs automáticos usados nos gatilhos de bonificação.</li><li>Use os números como leitura operacional; correções devem ser feitas no registro de origem.</li></ol>'
    },
    'orcamentos.php': {
      title: 'Orçamentos · oportunidades',
      lead: 'Crie, filtre e movimente oportunidades pelo funil comercial.',
      body: '<h3>Fluxo recomendado</h3><ol><li>Cadastre a nova oportunidade no bloco superior.</li><li>Use os filtros do Kanban; eles atualizam o quadro sem recarregar a página.</li><li>Arraste o cartão para outra etapa ou use o seletor do próprio cartão.</li><li>Importação do Cotador e sincronização RD Station são funções exclusivas do ADM.</li></ol>'
    },
    'pedidos.php': {
      title: 'Pedidos · vendas e faturamento',
      lead: 'Registre a evolução comercial depois que a oportunidade vira pedido.',
      body: '<h3>O que fazer</h3><ol><li>Confirme responsável, modalidade e valores.</li><li>Atualize faturamento e informações financeiras conforme o processo real.</li><li>Os dados faturados alimentam a apuração de comissão quando o cargo e a política forem elegíveis.</li><li>Evite duplicar pedidos: atualize o registro já criado sempre que possível.</li></ol>'
    },
    'visitas.php': {
      title: 'Visitas · atividade comercial',
      lead: 'Registre visitas e prospecções que fazem parte dos indicadores comerciais.',
      body: '<h3>Boas práticas</h3><ol><li>Registre a atividade no dia em que ocorreu.</li><li>Vincule corretamente o vendedor e o tipo da atividade.</li><li>Use observações objetivas para deixar histórico útil para a equipe.</li><li>Atividades elegíveis entram nos indicadores e podem impactar a bonificação.</li></ol>'
    }
  };

  function ensureTutorialDialog() {
    let dialog = document.querySelector('[data-crm-info-dialog]');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.className = 'dnb-info-dialog';
    dialog.dataset.crmInfoDialog = '1';
    dialog.innerHTML = '<div class="dnb-info-dialog__head"><div><strong data-info-title></strong><small data-info-lead></small></div><button type="button" class="dnb-info-dialog__close" aria-label="Fechar">×</button></div><div class="dnb-info-dialog__body" data-info-body></div>';
    document.body.appendChild(dialog);
    dialog.querySelector('.dnb-info-dialog__close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
    return dialog;
  }

  function openTutorial(file) {
    const data = tutorials[file];
    if (!data) return;
    const dialog = ensureTutorialDialog();
    dialog.querySelector('[data-info-title]').textContent = data.title;
    dialog.querySelector('[data-info-lead]').textContent = data.lead;
    dialog.querySelector('[data-info-body]').innerHTML = data.body;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function addCrmInfoButtons() {
    const groups = Array.from(document.querySelectorAll('.dnb-nav__group'));
    const crmGroup = groups.find((group) => normalize(group.querySelector('.dnb-nav__eyebrow')?.textContent) === 'crm');
    if (!crmGroup) return;
    crmGroup.querySelectorAll(':scope > .dnb-nav__link').forEach((link) => {
      const file = (new URL(link.href, window.location.href).pathname.split('/').pop() || '').toLowerCase();
      if (!tutorials[file]) return;
      const row = document.createElement('div');
      row.className = 'dnb-nav__row';
      link.parentNode.insertBefore(row, link);
      row.appendChild(link);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'dnb-nav__info';
      button.textContent = 'i';
      button.setAttribute('aria-label', 'Como funciona ' + link.textContent.trim());
      button.title = 'Como funciona';
      button.addEventListener('click', () => openTutorial(file));
      row.appendChild(button);
    });
  }

  function findCardByText(text) {
    const target = normalize(text);
    const nodes = Array.from(document.querySelectorAll('span,h2,h3,strong'));
    const node = nodes.find((item) => normalize(item.textContent).includes(target));
    return node ? node.closest('section,.bg-white.rounded-xl') : null;
  }

  function reorderOpportunityAboveFilters() {
    if (!location.pathname.endsWith('/orcamentos.php') && location.pathname !== '/orcamentos.php') return;
    const filterHeading = Array.from(document.querySelectorAll('h2')).find((h) => normalize(h.textContent) === 'filtros do kanban');
    const filterCard = filterHeading?.closest('section,.bg-white.rounded-xl');
    const opportunityCard = findCardByText('nova oportunidade / orçamento');
    if (filterCard && opportunityCard && filterCard.parentNode === opportunityCard.parentNode) {
      filterCard.parentNode.insertBefore(opportunityCard, filterCard);
    }
  }

  function hideAdminOnlyOpportunityTools() {
    if (isAdmin()) return;
    ['importar do cotador dnb', 'sincronizar rd station crm'].forEach((label) => {
      const card = findCardByText(label);
      if (card) card.classList.add('dnb-card-hidden-by-role');
    });
  }

  function serializeFilterForm(form) {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
      if (typeof value === 'string') params.set(key, value);
    });
    return params;
  }

  function bindReplacementKanban(board, refresh) {
    if (!board || board.dataset.crmEnhanced === '1') return;
    board.dataset.crmEnhanced = '1';
    let dragged = null;
    board.querySelectorAll('[data-kanban-card]').forEach((card) => {
      card.addEventListener('dragstart', (event) => {
        dragged = card;
        card.classList.add('is-dragging');
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', card.dataset.leadId || '');
        }
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        board.querySelectorAll('[data-kanban-stage]').forEach((column) => column.classList.remove('is-drag-over'));
        dragged = null;
      });
    });
    board.querySelectorAll('[data-kanban-stage]').forEach((column) => {
      column.addEventListener('dragover', (event) => {
        if (!dragged) return;
        event.preventDefault();
        column.classList.add('is-drag-over');
      });
      column.addEventListener('dragleave', (event) => {
        if (!column.contains(event.relatedTarget)) column.classList.remove('is-drag-over');
      });
      column.addEventListener('drop', (event) => {
        event.preventDefault();
        column.classList.remove('is-drag-over');
        if (!dragged) return;
        const form = dragged.querySelector('[data-kanban-stage-form]');
        const select = form?.querySelector('[data-kanban-stage-select]');
        const stage = column.dataset.kanbanStage || '';
        if (!form || !select || !Array.from(select.options).some((o) => o.value === stage) || select.value === stage) return;
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
        const response = await fetch(form.action || window.location.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
        if (!response.ok && response.status !== 302) throw new Error('Não foi possível atualizar a oportunidade.');
        await refresh(false);
      } catch (error) {
        window.alert(error.message || 'Não foi possível atualizar a oportunidade.');
      } finally {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
      }
    });
  }

  function initKanbanAjaxFilters() {
    if (!location.pathname.endsWith('/orcamentos.php') && location.pathname !== '/orcamentos.php') return;
    const heading = Array.from(document.querySelectorAll('h2')).find((h) => normalize(h.textContent) === 'filtros do kanban');
    const section = heading?.closest('section,.bg-white.rounded-xl');
    const form = section?.querySelector('form[method="get"]');
    if (!form || form.dataset.crmAjax === '1') return;
    form.dataset.crmAjax = '1';
    const status = document.createElement('span');
    status.className = 'dnb-kanban-ajax-status';
    status.setAttribute('aria-live', 'polite');
    form.appendChild(status);
    let controller = null;
    let debounce = null;

    const refresh = async (pushState = true) => {
      if (controller) controller.abort();
      controller = new AbortController();
      const params = serializeFilterForm(form);
      const url = new URL(window.location.href);
      url.search = params.toString();
      form.classList.add('is-loading');
      status.textContent = 'Atualizando Kanban…';
      try {
        const response = await fetch(url.toString(), {credentials: 'same-origin', signal: controller.signal, headers: {'X-Requested-With':'fetch'}});
        if (!response.ok) throw new Error('Falha ao atualizar os filtros.');
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const incoming = doc.querySelector('[data-opportunity-kanban]');
        const current = document.querySelector('[data-opportunity-kanban]');
        if (!incoming || !current) throw new Error('Kanban não encontrado na resposta.');
        const replacement = document.importNode(incoming, true);
        current.replaceWith(replacement);
        bindReplacementKanban(replacement, refresh);
        if (pushState) history.replaceState({}, '', url.pathname + '?' + params.toString());
        status.textContent = 'Filtros aplicados';
        window.setTimeout(() => { if (status.textContent === 'Filtros aplicados') status.textContent = ''; }, 1600);
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = error.message || 'Não foi possível atualizar.';
      } finally {
        form.classList.remove('is-loading');
      }
    };

    form.addEventListener('submit', (event) => { event.preventDefault(); refresh(true); });
    form.querySelectorAll('select,input:not([type="hidden"]):not([type="search"])').forEach((field) => field.addEventListener('change', () => refresh(true)));
    form.querySelectorAll('input[type="search"]').forEach((field) => field.addEventListener('input', () => {
      window.clearTimeout(debounce);
      debounce = window.setTimeout(() => refresh(true), 300);
    }));
    bindReplacementKanban(document.querySelector('[data-opportunity-kanban]'), refresh);
  }

  function integrationField(label, name, type, value, hint) {
    const wrap = document.createElement('label');
    const title = document.createElement('span');
    title.textContent = label;
    const input = document.createElement('input');
    input.name = name;
    input.type = type || 'text';
    input.autocomplete = type === 'password' ? 'new-password' : 'off';
    input.className = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
    if (value != null) input.value = value;
    wrap.append(title, input);
    if (hint) {
      const small = document.createElement('small');
      small.textContent = hint;
      wrap.appendChild(small);
    }
    return wrap;
  }

  async function initIntegrationEditor() {
    const panel = document.querySelector('#tab-integracoes');
    if (!panel || panel.querySelector('[data-integration-editor]')) return;
    const card = document.createElement('section');
    card.className = 'bg-white rounded-xl shadow-sm mb-4 p-4 dnb-integration-editor';
    card.dataset.integrationEditor = '1';
    card.innerHTML = '<div><span class="dnb-eyebrow">Configuração funcional</span><h2 class="dnb-section-title">Editar integrações e tokens</h2><p class="text-sm text-slate-500 mt-1">Os segredos são gravados somente no servidor e não entram no Git. Deixe um token em branco para manter o valor atual.</p></div><div data-integration-loading class="text-sm text-slate-500 mt-4">Carregando configuração…</div>';
    panel.insertBefore(card, panel.firstChild);
    try {
      const response = await fetch('/api/v1/integrations.php', {credentials:'same-origin'});
      const state = await response.json();
      if (!response.ok || !state.ok) throw new Error(state.erro || 'Não foi possível carregar as integrações.');
      const form = document.createElement('form');
      form.className = 'mt-4';
      const grid = document.createElement('div');
      grid.className = 'dnb-integration-editor__grid';
      grid.append(
        integrationField('Endpoint da IA (HTTPS)', 'DNB_AI_VPS_ENDPOINT', 'url', state.fields.DNB_AI_VPS_ENDPOINT || '', ''),
        integrationField('Timeout da IA (segundos)', 'DNB_AI_TIMEOUT', 'number', state.fields.DNB_AI_TIMEOUT || '45', ''),
        integrationField('Token da IA', 'DNB_AI_VPS_TOKEN', 'password', '', state.secrets.DNB_AI_VPS_TOKEN ? 'Token atual definido — deixe em branco para manter.' : 'Token ainda não definido.'),
        integrationField('URL de envio WhatsApp (HTTPS)', 'DNB_WHATSAPP_SEND_URL', 'url', state.fields.DNB_WHATSAPP_SEND_URL || '', ''),
        integrationField('Timeout WhatsApp (segundos)', 'DNB_WHATSAPP_TIMEOUT', 'number', state.fields.DNB_WHATSAPP_TIMEOUT || '30', ''),
        integrationField('Token da API WhatsApp', 'DNB_WHATSAPP_API_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_API_TOKEN ? 'Token atual definido — deixe em branco para manter.' : 'Token ainda não definido.'),
        integrationField('Token do webhook', 'DNB_WHATSAPP_WEBHOOK_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_WEBHOOK_TOKEN ? 'Token atual definido — deixe em branco para manter.' : 'Token ainda não definido.'),
        integrationField('Token do Cron Job', 'DNB_WHATSAPP_CRON_TOKEN', 'password', '', state.secrets.DNB_WHATSAPP_CRON_TOKEN ? 'Token atual definido — deixe em branco para manter.' : 'Token ainda não definido.')
      );
      form.appendChild(grid);
      const statuses = document.createElement('div');
      statuses.className = 'dnb-integration-status';
      statuses.innerHTML = '<span class="dnb-integration-pill ' + (state.status.ai ? 'is-ok' : '') + '">IA: ' + (state.status.ai ? 'configurada' : 'pendente') + '</span><span class="dnb-integration-pill ' + (state.status.whatsapp ? 'is-ok' : '') + '">WhatsApp: ' + (state.status.whatsapp ? 'configurado' : 'pendente') + '</span>';
      form.appendChild(statuses);
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
      card.querySelector('[data-integration-loading]').replaceWith(form);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        feedback.classList.remove('is-error');
        feedback.textContent = 'Salvando…';
        const payload = {};
        new FormData(form).forEach((value, key) => { payload[key] = value; });
        try {
          const saved = await fetch('/api/v1/integrations.php', {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify(payload)});
          const result = await saved.json();
          if (!saved.ok || !result.ok) throw new Error(result.erro || 'Falha ao salvar.');
          feedback.textContent = result.mensagem || 'Integrações atualizadas.';
          form.querySelectorAll('input[type="password"]').forEach((input) => { input.value = ''; });
          statuses.innerHTML = '<span class="dnb-integration-pill ' + (result.status.ai ? 'is-ok' : '') + '">IA: ' + (result.status.ai ? 'configurada' : 'pendente') + '</span><span class="dnb-integration-pill ' + (result.status.whatsapp ? 'is-ok' : '') + '">WhatsApp: ' + (result.status.whatsapp ? 'configurado' : 'pendente') + '</span>';
        } catch (error) {
          feedback.classList.add('is-error');
          feedback.textContent = error.message || 'Não foi possível salvar.';
        } finally { submit.disabled = false; }
      });
    } catch (error) {
      card.querySelector('[data-integration-loading]').textContent = error.message || 'Não foi possível carregar as integrações.';
    }
  }

  addProfileLink();
  addCrmInfoButtons();
  reorderOpportunityAboveFilters();
  hideAdminOnlyOpportunityTools();
  initKanbanAjaxFilters();
  initIntegrationEditor();
})();
