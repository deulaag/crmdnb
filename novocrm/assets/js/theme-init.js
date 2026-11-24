/* NOVOCRM — aplica tema, densidade e estado da sidebar antes da primeira pintura (evita "flash"). */
(function () {
  var root = document.documentElement;
  function read(key) {
    try { return window.localStorage.getItem(key); } catch (_) { return null; }
  }
  var theme = read('nc:theme');
  if (theme !== 'light' && theme !== 'dark') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  root.setAttribute('data-theme', theme);
  root.setAttribute('data-density', read('nc:density') === 'compact' ? 'compact' : 'comfortable');
  root.setAttribute('data-sidebar', read('nc:sidebar') === 'collapsed' ? 'collapsed' : 'expanded');
})();
