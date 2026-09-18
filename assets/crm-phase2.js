(() => {
  const openModal = (name) => {
    const modal = document.querySelector('[data-modal="' + CSS.escape(name) + '"]');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('dnb-modal-open');
    const focusable = modal.querySelector('input:not([type="hidden"]),select,textarea,button');
    if (focusable) setTimeout(() => focusable.focus(), 0);
  };
  const closeModal = (modal) => {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.dnb-modal-backdrop:not(.hidden)')) document.body.classList.remove('dnb-modal-open');
  };
  document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-modal-open]');
    if (opener) {
      event.preventDefault();
      openModal(opener.getAttribute('data-modal-open'));
      return;
    }
    const closer = event.target.closest('[data-modal-close]');
    if (closer) {
      event.preventDefault();
      closeModal(closer.closest('[data-modal]'));
      return;
    }
    if (event.target.matches('[data-modal].dnb-modal-backdrop')) closeModal(event.target);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeModal(document.querySelector('.dnb-modal-backdrop:not(.hidden)'));
  });
  document.querySelectorAll('[data-modal][data-auto-open="1"]').forEach((modal) => openModal(modal.getAttribute('data-modal')));
})();