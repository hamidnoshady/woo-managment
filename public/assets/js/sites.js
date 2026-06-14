/**
 * Site picker page.
 */

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());

  try {
    const data = await App.api('/api/sites.php');
    document.getElementById('loading').classList.add('hidden');

    if (data.items.length === 0) {
      document.getElementById('empty-state').classList.remove('hidden');
      return;
    }

    if (data.items.length === 1) {
      await selectSite(data.items[0].id);
      return;
    }

    const list = document.getElementById('site-list');
    list.classList.remove('hidden');

    data.items.forEach((site) => {
      const card = document.createElement('button');
      card.className = 'w-full text-left bg-white rounded-2xl border border-gray-100 p-4 flex items-center justify-between active:scale-[0.99] transition';
      const isCurrent = site.id === data.current_site_id;
      card.innerHTML = `
        <div>
          <div class="text-sm font-semibold text-gray-900">${escapeHtml(site.name)}</div>
          <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(site.store_url)}</div>
        </div>
        ${isCurrent ? '<span class="text-xs font-medium text-green-600">Current</span>' : '<span class="text-gray-300 text-lg">&rsaquo;</span>'}
      `;
      card.addEventListener('click', () => selectSite(site.id));
      list.appendChild(card);
    });
  } catch (e) {
    document.getElementById('loading').classList.add('hidden');
    App.toast(e.message, 'error');
  }
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

async function selectSite(siteId) {
  try {
    await App.api('/api/sites.php?action=select', {
      method: 'POST',
      body: JSON.stringify({ site_id: siteId }),
    });
    window.location.href = '/products.php';
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
