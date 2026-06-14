/**
 * Superadmin: manage users (admins / shop managers / superadmins) and their
 * site assignments.
 */

let sites = [];
let users = [];

const sheet = document.getElementById('user-sheet');
const form = document.getElementById('user-form');

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  bindEvents();
  await loadAll();
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

async function loadAll() {
  try {
    const [sitesData, usersData] = await Promise.all([
      App.api('/api/sites.php'),
      App.api('/api/users.php'),
    ]);
    sites = sitesData.items;
    users = usersData.items;
    renderUsers();
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    document.getElementById('loading').classList.add('hidden');
  }
}

function renderUsers() {
  const list = document.getElementById('user-list');
  list.classList.remove('hidden');
  list.innerHTML = '';

  const roleLabels = {
    superadmin: ['Superadmin', 'bg-purple-100 text-purple-700'],
    admin: ['Admin', 'bg-blue-100 text-blue-700'],
    shop_manager: ['Shop manager', 'bg-gray-100 text-gray-600'],
  };

  users.forEach((user) => {
    const [label, cls] = roleLabels[user.role] || ['Unknown', 'bg-gray-100 text-gray-600'];
    const siteNames = user.role === 'superadmin'
      ? 'All sites'
      : (user.site_ids.map((id) => sites.find((s) => s.id === id)?.name).filter(Boolean).join(', ') || 'No sites assigned');

    const card = document.createElement('button');
    card.className = 'w-full text-left bg-white rounded-2xl border border-gray-100 p-4';
    card.innerHTML = `
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm font-semibold text-gray-900">${escapeHtml(user.name || user.phone)}</div>
          <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(user.phone)}</div>
        </div>
        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded ${cls}">${label}</span>
      </div>
      <div class="text-xs text-gray-400 mt-2 truncate">${escapeHtml(siteNames)}</div>
    `;
    card.addEventListener('click', () => openSheet(user));
    list.appendChild(card);
  });
}

function bindEvents() {
  document.getElementById('add-btn').addEventListener('click', () => openSheet(null));
  document.getElementById('user-sheet-close').addEventListener('click', closeSheet);
  document.getElementById('user-overlay').addEventListener('click', closeSheet);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveUser();
  });

  document.getElementById('user-delete-btn').addEventListener('click', async () => {
    const id = document.getElementById('user-id').value;
    if (!id) return;
    if (!confirm('Delete this user?')) return;

    try {
      await App.api(`/api/users.php?id=${id}`, { method: 'DELETE' });
      App.toast('User deleted', 'success');
      closeSheet();
      await loadAll();
    } catch (err) {
      App.toast(err.message, 'error');
    }
  });
}

function openSheet(user) {
  form.reset();
  document.getElementById('user-id').value = user ? user.id : '';
  document.getElementById('user-phone').value = user ? user.phone : '';
  document.getElementById('user-phone').disabled = !!user;
  document.getElementById('user-name').value = user ? user.name : '';
  document.getElementById('user-role').value = user ? user.role : 'admin';
  document.getElementById('user-sheet-title').textContent = user ? 'Edit user' : 'Add user';

  const isSelf = user && user.id === window.CURRENT_USER.id;
  document.getElementById('user-delete-btn').classList.toggle('hidden', !user || isSelf);
  document.getElementById('user-role').disabled = isSelf;

  const sitesList = document.getElementById('user-sites-list');
  sitesList.innerHTML = '';
  const selectedIds = new Set((user?.site_ids || []));

  if (sites.length === 0) {
    sitesList.innerHTML = '<p class="text-xs text-gray-400">No sites available yet.</p>';
  } else {
    sites.forEach((site) => {
      const label = document.createElement('label');
      label.className = 'flex items-center gap-2 text-sm text-gray-700';
      label.innerHTML = `<input type="checkbox" value="${site.id}" class="site-checkbox h-4 w-4 rounded border-gray-300" ${selectedIds.has(site.id) ? 'checked' : ''}> ${escapeHtml(site.name)}`;
      sitesList.appendChild(label);
    });
  }

  sheet.classList.remove('hidden');
}

function closeSheet() {
  sheet.classList.add('hidden');
}

async function saveUser() {
  const id = document.getElementById('user-id').value;
  const payload = {
    name: document.getElementById('user-name').value.trim(),
    role: document.getElementById('user-role').value,
    site_ids: Array.from(document.querySelectorAll('.site-checkbox:checked')).map((cb) => parseInt(cb.value, 10)),
  };

  if (!id) {
    payload.phone = document.getElementById('user-phone').value.trim();
  }

  const saveBtn = document.getElementById('user-save-btn');
  saveBtn.disabled = true;

  try {
    if (id) {
      await App.api(`/api/users.php?id=${id}`, { method: 'PUT', body: JSON.stringify(payload) });
    } else {
      await App.api('/api/users.php', { method: 'POST', body: JSON.stringify(payload) });
    }
    App.toast('User saved', 'success');
    closeSheet();
    await loadAll();
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
