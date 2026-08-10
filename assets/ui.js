/** DNB CRM - interações de interface v3.0 */
(function () {
  const app = document.querySelector('[data-dnb-app]');
  const openButton = document.querySelector('[data-sidebar-open]');
  const closeTargets = document.querySelectorAll('[data-sidebar-close]');

  function setSidebar(open) {
    if (!app) return;
    app.classList.toggle('is-sidebar-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
    if (openButton) openButton.setAttribute('aria-expanded', String(open));
  }

  if (openButton) openButton.addEventListener('click', () => setSidebar(true));
  closeTargets.forEach((target) => target.addEventListener('click', () => setSidebar(false)));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setSidebar(false);
  });

  document.querySelectorAll('.overflow-x-auto').forEach((container) => {
    container.tabIndex = 0;
    container.setAttribute('role', 'region');
    if (!container.hasAttribute('aria-label')) container.setAttribute('aria-label', 'Tabela com rolagem horizontal');
  });

  // O sistema legado possui vários rótulos visuais sem associação explícita.
  // Completa a relação label/campo sem alterar nomes ou o envio dos formulários.
  let generatedFieldId = 0;
  document.querySelectorAll('label:not([for])').forEach((label) => {
    const parent = label.parentElement;
    if (!parent) return;
    const field = parent.querySelector('input:not([type="hidden"]), select, textarea');
    if (!field) return;
    if (!field.id) field.id = 'dnb-field-' + (++generatedFieldId);
    label.htmlFor = field.id;
  });

  const aiForm = document.querySelector('[data-ai-form]');
  if (aiForm) {
    aiForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = event.submitter;
      const status = aiForm.querySelector('[data-ai-status]');
      const resultBox = document.querySelector('[data-ai-result]');
      const data = new FormData(aiForm);
      if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
      if (status) status.textContent = 'Processando com segurança na VPS…';
      if (resultBox) { resultBox.hidden = true; resultBox.classList.remove('is-error'); }
      try {
        const response = await fetch(aiForm.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': aiForm.dataset.csrf},
          body: JSON.stringify({lead_id: Number(data.get('lead_id')), message: data.get('message')})
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.erro || 'Não foi possível processar a solicitação.');
        if (resultBox) {
          const title = document.createElement('strong'); title.textContent = 'Resposta · ' + payload.result.route;
          const answer = document.createElement('p'); answer.textContent = payload.result.response || 'A IA não retornou texto.';
          resultBox.replaceChildren(title, answer);
          if (payload.result.score !== null) {
            const score = document.createElement('p'); score.textContent = 'Score ' + payload.result.score + '/100 · ' + (payload.result.score_justification || 'Sem justificativa.'); resultBox.appendChild(score);
          }
          if (payload.case_id) {
            const link = document.createElement('a'); link.href = '/casos.php?caso=' + payload.case_id; link.textContent = 'Abrir caso de atendimento #' + payload.case_id; resultBox.appendChild(link);
          }
          resultBox.hidden = false;
        }
        if (status) status.textContent = payload.case_id ? 'Transbordado para atendimento humano.' : 'Processamento concluído.';
      } catch (error) {
        if (status) status.textContent = error.message;
        if (resultBox) { resultBox.hidden = false; resultBox.textContent = error.message; resultBox.classList.add('is-error'); }
      } finally {
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
      }
    });
  }

  const whatsapp = document.querySelector('[data-whatsapp-chat]');
  if (whatsapp && Number(whatsapp.dataset.conversationId) > 0) {
    const conversationId = Number(whatsapp.dataset.conversationId);
    const csrf = whatsapp.dataset.csrf || '';
    const messagesBox = whatsapp.querySelector('[data-chat-messages]');
    const sendForm = whatsapp.querySelector('[data-chat-send-form]');
    const statusBox = whatsapp.querySelector('[data-chat-status]');
    let lastMessageId = Number(whatsapp.dataset.lastMessageId || 0);
    let polling = false;

    const scrollToEnd = () => {
      if (messagesBox) messagesBox.scrollTop = messagesBox.scrollHeight;
    };

    const createMessage = (message) => {
      const article = document.createElement('article');
      article.className = 'dnb-chat-message dnb-chat-message--' + (message.direction === 'outgoing' ? 'outgoing' : 'incoming');
      article.dataset.messageId = String(message.id);
      const bubble = document.createElement('div');
      if (message.message_body) {
        const text = document.createElement('p'); text.textContent = message.message_body; bubble.appendChild(text);
      }
      if (message.media_url) {
        const media = document.createElement('a'); media.href = message.media_url; media.target = '_blank'; media.rel = 'noopener noreferrer'; media.textContent = 'Abrir mídia recebida'; bubble.appendChild(media);
      }
      const meta = document.createElement('small');
      meta.textContent = (message.sender_user_name || (message.sender_type === 'ai' ? 'IA DNB' : (message.direction === 'incoming' ? 'Cliente' : 'Sistema'))) + ' · ' + String(message.created_at || '').slice(0, 16) + ' · ' + message.status;
      bubble.appendChild(meta); article.appendChild(bubble);
      return article;
    };

    const loadMessages = async () => {
      if (polling || document.hidden) return;
      polling = true;
      try {
        const response = await fetch('/api/v1/whatsapp/messages.php?conversation_id=' + conversationId + '&after_id=' + lastMessageId, {credentials: 'same-origin'});
        const payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'Falha ao atualizar o chat.');
        if (messagesBox && payload.messages.length) {
          const empty = messagesBox.querySelector('[data-chat-empty]'); if (empty) empty.remove();
          payload.messages.forEach((message) => { messagesBox.appendChild(createMessage(message)); lastMessageId = Math.max(lastMessageId, Number(message.id)); });
          scrollToEnd();
        }
      } catch (error) {
        if (statusBox) statusBox.textContent = error.message;
      } finally { polling = false; }
    };

    if (sendForm) {
      sendForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const field = sendForm.querySelector('[name="message"]');
        const button = event.submitter;
        const message = field ? field.value.trim() : '';
        if (!message) return;
        if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
        if (statusBox) statusBox.textContent = 'Enviando e ativando o Human Lock…';
        try {
          const response = await fetch('/api/v1/whatsapp/send.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({conversation_id: conversationId, message})
          });
          const payload = await response.json();
          if (!response.ok || !payload.ok) throw new Error(payload.error || 'Falha ao enviar a mensagem.');
          if (field) field.value = '';
          if (statusBox) statusBox.textContent = 'Mensagem enviada. Atendimento humano ativo.';
          await loadMessages();
        } catch (error) {
          if (statusBox) statusBox.textContent = error.message;
        } finally {
          if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
        }
      });
    }

    whatsapp.querySelectorAll('[data-chat-assign]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true; button.setAttribute('aria-busy', 'true');
        if (statusBox) statusBox.textContent = button.dataset.chatAssign === 'release' ? 'Liberando atendimento…' : 'Assumindo atendimento…';
        try {
          const response = await fetch('/api/v1/whatsapp/assign.php', {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            body: JSON.stringify({conversation_id: conversationId, action: button.dataset.chatAssign})
          });
          const payload = await response.json();
          if (!response.ok || !payload.ok) throw new Error(payload.error || 'Falha ao atualizar o atendimento.');
          window.location.reload();
        } catch (error) {
          if (statusBox) statusBox.textContent = error.message;
          button.disabled = false; button.removeAttribute('aria-busy');
        }
      });
    });

    scrollToEnd();
    window.setInterval(loadMessages, 4000);
  }

  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const button = event.submitter;
      if (!button || event.defaultPrevented) return;
      window.setTimeout(() => {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
      }, 0);
    });
  });
})();

function dnbCopiar(id) {
  const element = document.getElementById(id);
  const button = document.getElementById('btn-' + id);
  if (!element) return;

  const done = () => {
    if (!button) return;
    const original = button.innerText;
    button.innerText = 'Copiado';
    window.setTimeout(() => { button.innerText = original; }, 1500);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(element.innerText).then(done);
    return;
  }

  const area = document.createElement('textarea');
  area.value = element.innerText;
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  document.execCommand('copy');
  area.remove();
  done();
}
