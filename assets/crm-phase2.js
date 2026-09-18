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
  const initRdPipelineSelects = () => {
    document.querySelectorAll('[data-rd-pipeline]').forEach((pipeline) => {
      const form = pipeline.closest('form');
      if (!form) return;
      const stage = form.querySelector('[data-rd-stage]');
      if (!stage) return;
      const sync = () => {
        const selectedPipeline = pipeline.value || '';
        let selectedVisible = false;
        Array.from(stage.options).forEach((option) => {
          const optionPipeline = option.dataset.pipeline || '';
          const visible = optionPipeline === '' || optionPipeline === selectedPipeline;
          option.hidden = !visible;
          option.disabled = !visible;
          if (visible && option.selected) selectedVisible = true;
        });
        if (!selectedVisible) stage.value = '';
      };
      pipeline.addEventListener('change', sync);
      sync();
    });
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
  initRdPipelineSelects();
})();