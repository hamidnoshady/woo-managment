/**
 * Superadmin: manage app-wide settings (Kavenegar, OTP, session, superadmins).
 */

const form = document.getElementById('settings-form');

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  bindUpdateEvents();
  await loadSettings();
}

let latestRelease = null;

function bindUpdateEvents() {
  document.getElementById('app-update-check-btn').addEventListener('click', checkForUpdate);
  document.getElementById('app-update-apply-btn').addEventListener('click', applyUpdate);
}

async function checkForUpdate() {
  const checkBtn = document.getElementById('app-update-check-btn');
  const applyBtn = document.getElementById('app-update-apply-btn');
  const status = document.getElementById('app-version-status');

  checkBtn.disabled = true;
  status.textContent = t('app_update_checking');
  applyBtn.classList.add('hidden');

  try {
    const data = await App.api('/api/app-update.php');
    latestRelease = data.latest;
    if (latestRelease) {
      status.textContent = t('app_update_available', latestRelease.version);
      applyBtn.classList.remove('hidden');
    } else {
      status.textContent = t('app_update_up_to_date', data.current_version);
    }
  } catch (e) {
    status.textContent = t('app_update_check_failed');
  } finally {
    checkBtn.disabled = false;
  }
}

async function applyUpdate() {
  if (!latestRelease) return;
  const applyBtn = document.getElementById('app-update-apply-btn');
  const status = document.getElementById('app-version-status');

  applyBtn.disabled = true;
  status.textContent = t('app_update_updating');

  try {
    const data = await App.api('/api/app-update.php', { method: 'POST', body: JSON.stringify({}) });
    status.textContent = t('app_update_applied', data.version);
    setTimeout(() => window.location.reload(), 1500);
  } catch (e) {
    App.toast(e.message, 'error');
    applyBtn.disabled = false;
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

async function loadSettings() {
  try {
    const data = await App.api('/api/settings.php');
    renderForm(data.fields);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    document.getElementById('loading').classList.add('hidden');
  }
}

function renderForm(fields) {
  form.innerHTML = '';
  form.classList.remove('hidden');

  const groups = {};
  fields.forEach((field) => {
    groups[field.group] = groups[field.group] || [];
    groups[field.group].push(field);
  });

  Object.keys(groups).forEach((groupName) => {
    const section = document.createElement('section');
    section.className = 'bg-white rounded-2xl border border-gray-100 p-4 space-y-4';

    const heading = document.createElement('h2');
    heading.className = 'text-sm font-semibold text-gray-900';
    heading.textContent = groupName;
    section.appendChild(heading);

    groups[groupName].forEach((field) => {
      const wrap = document.createElement('div');

      const label = document.createElement('label');
      label.className = 'block text-sm font-medium text-gray-700 mb-1';
      label.textContent = field.label;
      label.setAttribute('for', `field-${field.key}`);
      wrap.appendChild(label);

      let input;
      if (field.type === 'select') {
        input = document.createElement('select');
        input.className = 'w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none';
        Object.entries(field.options || {}).forEach(([value, optLabel]) => {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = optLabel;
          option.selected = value === field.value;
          input.appendChild(option);
        });
      } else {
        input = document.createElement('input');
        input.className = 'w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none';

        if (field.type === 'password') {
          input.type = 'text';
          input.autocomplete = 'off';
          input.placeholder = field.value ? t('leave_blank_to_keep') : t('not_set');
          input.dataset.type = 'password';
        } else if (field.type === 'number') {
          input.type = 'number';
          input.value = field.value;
        } else {
          input.type = 'text';
          input.value = field.value;
        }
      }
      input.id = `field-${field.key}`;
      input.name = field.key;

      wrap.appendChild(input);

      if (field.help) {
        const help = document.createElement('p');
        help.className = 'text-xs text-gray-400 mt-1';
        help.textContent = field.help;
        wrap.appendChild(help);
      }

      section.appendChild(wrap);
    });

    if (groups[groupName].some((f) => f.key === 'da_api_url')) {
      section.appendChild(buildJetBackupTestConnection());
    }

    form.appendChild(section);
  });

  const saveBtn = document.createElement('button');
  saveBtn.type = 'submit';
  saveBtn.className = 'w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-sm';
  saveBtn.textContent = t('save_settings');
  form.appendChild(saveBtn);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveSettings(saveBtn);
  });
}

function buildJetBackupTestConnection() {
  const wrap = document.createElement('div');
  wrap.className = 'pt-1 border-t border-gray-100 mt-1';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'w-full rounded-xl border border-gray-300 text-gray-700 font-medium py-2.5 text-sm mt-3';
  btn.textContent = t('jetbackup_test_connection_btn');

  const result = document.createElement('div');
  result.className = 'text-xs mt-2 space-y-1';

  btn.addEventListener('click', async () => {
    // Saved settings, not whatever is currently typed but unsaved in the
    // form, are what a real backup/sync would use — testing against the
    // saved values avoids a false "connected" reading after an edit that
    // hasn't been saved yet.
    btn.disabled = true;
    btn.textContent = t('jetbackup_testing');
    result.innerHTML = '';

    try {
      const data = await App.api('/api/da-sync.php?action=test');
      result.innerHTML = [
        renderConnectionResult(t('jetbackup_test_directadmin'), data.directadmin),
        renderConnectionResult(t('jetbackup_test_jetbackup'), data.jetbackup),
      ].join('');
    } catch (e) {
      result.innerHTML = `<p class="text-red-600">${escapeHtmlSettings(e.message)}</p>`;
    } finally {
      btn.disabled = false;
      btn.textContent = t('jetbackup_test_connection_btn');
    }
  });

  wrap.appendChild(btn);
  wrap.appendChild(result);
  return wrap;
}

function renderConnectionResult(label, res) {
  const cls = res.ok ? 'text-green-600' : 'text-red-600';
  const detail = res.ok ? t('jetbackup_test_ok') : escapeHtmlSettings(res.error);
  return `<p class="${cls}">${escapeHtmlSettings(label)}: ${detail}</p>`;
}

function escapeHtmlSettings(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

async function saveSettings(saveBtn) {
  const payload = {};
  form.querySelectorAll('input, select').forEach((input) => {
    if (input.dataset.type === 'password' && input.value.trim() === '') {
      return;
    }
    payload[input.name] = input.value;
  });

  saveBtn.disabled = true;
  try {
    await App.api('/api/settings.php', { method: 'PUT', body: JSON.stringify(payload) });
    App.toast(t('settings_saved'), 'success');
    await loadSettings();
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}
