/** DNB CRM - interações de interface v2.0 */
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
