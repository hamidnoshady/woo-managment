/**
 * Superadmin: manage WooCommerce sites (stores) and their REST API credentials.
 */

const sheet = document.getElementById('site-sheet');
const form = document.getElementById('site-form');

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
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
    list.innerHTML = '<p class="text-center text-sm text-gray-400 py-12">No sites yet. Tap "+ Add" to connect a WooCommerce store.</p>';
    return;
  }

  sites.forEach((site) => {
    const card = document.createElement('button');
    card.className = 'w-full text-left bg-white rounded-2xl border border-gray-100 p-4';
    card.innerHTML = `
      <div class="text-sm font-semibold text-gray-900">${escapeHtml(site.name)}</div>
      <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(site.store_url)}</div>
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
    if (!confirm('Delete this site? Users assigned to it will lose access.')) return;

    try {
      await App.api(`/api/sites.php?id=${id}`, { method: 'DELETE' });
      App.toast('Site deleted', 'success');
      closeSheet();
      await loadSites();
    } catch (err) {
      App.toast(err.message, 'error');
    }
  });
}

async function openSheet(id) {
  form.reset();
  document.getElementById('site-id').value = id || '';
  document.getElementById('site-ck-hint').classList.add('hidden');
  document.getElementById('site-cs-hint').classList.add('hidden');
  document.getElementById('site-ck').placeholder = 'ck_...';
  document.getElementById('site-cs').placeholder = 'cs_...';
  document.getElementById('site-delete-btn').classList.toggle('hidden', !id);
  document.getElementById('site-sheet-title').textContent = id ? 'Edit site' : 'Add site';
  document.getElementById('site-verify-ssl').checked = true;

  if (id) {
    try {
      const data = await App.api(`/api/sites.php?action=detail&id=${id}`);
      const item = data.item;
      document.getElementById('site-name').value = item.name;
      document.getElementById('site-url').value = item.store_url;
      document.getElementById('site-verify-ssl').checked = item.verify_ssl;

      document.getElementById('site-ck').placeholder = item.consumer_key;
      document.getElementById('site-cs').placeholder = item.consumer_secret;

      const ckHint = document.getElementById('site-ck-hint');
      ckHint.textContent = `Current: ${item.consumer_key}. Leave blank to keep.`;
      ckHint.classList.remove('hidden');

      const csHint = document.getElementById('site-cs-hint');
      csHint.textContent = `Current: ${item.consumer_secret}. Leave blank to keep.`;
      csHint.classList.remove('hidden');
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
  };

  const ck = document.getElementById('site-ck').value.trim();
  const cs = document.getElementById('site-cs').value.trim();
  if (ck !== '' || !id) payload.consumer_key = ck;
  if (cs !== '' || !id) payload.consumer_secret = cs;

  const saveBtn = document.getElementById('site-save-btn');
  saveBtn.disabled = true;

  try {
    if (id) {
      await App.api(`/api/sites.php?id=${id}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await App.api('/api/sites.php', { method: 'POST', body: JSON.stringify(payload) });
    }
    App.toast('Site saved', 'success');
    closeSheet();
    await loadSites();
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
