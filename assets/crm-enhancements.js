(function () {
  'use strict';

  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = '/assets/crm-enhancements.css?v=3.5.0';
  document.head.appendChild(css);

  const normalize = (value) => String(value || '').trim().toLowerCase();
  const isAdmin = () => Boolean(document.querySelector('a[href*="usuarios.php"],a[href*="configuracoes.php"]'));
  const onQuotesPage = () => location.pathname.endsWith('/orcamentos.php') || location.pathname === '/orcamentos.php';
  const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm';
  let refreshKanban = null;

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

  const tutorials = {
    'painel.php': ['Painel · visão geral', 'Acompanhe metas pessoais e as tarefas comerciais do dia.', '<h3>Como usar</h3><ol><li>Vendedores visualizam o próprio Farol de metas.</li><li>O bloco Tarefas mostra os próximos compromissos ligados aos orçamentos.</li><li>Abra o orçamento para revisar o contexto ou conclua a tarefa quando terminar.</li><li>O sentimento escolhido no perfil acompanha sua identidade no sistema.</li></ol>'],
    'empresas.php': ['Empresas', 'Acompanhe cada cliente em uma visão única.', '<h3>Como usar</h3><ol><li>Cadastre a empresa pelo CNPJ e revise duplicidades sinalizadas.</li><li>Abra a empresa para consultar contatos, negociações, propostas, pedidos, visitas e histórico.</li><li>Use o DNB ID como referência interna do CRM.</li></ol>'],
    'contatos.php': ['Contatos', 'Organize as pessoas relacionadas às empresas e negociações.', '<h3>Como usar</h3><ol><li>Cadastre o contato pelo e-mail e vincule a uma empresa quando conhecida.</li><li>Contatos sem empresa permanecem disponíveis para triagem.</li><li>Dentro da negociação, defina o papel do contato: principal, decisor, comprador, técnico, financeiro ou outro.</li></ol>'],
    'negociacoes.php': ['Negociações', 'Centralize o negócio comercial antes da proposta.', '<h3>Como usar</h3><ol><li>Abra uma negociação mesmo sem orçamento.</li><li>Relacione empresa e vários contatos.</li><li>Use lista ou Kanban para acompanhar o pipeline.</li><li>Orçamentos, pedidos e visitas ficam associados ao mesmo negócio.</li></ol>'],
    'orcamentos.php': ['Orçamentos · propostas', 'Crie e acompanhe propostas comerciais vinculadas a negociações.', '<h3>Fluxo recomendado</h3><ol><li>Quando possível, crie o orçamento dentro de uma negociação existente.</li><li>O orçamento continua alimentando os indicadores de propostas emitidas.</li><li>Pedidos podem ser gerados a partir da proposta confirmada.</li><li>Importações legadas continuam disponíveis durante a transição.</li></ol>'],
    'pedidos.php': ['Pedidos · vendas e faturamento', 'Acompanhe os pedidos gerados manualmente ou pelo fechamento do orçamento.', '<h3>O que fazer</h3><ol><li>Confirme responsável, modalidade e valores.</li><li>Atualize o faturamento quando ocorrer.</li><li>Pedidos originados do Fechamento ficam vinculados ao orçamento de origem.</li><li>Pedidos faturados elegíveis alimentam a comissão.</li></ol>'],
    'visitas.php': ['Visitas · atividade comercial', 'Registre visitas e prospecções que fazem parte dos indicadores.', '<h3>Boas práticas</h3><ol><li>Registre a atividade no dia em que ocorreu.</li><li>Vincule corretamente vendedor e tipo.</li><li>Use observações objetivas.</li><li>Atividades elegíveis entram nos indicadores do CRM.</li></ol>']
  };

  function createDialog(className = 'dnb-action-dialog') {
    const dialog = document.createElement('dialog');
    dialog.className = className;
    dialog.innerHTML = '<div class="dnb-action-dialog__head"><div><strong data-dialog-title></strong><small data-dialog-subtitle></small></div><button type="button" class="dnb-action-dialog__close" aria-label="Fechar">×</button></div><div class="dnb-action-dialog__body" data-dialog-body></div>';
    document.body.appendChild(dialog);
    const close = () => { if (typeof dialog.close === 'function') dialog.close(); dialog.remove(); };
    dialog.querySelector('.dnb-action-dialog__close').addEventListener('click', close);
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    return { dialog, close };
  }

  function showMessage(title, subtitle, html) {
    const {dialog} = createDialog();
    dialog.querySelector('[data-dialog-title]').textContent = title;
    dialog.querySelector('[data-dialog-subtitle]').textContent = subtitle || '';
    dialog.querySelector('[data-dialog-body]').innerHTML = html;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }

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
      if (!tutorials[file] || link.closest('.dnb-nav__row')) return;
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
    enhanceTaskButtons(board);
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
      if (!(form instanceof HTMLFormElement) || normalize(form.method) !== 'post' || form.matches('[data-kanban-stage-form]')) return;
      event.preventDefault();
      const button = event.submitter;
      if (button) button.disabled = true;
      try {
        const response = await fetch(form.action || location.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
        if (!response.ok) throw new Error('Não foi possível atualizar a oportunidade.');
        await refresh(false);
      } catch (error) {
        alert(error.message || 'Não foi possível atualizar a oportunidade.');
      } finally { if (button) button.disabled = false; }
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
      } finally { form.classList.remove('is-loading'); }
    };
    refreshKanban = refresh;

    form.addEventListener('submit', (event) => { event.preventDefault(); refresh(true); });
    form.querySelectorAll('select,input:not([type="hidden"]):not([type="search"])').forEach((field) => field.addEventListener('change', () => refresh(true)));
    form.querySelectorAll('input[type="search"]').forEach((field) => field.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => refresh(true), 300);
    }));
    enhanceTaskButtons(document.querySelector('[data-opportunity-kanban]'));
  }

  async function submitStageForm(form, submitter) {
    const data = new FormData(form);
    data.set('acao', 'set_stage');
    if (submitter) submitter.disabled = true;
    try {
      const response = await fetch('/api/v1/opportunities.php', {method:'POST',body:data,credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.erro || 'Não foi possível mover a oportunidade.');
      if (result.stage === 'Fechamento') {
        const pedido = result.order || {};
        showMessage('Pedido de Venda gerado', 'Fechamento concluído automaticamente', '<div class="dnb-action-dialog__success">A oportunidade entrou em <b>Fechamento</b> e foi marcada como <b>Ganha</b>.</div><p>Pedido: <span class="dnb-order-number">' + String(pedido.numero || 'gerado') + '</span></p><div class="dnb-action-dialog__actions"><a class="rounded-lg px-4 py-2 text-sm font-bold text-white" style="background:#003060" href="/pedidos.php">Abrir Pedidos</a></div>');
      }
      if (refreshKanban) await refreshKanban(false); else location.reload();
    } catch (error) {
      showMessage('Não foi possível mover', 'A alteração não foi concluída', '<p>' + String(error.message || 'Erro inesperado.') + '</p>');
    } finally { if (submitter) submitter.disabled = false; }
  }

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-kanban-stage-form]')) return;
    event.preventDefault();
    submitStageForm(form, event.submitter);
  }, true);

  function injectTaskFieldsIntoCreate() {
    if (!onQuotesPage()) return;
    const form = Array.from(document.querySelectorAll('form[method="post"]')).find((item) => item.querySelector('input[name="acao"][value="add"]'));
    if (!form || form.querySelector('[data-task-fields]')) return;
    const fields = document.createElement('div');
    fields.className = 'dnb-task-fields';
    fields.dataset.taskFields = '1';
    fields.innerHTML = '<label><span>Tarefa de acompanhamento (opcional)</span><input class="' + inputClass + '" name="task_title" maxlength="180" placeholder="Ex.: retornar proposta, confirmar aprovação, cobrar PO"></label><label><span>Prazo da tarefa</span><input class="' + inputClass + '" type="date" name="task_due_date"></label><p class="dnb-task-fields__hint">Ao informar um prazo, a tarefa fica vinculada ao orçamento e aparece no Painel do CRM para o responsável.</p>';
    const actions = form.querySelector('.mt-3.flex');
    if (actions) actions.before(fields); else form.appendChild(fields);

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = event.submitter;
      if (button) button.disabled = true;
      const data = new FormData(form);
      data.set('acao', 'create');
      try {
        const response = await fetch('/api/v1/opportunities.php', {method:'POST',body:data,credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.erro || 'Não foi possível registrar a oportunidade.');
        showMessage('Oportunidade registrada', 'Cadastro concluído', '<div class="dnb-action-dialog__success">Orçamento <b>' + String(result.numero || '') + '</b> criado com sucesso' + (result.task_id ? ' e tarefa adicionada ao Painel.' : '.') + '</div>');
        form.reset();
        setTimeout(() => location.reload(), 700);
      } catch (error) {
        showMessage('Não foi possível registrar', 'Revise os dados', '<p>' + String(error.message || 'Erro inesperado.') + '</p>');
      } finally { if (button) button.disabled = false; }
    });
  }

  function openTaskDialog(leadId) {
    const {dialog, close} = createDialog();
    dialog.querySelector('[data-dialog-title]').textContent = 'Adicionar tarefa ao orçamento';
    dialog.querySelector('[data-dialog-subtitle]').textContent = 'A tarefa aparecerá no Painel do responsável.';
    const body = dialog.querySelector('[data-dialog-body]');
    body.innerHTML = '<form data-add-task-form><label><span>Tarefa</span><input class="' + inputClass + '" name="task_title" maxlength="180" required placeholder="Ex.: confirmar retorno do cliente"></label><label><span>Prazo</span><input class="' + inputClass + '" type="date" name="task_due_date" required></label><div class="dnb-action-dialog__actions"><button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold" data-cancel>Cancelar</button><button class="rounded-lg px-4 py-2 text-sm font-bold text-white" style="background:#003060">Salvar tarefa</button></div></form>';
    body.querySelector('[data-cancel]').addEventListener('click', close);
    body.querySelector('form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      data.set('acao','add_task'); data.set('id',String(leadId));
      const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
      data.set('csrf_token',csrf);
      const response = await fetch('/api/v1/opportunities.php',{method:'POST',body:data,credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok || !result.ok) { alert(result.erro || 'Não foi possível salvar a tarefa.'); return; }
      close();
      showMessage('Tarefa adicionada','Acompanhamento atualizado','<div class="dnb-action-dialog__success">A nova tarefa já está disponível no Painel do CRM.</div>');
    });
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open','');
  }

  function enhanceTaskButtons(root = document) {
    if (!root) return;
    root.querySelectorAll?.('[data-kanban-card]').forEach((card) => {
      if (card.querySelector('[data-add-task]')) return;
      const leadId = Number(card.dataset.leadId || 0);
      const actions = card.querySelector('.dnb-opportunity-card__actions');
      if (!leadId || !actions) return;
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'dnb-task-add rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold';
      button.dataset.addTask = String(leadId); button.textContent = '+ Tarefa';
      button.addEventListener('click', () => openTaskDialog(leadId));
      actions.appendChild(button);
    });
  }

  function initTaskCompletion() {
    document.querySelectorAll('[data-complete-crm-task]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        const data = new FormData();
        data.set('acao','complete_task'); data.set('task_id',button.dataset.completeCrmTask || '');
        data.set('csrf_token',document.querySelector('input[name="csrf_token"]')?.value || '');
        try {
          const response = await fetch('/api/v1/opportunities.php',{method:'POST',body:data,credentials:'same-origin'});
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.erro || 'Falha ao concluir tarefa.');
          button.closest('.dnb-task-card')?.remove();
        } catch (error) { alert(error.message); button.disabled = false; }
      });
    });
  }

  function field(label, name, type, value, hint) {
    const wrap = document.createElement('label');
    const title = document.createElement('span'); title.textContent = label;
    const input = document.createElement('input'); input.name = name; input.type = type; input.value = value || '';
    input.autocomplete = type === 'password' ? 'new-password' : 'off'; input.className = inputClass;
    wrap.append(title, input);
    if (hint) { const small = document.createElement('small'); small.textContent = hint; wrap.appendChild(small); }
    return wrap;
  }

  function integrationForm(fields, csrf, state, keys) {
    const form = document.createElement('form');
    form.className = 'dnb-integration-editor'; form.style.padding = '1rem';
    const grid = document.createElement('div'); grid.className = 'dnb-integration-editor__grid';
    keys.forEach((key) => {
      const meta = fields[key];
      if (!meta) return;
      grid.appendChild(field(meta.label,key,meta.type,meta.value,meta.hint));
    });
    form.appendChild(grid);
    const actions = document.createElement('div'); actions.className = 'flex items-center flex-wrap gap-2 mt-3';
    const submit = document.createElement('button'); submit.type='submit'; submit.className='rounded-lg px-4 py-2 text-sm font-bold text-white'; submit.style.background='#003060'; submit.textContent='Salvar conexão';
    const feedback = document.createElement('span'); feedback.className='dnb-integration-feedback';
    actions.append(submit,feedback); form.appendChild(actions);
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); submit.disabled=true; feedback.textContent='Salvando…'; feedback.classList.remove('is-error');
      const payload = Object.fromEntries(new FormData(form).entries());
      try {
        const response = await fetch('/api/v1/integrations.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
        const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.erro || 'Falha ao salvar.');
        feedback.textContent='Conexão atualizada.'; form.querySelectorAll('input[type="password"]').forEach((input)=>{input.value='';});
      } catch (error) { feedback.classList.add('is-error'); feedback.textContent=error.message; } finally { submit.disabled=false; }
    });
    return form;
  }

  async function initDirectIntegrationEditors() {
    if (!isAdmin() || !location.pathname.endsWith('/configuracoes.php')) return;
    const whatsappCard = findCardByText('conexão whatsapp');
    const aiCard = findCardByText('conexão ia & rag');
    if (!whatsappCard && !aiCard) return;
    try {
      const response = await fetch('/api/v1/integrations.php',{credentials:'same-origin'});
      const state = await response.json(); if (!response.ok || !state.ok) throw new Error(state.erro || 'Não foi possível carregar integrações.');
      const fields = {
        DNB_AI_VPS_ENDPOINT:{label:'Endpoint da IA (HTTPS)',type:'url',value:state.fields.DNB_AI_VPS_ENDPOINT},
        DNB_AI_TIMEOUT:{label:'Timeout da IA (segundos)',type:'number',value:state.fields.DNB_AI_TIMEOUT},
        DNB_AI_VPS_TOKEN:{label:'Bearer Token da IA',type:'password',value:'',hint:state.secrets.DNB_AI_VPS_TOKEN?'Definido — deixe em branco para manter.':'Ainda não definido.'},
        DNB_WHATSAPP_SEND_URL:{label:'URL de envio WhatsApp (HTTPS)',type:'url',value:state.fields.DNB_WHATSAPP_SEND_URL},
        DNB_WHATSAPP_TIMEOUT:{label:'Timeout WhatsApp (segundos)',type:'number',value:state.fields.DNB_WHATSAPP_TIMEOUT},
        DNB_WHATSAPP_API_TOKEN:{label:'Token da API WhatsApp',type:'password',value:'',hint:state.secrets.DNB_WHATSAPP_API_TOKEN?'Definido — deixe em branco para manter.':'Ainda não definido.'},
        DNB_WHATSAPP_WEBHOOK_TOKEN:{label:'Token do webhook',type:'password',value:'',hint:state.secrets.DNB_WHATSAPP_WEBHOOK_TOKEN?'Definido — deixe em branco para manter.':'Ainda não definido.'},
        DNB_WHATSAPP_CRON_TOKEN:{label:'Token do Cron Job',type:'password',value:'',hint:state.secrets.DNB_WHATSAPP_CRON_TOKEN?'Definido — deixe em branco para manter.':'Ainda não definido.'}
      };
      if (whatsappCard && !whatsappCard.dataset.directEdit) {
        whatsappCard.dataset.directEdit='1'; const body=whatsappCard.querySelector('.p-4'); if(body) body.replaceWith(integrationForm(fields,state.csrf,state,['DNB_WHATSAPP_SEND_URL','DNB_WHATSAPP_TIMEOUT','DNB_WHATSAPP_API_TOKEN','DNB_WHATSAPP_WEBHOOK_TOKEN','DNB_WHATSAPP_CRON_TOKEN']));
      }
      if (aiCard && !aiCard.dataset.directEdit) {
        aiCard.dataset.directEdit='1'; const body=aiCard.querySelector('.p-4'); if(body) body.replaceWith(integrationForm(fields,state.csrf,state,['DNB_AI_VPS_ENDPOINT','DNB_AI_TIMEOUT','DNB_AI_VPS_TOKEN']));
      }
    } catch (error) { console.error(error); }
  }

  async function initTaskSettingsEditor() {
    if (!isAdmin() || !location.pathname.endsWith('/configuracoes.php')) return;
    const panel = document.querySelector('#tab-integracoes');
    if (!panel || panel.querySelector('[data-task-settings]')) return;
    const card = document.createElement('section'); card.className='bg-white rounded-xl shadow-sm mb-4 p-4 dnb-task-settings'; card.dataset.taskSettings='1';
    card.innerHTML='<span class="dnb-eyebrow">Tarefas comerciais</span><h2 class="dnb-section-title">Template de lembrete de prazo</h2><p class="text-sm text-slate-500 mt-1">Notificação interna enviada ao responsável quando o prazo estiver próximo.</p><div data-task-settings-loading class="text-sm text-slate-500 mt-3">Carregando…</div>';
    panel.prepend(card);
    try {
      const response=await fetch('/api/v1/task-settings.php',{credentials:'same-origin'}); const state=await response.json(); if(!response.ok||!state.ok) throw new Error(state.erro||'Não foi possível carregar.');
      const s=state.settings; const form=document.createElement('form'); form.className='grid gap-3 mt-4';
      form.innerHTML='<label class="flex items-center gap-2"><input type="checkbox" name="reminder_enabled" value="1" '+(Number(s.reminder_enabled)?'checked':'')+'><span style="margin:0">Ativar lembretes automáticos</span></label><label><span>Avisar quantos dias antes</span><input class="'+inputClass+'" type="number" min="0" max="30" name="reminder_days_before" value="'+Number(s.reminder_days_before||1)+'"></label><label><span>Mensagem</span><textarea class="'+inputClass+'" name="reminder_template" maxlength="1000"></textarea></label><p class="text-xs text-slate-500">Variáveis: {tarefa}, {orcamento}, {cliente}, {dias}, {data}, {vendedor}.</p><div class="flex items-center gap-2"><button class="rounded-lg px-4 py-2 text-sm font-bold text-white" style="background:#003060">Salvar template</button><span data-task-feedback class="text-xs text-slate-500"></span></div>';
      form.querySelector('textarea').value=String(s.reminder_template||''); card.querySelector('[data-task-settings-loading]').replaceWith(form);
      form.addEventListener('submit',async(event)=>{event.preventDefault();const data=new FormData(form);data.set('csrf_token',state.csrf);const feedback=form.querySelector('[data-task-feedback]');feedback.textContent='Salvando…';const saved=await fetch('/api/v1/task-settings.php',{method:'POST',body:data,credentials:'same-origin'});const result=await saved.json();feedback.textContent=saved.ok&&result.ok?'Template salvo.':(result.erro||'Falha ao salvar.');});
    } catch(error){card.querySelector('[data-task-settings-loading]').textContent=error.message;}
  }

  addProfileLink();
  addCrmInfoButtons();
  reorderOpportunityAboveFilters();
  hideAdminOnlyOpportunityTools();
  initKanbanAjaxFilters();
  injectTaskFieldsIntoCreate();
  initTaskCompletion();
  initDirectIntegrationEditors();
  initTaskSettingsEditor();
})();
