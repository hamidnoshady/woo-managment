/**
 * Superadmin: manage app-wide settings (Kavenegar, OTP, session, superadmins).
 */

const form = document.getElementById('settings-form');

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  await loadSettings();
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

      const input = document.createElement('input');
      input.id = `field-${field.key}`;
      input.name = field.key;
      input.className = 'w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none';

      if (field.type === 'password') {
        input.type = 'text';
        input.autocomplete = 'off';
        input.placeholder = field.value ? 'Leave blank to keep current value' : 'Not set';
        input.dataset.type = 'password';
      } else if (field.type === 'number') {
        input.type = 'number';
        input.value = field.value;
      } else {
        input.type = 'text';
        input.value = field.value;
      }

      wrap.appendChild(input);

      if (field.help) {
        const help = document.createElement('p');
        help.className = 'text-xs text-gray-400 mt-1';
        help.textContent = field.help;
        wrap.appendChild(help);
      }

      section.appendChild(wrap);
    });

    form.appendChild(section);
  });

  const saveBtn = document.createElement('button');
  saveBtn.type = 'submit';
  saveBtn.className = 'w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-sm';
  saveBtn.textContent = 'Save settings';
  form.appendChild(saveBtn);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await saveSettings(saveBtn);
  });
}

async function saveSettings(saveBtn) {
  const payload = {};
  form.querySelectorAll('input').forEach((input) => {
    if (input.dataset.type === 'password' && input.value.trim() === '') {
      return;
    }
    payload[input.name] = input.value;
  });

  saveBtn.disabled = true;
  try {
    await App.api('/api/settings.php', { method: 'PUT', body: JSON.stringify(payload) });
    App.toast('Settings saved', 'success');
    await loadSettings();
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}
