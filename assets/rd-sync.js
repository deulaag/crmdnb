(() => {
  const root = document.querySelector('[data-rd-sync-console]');
  if (!root) return;
  const jobId = Number(root.dataset.jobId || 0);
  if (!jobId) return;

  const csrf = root.dataset.csrf || '';
  const logBox = root.querySelector('[data-rd-sync-log]');
  const statusEl = root.querySelector('[data-rd-sync-status]');
  const stageEl = root.querySelector('[data-rd-sync-stage]');
  const pageEl = root.querySelector('[data-rd-sync-page]');
  const conflictsEl = root.querySelector('[data-rd-sync-conflicts]');
  const cancelButton = root.querySelector('[data-rd-sync-cancel]');
  const resumeButton = root.querySelector('[data-rd-sync-resume]');
  let lastLogId = 0;
  let stopped = !['queued', 'running', 'paused'].includes(root.dataset.jobStatus || '');
  let firstPayload = true;

  const levelClass = (level) => {
    if (level === 'error') return 'text-rose-400';
    if (level === 'warning') return 'text-amber-300';
    if (level === 'success') return 'text-emerald-400';
    return 'text-sky-300';
  };

  const appendLogs = (logs) => {
    if (!logBox || !Array.isArray(logs)) return;
    if (firstPayload) {
      logBox.innerHTML = '';
      firstPayload = false;
    }
    logs.forEach((log) => {
      lastLogId = Math.max(lastLogId, Number(log.id || 0));
      const row = document.createElement('div');
      row.className = 'py-1';
      const date = document.createElement('span');
      date.className = 'text-slate-500';
      date.textContent = '[' + String(log.created_at || '') + '] ';
      const level = document.createElement('span');
      level.className = levelClass(String(log.level || 'info'));
      level.textContent = String(log.level || 'info').toUpperCase() + ' ';
      const scope = document.createElement('span');
      scope.className = 'text-slate-500';
      scope.textContent = String(log.scope || '') + ' ';
      row.append(date, level, scope, document.createTextNode(String(log.message || '')));
      logBox.appendChild(row);
    });
    if (logs.length) logBox.scrollTop = logBox.scrollHeight;
  };

  const setCounter = (name, value) => {
    const el = document.querySelector('[data-rd-counter="' + name + '"]');
    if (el) el.textContent = String(value ?? 0);
  };

  const render = (payload) => {
    if (!payload || !payload.job) return;
    const job = payload.job;
    if (statusEl) statusEl.textContent = String(job.status || '').toUpperCase();
    if (stageEl) stageEl.textContent = String(job.stage || '—');
    if (pageEl) pageEl.textContent = String(job.page_number || 0);
    if (conflictsEl) conflictsEl.textContent = String(payload.pending_conflicts || 0);
    const c = job.counters || {};
    setCounter('companies_created', c.companies_created || 0);
    setCounter('contacts_created', c.contacts_created || 0);
    setCounter('negotiations_created', c.negotiations_created || 0);
    setCounter('conflicts', c.conflicts || 0);
    setCounter('updates', (c.companies_updated || 0) + (c.contacts_updated || 0) + (c.negotiations_updated || 0));
    appendLogs(payload.logs || []);
    stopped = ['completed', 'error', 'cancelled'].includes(String(job.status || ''));
    if (cancelButton) cancelButton.hidden = stopped;
    if (resumeButton) resumeButton.hidden = String(job.status || '') !== 'error';
  };

  const post = async (action) => {
    const response = await fetch('/api/v1/rdstation-sync.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
      body: JSON.stringify({action, job_id: jobId, after: lastLogId})
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'Falha ao processar sincronização.');
    return payload;
  };

  const step = async () => {
    if (stopped) return;
    try {
      const payload = await post('step');
      render(payload);
      const retryAt = Number(payload.job?.retry_after || 0) * 1000;
      const wait = retryAt > Date.now() ? Math.min(10000, retryAt - Date.now() + 250) : 650;
      if (!stopped) setTimeout(step, wait);
    } catch (error) {
      if (statusEl) statusEl.textContent = 'ERRO DE COMUNICAÇÃO';
      if (logBox) {
        const row = document.createElement('div');
        row.className = 'py-1 text-rose-400';
        row.textContent = String(error?.message || error);
        logBox.appendChild(row);
      }
    }
  };

  if (cancelButton) {
    cancelButton.addEventListener('click', async () => {
      if (!confirm('Cancelar esta sincronização? O que já foi importado será mantido.')) return;
      try {
        render(await post('cancel'));
      } catch (error) {
        alert(String(error?.message || error));
      }
    });
  }

  if (resumeButton) {
    resumeButton.addEventListener('click', async () => {
      try {
        const payload = await post('resume');
        render(payload);
        stopped = false;
        setTimeout(step, 250);
      } catch (error) {
        alert(String(error?.message || error));
      }
    });
  }

  if (!stopped) setTimeout(step, 300);
})();