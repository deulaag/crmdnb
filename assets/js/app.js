(() => {
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
          : 'Nenhum arquivo selecionado.';
      });
    }
    form.addEventListener('submit', () => {
      if (!button || button.disabled) return;
      button.disabled = true;
      button.textContent = 'Lendo e importando PDFs...';
    });
  });
})();
