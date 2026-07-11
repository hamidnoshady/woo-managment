/**
 * Superadmin: manage WooCommerce sites (stores), their agent pairing token,
 * and backup settings.
 */

const sheet = document.getElementById('site-sheet');
const form = document.getElementById('site-form');

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  document.getElementById('sync-da-btn').addEventListener('click', syncDirectAdmin);
  bindEvents();
  await loadSites();
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

async function loadSites() {
  try {
    const data = await App.api('/api/sites.php');
    renderSites(data.items);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    document.getElementById('loading').classList.add('hidden');
  }
}

function renderSites(sites) {
  const list = document.getElementById('site-list');
  list.classList.remove('hidden');
  list.innerHTML = '';

  if (sites.length === 0) {
    list.innerHTML = `<p class="text-center text-sm text-gray-400 py-12">${escapeHtml(t('no_sites_available'))}</p>`;
    return;
  }

  sites.forEach((site) => {
    const daLabel = site.da_username
      ? `<span class="text-xs text-green-600">${escapeHtml(t('da_linked', site.da_username))}</span>`
      : '';
    const card = document.createElement('button');
    card.className = 'w-full text-left bg-white rounded-2xl border border-gray-100 p-4';
    card.innerHTML = `
      <div class="text-sm font-semibold text-gray-900">${escapeHtml(site.name)}</div>
      <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(site.store_url)}</div>
      ${daLabel}
    `;
    card.addEventListener('click', () => openSheet(site.id));
    list.appendChild(card);
  });
}

function bindEvents() {
  document.getElementById('add-btn').addEventListener('click', () => openSheet(null));
  document.getElementById('site-sheet-close').addEventListener('click', closeSheet);
  document.getElementById('site-overlay').addEventListener('click', closeSheet);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveSite();
  });

  document.getElementById('site-delete-btn').addEventListener('click', async () => {
    const id = document.getElementById('site-id').value;
    if (!id) return;
    if (!confirm(t('delete_site_confirm'))) return;

    try {
      await App.api(`/api/sites.php?id=${id}`, { method: 'DELETE' });
      App.toast(t('site_deleted'), 'success');
      closeSheet();
      await loadSites();
    } catch (err) {
      App.toast(err.message, 'error');
    }
  });

  document.getElementById('generate-agent-token-btn').addEventListener('click', async () => {
    const siteId = document.getElementById('site-id').value;
    if (!siteId) return;
    try {
      const res = await App.api('/api/sites.php?action=generate_agent_token', {
        method: 'POST',
        body: JSON.stringify({ site_id: Number(siteId) }),
      });
      const display = document.getElementById('agent-token-display');
      display.textContent = res.token;
      display.classList.remove('hidden');
      App.toast(t('pairing_token_generated_help'), 'success');
    } catch (err) {
      App.toast(err.message, 'error');
    }
  });
}

async function openSheet(id) {
  form.reset();
  document.getElementById('site-id').value = id || '';
  document.getElementById('site-delete-btn').classList.toggle('hidden', !id);
  document.getElementById('site-sheet-title').textContent = id ? t('edit_site') : t('add_site');
  document.getElementById('site-verify-ssl').checked = true;
  document.getElementById('generate-agent-token-btn').classList.toggle('hidden', !id);
  document.getElementById('agent-token-display').classList.add('hidden');
  document.getElementById('site-agent-status').textContent = '';
  document.getElementById('site-backup-enabled').checked = false;
  document.getElementById('site-backup-schedule').value = 'off';
  document.getElementById('site-backup-retention').value = '';

  if (id) {
    try {
      const data = await App.api(`/api/sites.php?action=detail&id=${id}`);
      const item = data.item;
      document.getElementById('site-name').value = item.name;
      document.getElementById('site-url').value = item.store_url;
      document.getElementById('site-verify-ssl').checked = item.verify_ssl;
      document.getElementById('site-backup-enabled').checked = item.backup_enabled;
      document.getElementById('site-backup-schedule').value = item.backup_schedule || 'off';
      document.getElementById('site-backup-retention').value = item.backup_retention_days || '';

      document.getElementById('site-agent-status').textContent = !item.agent_paired
        ? t('agent_never_paired')
        : item.agent_last_seen_at
          ? t('agent_last_seen', new Date(item.agent_last_seen_at * 1000).toLocaleString())
          : t('agent_connected');
    } catch (err) {
      App.toast(err.message, 'error');
      return;
    }
  }

  sheet.classList.remove('hidden');
}

function closeSheet() {
  sheet.classList.add('hidden');
}

async function saveSite() {
  const id = document.getElementById('site-id').value;
  const payload = {
    name: document.getElementById('site-name').value.trim(),
    store_url: document.getElementById('site-url').value.trim(),
    verify_ssl: document.getElementById('site-verify-ssl').checked,
    backup_enabled: document.getElementById('site-backup-enabled').checked,
    backup_schedule: document.getElementById('site-backup-schedule').value,
    backup_retention_days: Number(document.getElementById('site-backup-retention').value) || 30,
  };

  const saveBtn = document.getElementById('site-save-btn');
  saveBtn.disabled = true;

  try {
    if (id) {
      await App.api(`/api/sites.php?id=${id}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await App.api('/api/sites.php', { method: 'POST', body: JSON.stringify(payload) });
    }
    App.toast(t('site_saved'), 'success');
    closeSheet();
    await loadSites();
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function syncDirectAdmin() {
  const btn = document.getElementById('sync-da-btn');
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = t('syncing');

  try {
    const result = await App.api('/api/da-sync.php', { method: 'POST', body: JSON.stringify({}) });
    App.toast(t('sync_result', result.linked, result.created), 'success');
    await loadSites();
  } catch (err) {
    App.toast(err.message || t('sync_failed'), 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
