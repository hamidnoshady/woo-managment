/**
 * Batch price / stock adjustment page.
 */

let selectedIds = [];
let pendingRequest = null;

init();

async function init() {
  await ensureSession();

  try {
    selectedIds = JSON.parse(sessionStorage.getItem('batch_ids') || '[]');
  } catch (e) {
    selectedIds = [];
  }

  if (!Array.isArray(selectedIds) || selectedIds.length === 0) {
    document.getElementById('no-selection').classList.remove('hidden');
    document.getElementById('logout-btn').addEventListener('click', () => App.logout());
    return;
  }

  document.getElementById('batch-content').classList.remove('hidden');
  document.getElementById('selection-count').textContent = selectedIds.length;

  if (window.CURRENT_USER.role === 'admin' || window.CURRENT_USER.role === 'superadmin') {
    document.getElementById('price-section').classList.remove('hidden');
  }

  bindEvents();
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

function bindEvents() {
  const signBtn = document.getElementById('price-sign');
  signBtn.addEventListener('click', () => {
    signBtn.textContent = signBtn.textContent === '+' ? '−' : '+';
    signBtn.dataset.sign = signBtn.textContent === '+' ? '1' : '-1';
  });
  signBtn.dataset.sign = '1';

  const modeSelect = document.getElementById('price-mode');
  const extraWrap = document.getElementById('price-extra');
  const extraLabel = document.getElementById('price-extra-label');
  const extraInput = document.getElementById('price-extra-input');

  modeSelect.addEventListener('change', () => {
    if (modeSelect.value === 'step') {
      extraWrap.classList.remove('hidden');
      extraLabel.textContent = 'Round to nearest (e.g. 1000)';
      extraInput.value = '1000';
    } else if (modeSelect.value === 'ending') {
      extraWrap.classList.remove('hidden');
      extraLabel.textContent = 'Ending decimal (e.g. 0.99)';
      extraInput.value = '0.99';
    } else {
      extraWrap.classList.add('hidden');
    }
  });

  const stockAction = document.getElementById('stock-action');
  const stockValueLabel = document.getElementById('stock-value-label');
  stockAction.addEventListener('change', () => {
    stockValueLabel.textContent = stockAction.value === 'set'
      ? 'New stock quantity'
      : 'Amount (use negative to decrease)';
  });

  document.getElementById('price-preview-btn').addEventListener('click', () => previewPrice());
  document.getElementById('stock-preview-btn').addEventListener('click', () => previewStock());

  document.getElementById('preview-cancel').addEventListener('click', () => {
    document.getElementById('preview-section').classList.add('hidden');
    pendingRequest = null;
  });

  document.getElementById('preview-confirm').addEventListener('click', () => confirmApply());

  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
}

function previewPrice() {
  const sign = document.getElementById('price-sign').dataset.sign === '-1' ? -1 : 1;
  const percent = sign * parseFloat(document.getElementById('price-percent').value || '0');
  const mode = document.getElementById('price-mode').value;
  const stepOrEnding = parseFloat(document.getElementById('price-extra-input').value || '0');

  const applyTo = [];
  if (document.getElementById('apply-regular').checked) applyTo.push('regular');
  if (document.getElementById('apply-sale').checked) applyTo.push('sale');

  if (applyTo.length === 0) {
    App.toast('Select at least one price field to update', 'error');
    return;
  }

  pendingRequest = {
    ids: selectedIds,
    action: 'price',
    percent,
    mode,
    step_or_ending: stepOrEnding,
    apply_to: applyTo,
  };

  runPreview(pendingRequest, (change) => {
    const parts = [];
    if (change.regular_price) {
      parts.push(`Regular: ${change.regular_price.old} → ${change.regular_price.new}`);
    }
    if (change.sale_price) {
      parts.push(`Sale: ${change.sale_price.old} → ${change.sale_price.new}`);
    }
    return parts.join(' · ');
  });
}

function previewStock() {
  const stockAction = document.getElementById('stock-action').value;
  const value = parseInt(document.getElementById('stock-value').value || '0', 10);

  pendingRequest = {
    ids: selectedIds,
    action: 'stock',
    stock_action: stockAction,
    value,
  };

  runPreview(pendingRequest, (change) => {
    return `Stock: ${change.stock_quantity.old} → ${change.stock_quantity.new}`;
  });
}

async function runPreview(request, describeChange) {
  const previewSection = document.getElementById('preview-section');
  const previewList = document.getElementById('preview-list');

  try {
    const data = await App.api('/api/batch.php', {
      method: 'POST',
      body: JSON.stringify(Object.assign({ preview: true }, request)),
    });

    previewList.innerHTML = '';

    if (data.changes.length === 0) {
      previewList.innerHTML = '<p class="text-sm text-gray-400">No changes to apply.</p>';
    } else {
      data.changes.forEach((change) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0';
        row.innerHTML = `
          <span class="text-gray-700 truncate pr-2">${escapeHtml(change.name)}</span>
          <span class="text-gray-500 text-xs whitespace-nowrap">${escapeHtml(describeChange(change))}</span>
        `;
        previewList.appendChild(row);
      });
    }

    previewSection.classList.remove('hidden');
    previewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (err) {
    App.toast(err.message, 'error');
  }
}

async function confirmApply() {
  if (!pendingRequest) return;

  const confirmBtn = document.getElementById('preview-confirm');
  confirmBtn.disabled = true;
  confirmBtn.textContent = 'Applying...';

  try {
    await App.api('/api/batch.php', {
      method: 'POST',
      body: JSON.stringify(Object.assign({ preview: false }, pendingRequest)),
    });

    App.toast('Batch update applied', 'success');
    sessionStorage.removeItem('batch_ids');
    document.getElementById('preview-section').classList.add('hidden');
    pendingRequest = null;
    setTimeout(() => {
      window.location.href = '/products.php';
    }, 800);
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Apply changes';
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
