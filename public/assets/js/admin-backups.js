/**
 * Superadmin: trigger and review database/full backups to S3.
 */

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  document.getElementById('run-db-backup-btn').addEventListener('click', () => runBackup('database'));
  document.getElementById('run-full-backup-btn').addEventListener('click', () => runBackup('full'));
  await loadBackups();
}

async function ensureSession() {
  if (App.csrfToken()) return;
  try {
    const me = await App.api('/api/auth.php?action=me');
    if (me.authenticated) {
      App.setCsrfToken(me.csrf_token);
    }
  } catch (e) {
    // ignore
  }
}

async function loadBackups() {
  try {
    const data = await App.api('/api/backups.php');
    renderBackups(data.items);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    document.getElementById('loading').classList.add('hidden');
  }
}

function renderBackups(items) {
  const list = document.getElementById('backup-list');
  list.classList.remove('hidden');
  list.innerHTML = '';

  if (items.length === 0) {
    list.innerHTML = `<p class="text-center text-sm text-gray-400 py-8">${escapeHtml(t('no_backups_yet'))}</p>`;
    return;
  }

  items.forEach((item) => {
    const row = document.createElement('div');
    row.className = 'bg-white rounded-2xl border border-gray-100 p-3 flex items-center justify-between text-sm';
    const typeLabel = (item.type === 'full' || item.type === 'jetbackup') ? t('backup_type_full') : t('backup_type_database');
    const statusLabel = item.status === 'success' ? t('backup_status_success') : t('backup_status_failed');
    const sourceLabel = item.external_ref ? t('backup_source_jetbackup') : t('backup_source_legacy');
    const statusClass = item.status === 'success' ? 'text-green-600' : 'text-red-600';
    const sizeKb = (item.size_bytes / 1024).toFixed(1);
    const date = new Date(item.created_at * 1000).toLocaleString();

    row.innerHTML = `
      <div>
        <div class="font-medium text-gray-900">${escapeHtml(typeLabel)} &middot; <span class="${statusClass}">${escapeHtml(statusLabel)}</span> &middot; ${escapeHtml(sourceLabel)}</div>
        <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(date)} &middot; ${sizeKb} KB</div>
        ${item.error ? `<div class="text-xs text-red-500 mt-0.5">${escapeHtml(item.error)}</div>` : ''}
      </div>
    `;
    list.appendChild(row);
  });
}

async function runBackup(type) {
  const btn = document.getElementById(type === 'full' ? 'run-full-backup-btn' : 'run-db-backup-btn');
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = t('running_backup');

  try {
    await App.api('/api/backups.php', { method: 'POST', body: JSON.stringify({ type }) });
    App.toast(t('backup_succeeded'), 'success');
    await loadBackups();
  } catch (err) {
    App.toast(err.message || t('backup_failed'), 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str == null ? '' : String(str);
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
