/**
 * Activity logs page: lists changes the current user (or, for superadmins,
 * other users / the system) has made, with relative timestamps and an undo
 * action for changes still within the undo window.
 */

const state = {
  page: 1,
  totalPages: 1,
  loading: false,
  scope: 'mine',
};

const listEl = document.getElementById('log-list');
const loadMoreWrap = document.getElementById('load-more-wrap');
const loadMoreBtn = document.getElementById('load-more');
const emptyState = document.getElementById('empty-state');

init();

async function init() {
  await ensureSession();
  bindEvents();
  await loadLogs(true);

  App.onUndo = () => loadLogs(true);
}

async function ensureSession() {
  if (App.csrfToken()) return;
  try {
    const me = await App.api('/api/auth.php?action=me');
    if (me.authenticated) {
      App.setCsrfToken(me.csrf_token);
    }
  } catch (e) {
    // ignore, App.api already redirects on 401
  }
}

function bindEvents() {
  loadMoreBtn.addEventListener('click', () => {
    state.page += 1;
    loadLogs(false);
  });

  document.querySelectorAll('.scope-btn').forEach((btn) => {
    if (btn.dataset.scope === state.scope) {
      btn.classList.add('bg-gray-900', 'text-white');
    }
    btn.addEventListener('click', () => {
      state.scope = btn.dataset.scope;
      document.querySelectorAll('.scope-btn').forEach((b) => {
        b.classList.toggle('bg-gray-900', b === btn);
        b.classList.toggle('text-white', b === btn);
      });
      loadLogs(true);
    });
  });

  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
}

async function loadLogs(reset) {
  if (state.loading) return;
  state.loading = true;

  if (reset) {
    state.page = 1;
    listEl.innerHTML = '';
  }

  try {
    const params = new URLSearchParams();
    params.set('page', state.page);
    params.set('per_page', 20);
    if (window.CURRENT_USER.role === 'superadmin') {
      params.set('scope', state.scope);
    }

    const data = await App.api(`/api/logs.php?${params.toString()}`);
    if (reset) {
      listEl.innerHTML = '';
    }

    state.totalPages = data.total_pages;

    if (data.items.length === 0 && state.page === 1) {
      emptyState.classList.remove('hidden');
    } else {
      emptyState.classList.add('hidden');
      data.items.forEach((log) => listEl.appendChild(renderLogItem(log)));
    }

    loadMoreWrap.classList.toggle('hidden', state.page >= state.totalPages);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    state.loading = false;
  }
}

function renderLogItem(log) {
  const item = document.createElement('div');
  item.className = 'bg-white rounded-2xl border border-gray-100 p-3';

  const who = window.CURRENT_USER.role === 'superadmin' && log.user_id !== window.CURRENT_USER.id
    ? `<div class="text-xs text-gray-400 mt-0.5">${escapeHtml(log.user_name || log.user_phone)}</div>`
    : '';

  const undone = log.undone
    ? `<span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-100 text-gray-500">${escapeHtml(t('undo_applied'))}</span>`
    : '';

  item.innerHTML = `
    <div class="flex items-start justify-between gap-2">
      <div class="flex-1 min-w-0">
        <div class="text-sm text-gray-900">${escapeHtml(log.message)}</div>
        ${who}
        <div class="text-xs text-gray-400 mt-0.5">${formatTime(log.created_at)}</div>
      </div>
      <div class="flex-shrink-0">${undone}</div>
    </div>
  `;

  if (log.can_undo) {
    const btn = document.createElement('button');
    btn.className = 'mt-2 text-xs font-medium text-blue-600';
    btn.textContent = t('undo');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await App.api('/api/logs.php?action=undo', {
          method: 'POST',
          body: JSON.stringify({ id: log.id }),
        });
        App.toast(t('undo_applied'), 'success');
        loadLogs(true);
      } catch (err) {
        App.toast(err.message || t('undo_failed'), 'error');
        btn.disabled = false;
      }
    });
    item.querySelector('.flex-1').appendChild(btn);
  }

  return item;
}

function formatTime(timestamp) {
  const date = new Date(timestamp * 1000);
  const locale = (document.documentElement.lang === 'fa') ? 'fa-IR' : 'en-US';
  return date.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
