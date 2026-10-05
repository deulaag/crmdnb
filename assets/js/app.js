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
          : 'Nenhum PDF selecionado.';
      });
    }

    form.addEventListener('submit', (event) => {
      const files = input ? Array.from(input.files || []) : [];
      if (input && !files.length) {
        event.preventDefault();
        if (selected) selected.textContent = 'Selecione pelo menos um PDF antes de extrair.';
        return;
      }

      if (!button || button.disabled) return;
      button.disabled = true;
      button.dataset.originalLabel = button.textContent;
      button.textContent = button.dataset.loadingLabel || 'Processando...';
    });
  });

  const formatCnpj = (value) => {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 14);
    if (digits.length <= 2) return digits;
    if (digits.length <= 5) return digits.replace(/^(\d{2})(\d+)/, '$1.$2');
    if (digits.length <= 8) return digits.replace(/^(\d{2})(\d{3})(\d+)/, '$1.$2.$3');
    if (digits.length <= 12) return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d+)/, '$1.$2.$3/$4');
    return digits.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d+)/, '$1.$2.$3/$4-$5');
  };

  document.querySelectorAll('[data-cnpj-input]').forEach((input) => {
    input.addEventListener('input', () => {
      input.value = formatCnpj(input.value);
    });
  });

  document.querySelectorAll('[data-cnpj-lookup]').forEach((button) => {
    button.addEventListener('click', async () => {
      const form = button.closest('form') || document;
      const cnpjInput = form.querySelector('[data-cnpj-input]');
      const status = form.querySelector('[data-cnpja-status]');
      const csrf = form.querySelector('input[name="csrf_token"]');
      const digits = (cnpjInput?.value || '').replace(/\D/g, '');

      if (!cnpjInput || digits.length !== 14) {
        if (status) {
          status.className = 'cnpja-status error';
          status.textContent = 'Informe um CNPJ com 14 dígitos.';
        }
        cnpjInput?.focus();
        return;
      }

      const originalLabel = button.textContent;
      button.disabled = true;
      button.textContent = 'Consultando Receita...';

      if (status) {
        status.className = 'cnpja-status loading';
        status.textContent = 'Consultando a CNPJá...';
      }

      try {
        const body = new FormData();
        body.append('cnpj', digits);
        if (csrf) body.append('csrf_token', csrf.value);

        const response = await fetch(button.dataset.endpoint || 'cnpja_lookup.php', {
          method: 'POST',
          body,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });

        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) {
          throw new Error(payload?.message || 'Não foi possível consultar o CNPJ.');
        }

        const data = payload.data || {};
        Object.entries(data).forEach(([key, value]) => {
          const field = form.querySelector('[data-cnpja-field="' + key + '"]');
          if (!field) return;
          field.value = value ?? '';
          field.dispatchEvent(new Event('change', { bubbles: true }));
        });

        cnpjInput.value = formatCnpj(data.cnpj || digits);

        if (status) {
          const stale = data.stale ? ' · retorno de contingência em cache' : '';
          const source = data.strategy === 'ONLINE' ? 'Receita consultada agora' : 'consulta concluída';
          status.className = 'cnpja-status success';
          status.textContent = source + stale + '.';
        }
      } catch (error) {
        if (status) {
          status.className = 'cnpja-status error';
          status.textContent = error instanceof Error ? error.message : 'Erro ao consultar o CNPJ.';
        }
      } finally {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    });
  });

})();
