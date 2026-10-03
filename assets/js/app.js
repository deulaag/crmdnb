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
})();
