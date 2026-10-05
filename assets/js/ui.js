(() => {
  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  let activeModal = null;
  let previousFocus = null;
  let pendingConfirmForm = null;

  const focusables = (modal) => Array.from(modal.querySelectorAll(focusableSelector))
    .filter((el) => !el.hasAttribute('hidden') && el.offsetParent !== null);

  const openModal = (modal) => {
    if (!modal) return;

    previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    activeModal = modal;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('ui-modal-open');

    const preferred = modal.querySelector('[data-modal-primary]') || focusables(modal)[0];
    window.setTimeout(() => preferred?.focus(), 20);
  };

  const closeModal = (modal = activeModal, restoreFocus = true) => {
    if (!modal) return;

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('ui-modal-open');

    if (activeModal === modal) activeModal = null;
    if (restoreFocus) previousFocus?.focus?.();
    previousFocus = null;
  };

  const notificationModal = document.querySelector('[data-notification-modal]');
  if (notificationModal?.hasAttribute('data-modal-auto-open')) {
    openModal(notificationModal);
  }

  document.addEventListener('click', (event) => {
    const closeButton = event.target.closest('[data-modal-close]');
    if (closeButton) {
      closeModal(closeButton.closest('[data-ui-modal]'));
      return;
    }

    if (event.target.matches('[data-ui-modal][data-dismiss-backdrop="true"]')) {
      closeModal(event.target);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!activeModal) return;

    if (event.key === 'Escape' && activeModal.dataset.locked !== 'true') {
      event.preventDefault();
      if (activeModal.hasAttribute('data-confirm-modal')) {
        pendingConfirmForm = null;
      }
      closeModal(activeModal);
      return;
    }

    if (event.key !== 'Tab') return;

    const items = focusables(activeModal);
    if (!items.length) {
      event.preventDefault();
      return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const confirmModal = document.querySelector('[data-confirm-modal]');
  const confirmMessage = confirmModal?.querySelector('[data-confirm-message]');
  const confirmAction = confirmModal?.querySelector('[data-confirm-action]');

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === 'true') {
      return;
    }

    event.preventDefault();
    pendingConfirmForm = form;

    if (confirmMessage) {
      confirmMessage.textContent = form.dataset.confirm;
    }

    if (confirmAction) {
      confirmAction.textContent = form.dataset.confirmAction || 'Confirmar';
    }

    openModal(confirmModal);
  });

  confirmAction?.addEventListener('click', () => {
    if (!pendingConfirmForm) {
      closeModal(confirmModal);
      return;
    }

    const form = pendingConfirmForm;
    pendingConfirmForm = null;
    form.dataset.confirmed = 'true';
    closeModal(confirmModal, false);

    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });

  confirmModal?.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => {
    pendingConfirmForm = null;
    closeModal(confirmModal);
  });

  window.DNBUI = {
    openModal,
    closeModal
  };
})();
