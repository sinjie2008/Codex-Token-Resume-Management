const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const sessionsElement = document.querySelector('#sessions');
const logsElement = document.querySelector('#logs');
const clearLogsButton = document.querySelector('#clear-logs');
const noticeElement = document.querySelector('#notice');
let latestSnapshot = null;

const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[character]);

const formatDate = (value) => value
  ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' }).format(new Date(value))
  : 'Unavailable';

const countdown = (value) => {
  if (!value) return 'No verified reset';
  const seconds = Math.max(0, Math.floor((new Date(value).getTime() - Date.now()) / 1000));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainder = seconds % 60;
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
};

const showNotice = (message, error = false) => {
  noticeElement.textContent = message;
  noticeElement.className = `notice ${error ? 'error' : 'success'}`;
  noticeElement.hidden = false;
  window.setTimeout(() => { noticeElement.hidden = true; }, 6000);
};

const api = async (payload = null) => {
  const options = payload === null
    ? { cache: 'no-store' }
    : {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(payload)
      };
  const response = await fetch('api.php', options);
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.message || 'Request failed.');
  return result;
};

const statusClass = (status) => {
  if (['WAITING_FOR_RESET', 'RETRY_WAIT', 'ACTIVE_ELSEWHERE'].includes(status)) return 'warning';
  if (['RESUMING', 'WATCHING', 'ACTIVE'].includes(status)) return 'active';
  if (['COMPLETED', 'RESUMED_EXTERNALLY'].includes(status)) return 'success';
  if (['ERROR', 'CANCELLED'].includes(status)) return 'muted';
  return 'muted';
};

const renderSession = (session) => {
  const title = session.display_name || session.codex_title || 'Unknown / Untitled';
  const canChange = session.status !== 'RESUMING';
  const resetValue = session.auto_resume ? (session.next_retry_at || session.reset_at) : null;
  return `<article class="session-card" data-id="${session.id}">
    <div class="session-topline">
      <div class="session-title">
        <h3>${escapeHtml(title)}</h3>
        ${session.display_name ? `<p>Codex title: ${escapeHtml(session.codex_title)}</p>` : ''}
        <code>${escapeHtml(session.session_id)}</code>
      </div>
      <span class="status ${statusClass(session.status)}">${escapeHtml(session.status.replaceAll('_', ' '))}</span>
    </div>
    <dl class="session-facts">
      <div><dt>Project</dt><dd title="${escapeHtml(session.project_path)}">${escapeHtml(session.project_path || 'Unknown')}</dd></div>
      <div><dt>Next eligible time</dt><dd>${escapeHtml(formatDate(resetValue))}<br><span class="countdown" data-reset="${escapeHtml(resetValue || '')}">${escapeHtml(countdown(resetValue))}</span></dd></div>
      <div><dt>Last activity</dt><dd>${escapeHtml(formatDate(session.last_codex_activity_at))}</dd></div>
      <div><dt>Resume attempts</dt><dd>${session.resume_count} successful · ${session.retry_count} retries</dd></div>
    </dl>
    ${session.last_error ? `<p class="inline-error">${escapeHtml(session.last_error)}</p>` : ''}
    <div class="session-actions">
      <button class="button primary" data-action="resume_now" ${canChange ? '' : 'disabled'}>Resume Now</button>
      ${session.auto_resume
        ? `<button class="button danger" data-action="cancel" ${canChange ? '' : 'disabled'}>Cancel Auto Resume</button>`
        : `<button class="button secondary" data-action="enable" ${canChange ? '' : 'disabled'}>Enable Auto Resume</button>`}
      <button class="button danger" data-action="remove_session" ${canChange ? '' : 'disabled'}>Delete</button>
    </div>
    <details>
      <summary>Edit name and prompt</summary>
      <form class="edit-form">
        <label>Custom display name<input name="display_name" maxlength="255" value="${escapeHtml(session.display_name || '')}"></label>
        <label>Resume prompt<textarea name="resume_prompt" rows="4" maxlength="8000">${escapeHtml(session.resume_prompt || '')}</textarea></label>
        <button class="button secondary" type="submit">Save session</button>
      </form>
    </details>
  </article>`;
};

const renderSnapshot = (snapshot) => {
  latestSnapshot = snapshot;
  const workerPill = document.querySelector('#worker-pill');
  workerPill.textContent = snapshot.worker.online
    ? `Worker online · ${snapshot.worker.mode || 'LIVE'}`
    : 'Worker offline';
  workerPill.className = `pill ${snapshot.worker.online ? 'success' : 'warning'}`;
  document.querySelector('#storage-status').textContent = snapshot.codex.storage_status;
  document.querySelector('#last-scan').textContent = snapshot.codex.last_scan_at
    ? `Scanned ${formatDate(snapshot.codex.last_scan_at)}`
    : 'No scan yet';

  const usage = snapshot.usage;
  document.querySelector('#primary-usage').textContent = usage?.primary?.used_percent != null
    ? `${usage.primary.used_percent}%`
    : 'Unavailable';
  document.querySelector('#secondary-usage').textContent = usage?.secondary?.used_percent != null
    ? `${usage.secondary.used_percent}%`
    : 'Unavailable';
  document.querySelector('#primary-reset').textContent = usage?.primary?.resets_at
    ? `Reset ${formatDate(new Date(usage.primary.resets_at * 1000).toISOString())}`
    : 'No verified reset';
  document.querySelector('#secondary-reset').textContent = usage?.secondary?.resets_at
    ? `Reset ${formatDate(new Date(usage.secondary.resets_at * 1000).toISOString())}`
    : 'No verified reset';
  document.querySelector('#limit-state').textContent = usage?.rate_limit_reached_type
    ? `LIMITED: ${usage.rate_limit_reached_type}`
    : usage ? 'READY' : 'Unknown';
  document.querySelector('#usage-updated').textContent = usage?.updated_at
    ? `Local event ${formatDate(usage.updated_at)}`
    : 'No local usage event';

  sessionsElement.innerHTML = snapshot.sessions.length
    ? snapshot.sessions.map(renderSession).join('')
    : '<p class="empty">No managed sessions. Add a Codex session UUID or let the worker detect a rate limit.</p>';
  logsElement.innerHTML = snapshot.logs.length
    ? snapshot.logs.map((log) => `<li><time>${escapeHtml(formatDate(log.created_at))}</time><strong>${escapeHtml(log.event_type.replaceAll('_', ' '))}</strong><span>${escapeHtml(log.message)}</span></li>`).join('')
    : '<li class="empty">No events yet.</li>';
  clearLogsButton.disabled = snapshot.logs.length === 0;

  const promptField = document.querySelector('#settings-form textarea');
  if (document.activeElement !== promptField) promptField.value = snapshot.settings.default_resume_prompt || '';
};

const refresh = async (silent = false) => {
  try {
    renderSnapshot(await api());
  } catch (error) {
    if (!silent) showNotice(error.message, true);
    document.querySelector('#worker-pill').textContent = 'Dashboard unavailable';
    document.querySelector('#worker-pill').className = 'pill warning';
  }
};

document.querySelector('#toggle-add').addEventListener('click', () => { document.querySelector('#add-form').hidden = false; });
document.querySelector('#close-add').addEventListener('click', () => { document.querySelector('#add-form').hidden = true; });

clearLogsButton.addEventListener('click', async () => {
  if (!window.confirm('Clear all activity events? This cannot be undone. Managed sessions, Codex conversations, usage data, and worker state will not be changed.')) return;
  clearLogsButton.disabled = true;
  try {
    const result = await api({ action: 'clear_logs' });
    showNotice(result.message);
    await refresh(true);
  } catch (error) {
    showNotice(error.message, true);
    clearLogsButton.disabled = false;
  }
});

document.querySelector('#add-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const data = new FormData(form);
  try {
    const result = await api({ action: 'add_session', ...Object.fromEntries(data) });
    form.reset();
    form.hidden = true;
    showNotice(result.message);
    await refresh(true);
  } catch (error) { showNotice(error.message, true); }
});

sessionsElement.addEventListener('click', async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  if (button.dataset.action === 'remove_session'
      && !window.confirm('Delete this session from the current Auto Resume queue? A future rate-limit event can add it again automatically. The original Codex conversation will not be deleted.')) {
    return;
  }
  const id = Number(button.closest('.session-card').dataset.id);
  button.disabled = true;
  try {
    const result = await api({ action: button.dataset.action, id });
    showNotice(result.message);
    await refresh(true);
  } catch (error) {
    showNotice(error.message, true);
    button.disabled = false;
  }
});

sessionsElement.addEventListener('submit', async (event) => {
  if (!event.target.matches('.edit-form')) return;
  event.preventDefault();
  const id = Number(event.target.closest('.session-card').dataset.id);
  const data = new FormData(event.target);
  try {
    const result = await api({ action: 'update_session', id, ...Object.fromEntries(data) });
    showNotice(result.message);
    await refresh(true);
  } catch (error) { showNotice(error.message, true); }
});

document.querySelector('#settings-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const data = new FormData(event.currentTarget);
  try {
    const result = await api({ action: 'save_settings', ...Object.fromEntries(data) });
    showNotice(result.message);
  } catch (error) { showNotice(error.message, true); }
});

window.setInterval(() => {
  document.querySelectorAll('[data-reset]').forEach((element) => {
    element.textContent = countdown(element.dataset.reset);
  });
}, 1000);
window.setInterval(() => {
  const editing = document.activeElement?.matches('input, textarea')
    || document.querySelector('#add-form:not([hidden])')
    || document.querySelector('.session-card details[open]');
  if (!editing) refresh(true);
}, 5000);
refresh();
