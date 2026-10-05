(() => {
  const form = document.querySelector('[data-auth-form]');
  const container = document.querySelector('[data-turnstile-container]');
  const feedback = document.querySelector('[data-turnstile-feedback]');
  const submit = document.querySelector('[data-auth-submit]');

  if (!form || !container || !submit) return;

  let widgetId = null;
  let verified = false;

  const setFeedback = (message, state = '') => {
    if (!feedback) return;
    feedback.textContent = message;
    feedback.dataset.state = state;
  };

  const resetWidget = () => {
    verified = false;
    submit.disabled = true;
    if (window.turnstile && widgetId !== null) {
      window.turnstile.reset(widgetId);
    }
  };

  const render = () => {
    if (!window.turnstile) {
      window.setTimeout(render, 120);
      return;
    }

    widgetId = window.turnstile.render(container, {
      sitekey: container.dataset.siteKey,
      callback: () => {
        verified = true;
        submit.disabled = false;
        setFeedback('Verificação concluída.', 'success');
      },
      'error-callback': () => {
        verified = false;
        submit.disabled = true;
        setFeedback('Não foi possível concluir a verificação. Tente novamente.', 'error');
      },
      'expired-callback': () => {
        setFeedback('A verificação expirou. Gerando uma nova verificação...', 'warning');
        resetWidget();
      }
    });
  };

  form.addEventListener('submit', (event) => {
    if (!verified) {
      event.preventDefault();
      setFeedback('Conclua a verificação de segurança antes de entrar.', 'error');
      resetWidget();
    }
  });

  render();
})();
