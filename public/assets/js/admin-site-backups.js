// public/assets/js/admin-site-backups.js

const listEl = document.getElementById('backup-list');
const loadingEl = document.getElementById('loading');
const pollingJobs = new Set();
let backupSource = 'legacy';

async function loadBackups() {
  try {
    const res = await App.api('/api/site-backups.php');
    backupSource = res.source || 'legacy';
    renderList(res.items);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    loadingEl.classList.add('hidden');
    listEl.classList.remove('hidden');
  }
}

function renderList(items) {
  listEl.innerHTML = items.map(renderRow).join('');
  items.filter((i) => i.status === 'running').forEach((i) => pollJob(i.id));
}

function renderRow(item) {
  const statusClass = item.status === 'completed' ? 'text-green-600' : item.status === 'failed' ? 'text-red-600' : 'text-gray-500';
  // JetBackup rows are always full-account: no scope selector, no `type`/`started_at`/`percent` fields.
  const isJetBackup = backupSource === 'jetbackup';
  const label = isJetBackup ? t('site_backups_heading') : escapeHtml(item.type);
  const timestamp = isJetBackup ? item.created_at : item.started_at;
  const restoreBtn = item.status === 'completed'
    ? `<button class="restore-btn text-xs text-gray-700 underline" data-id="${item.id}"${isJetBackup ? '' : ` data-type="${item.type}"`}>${t('restore')}</button>`
    : '';
  return `<div class="bg-white rounded-2xl border border-gray-100 p-3 flex items-center justify-between" data-job-id="${item.id}">
    <div>
      <div class="text-sm font-medium text-gray-900">${label} &middot; ${new Date(timestamp * 1000).toLocaleString()}</div>
      <div class="text-xs ${statusClass}">${escapeHtml(item.status)}${item.status === 'running' && !isJetBackup ? ' (' + item.percent + '%)' : ''}${item.error ? ' — ' + escapeHtml(item.error) : ''}</div>
    </div>
    ${restoreBtn}
  </div>`;
}

async function pollJob(jobId) {
  if (pollingJobs.has(jobId)) return;
  pollingJobs.add(jobId);

  const tick = async () => {
    try {
      const res = await App.api(`/api/site-backups.php?action=poll&id=${jobId}`);
      const row = document.querySelector(`[data-job-id="${jobId}"]`);
      if (row) row.outerHTML = renderRow(res.item);
      if (res.item.status === 'running') {
        setTimeout(tick, 3000);
        return;
      }
    } catch (e) {
      App.toast(e.message, 'error');
    }
    pollingJobs.delete(jobId);
  };
  tick();
}

async function startBackup(scope) {
  try {
    const res = await App.api('/api/site-backups.php?action=start', {
      method: 'POST',
      body: JSON.stringify({ scope }),
    });
    listEl.insertAdjacentHTML('afterbegin', renderRow(res.item));
    pollJob(res.item.id);
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

document.getElementById('run-site-db-backup-btn').addEventListener('click', () => startBackup('database'));
document.getElementById('run-site-full-backup-btn').addEventListener('click', () => startBackup('full'));

listEl.addEventListener('click', async (e) => {
  const btn = e.target.closest('.restore-btn');
  if (!btn) return;
  if (!confirm(t('restore_confirm'))) return;

  if (backupSource === 'jetbackup') {
    try {
      await App.api('/api/site-backups.php?action=restore', {
        method: 'POST',
        body: JSON.stringify({ source_id: Number(btn.dataset.id) }),
      });
      App.toast(t('restore_started'));
    } catch (err) {
      App.toast(err.message, 'error');
    }
    return;
  }

  try {
    const res = await App.api('/api/site-restores.php?action=start', {
      method: 'POST',
      body: JSON.stringify({ source_backup_id: Number(btn.dataset.id), scope: btn.dataset.type }),
    });
    App.toast(t('restore_started'));

    const pollRestore = async () => {
      try {
        const r = await App.api(`/api/site-restores.php?action=poll&id=${res.item.id}`);
        if (r.item.status === 'running') {
          setTimeout(pollRestore, 3000);
          return;
        }
        App.toast(r.item.status === 'completed' ? t('restore_completed') : t('restore_failed') + ': ' + r.item.error);
      } catch (err) {
        App.toast(err.message, 'error');
      }
    };
    pollRestore();
  } catch (err) {
    App.toast(err.message, 'error');
  }
});

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

loadBackups();
