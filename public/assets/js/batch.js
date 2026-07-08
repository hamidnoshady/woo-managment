/**
 * Batch price / stock adjustment page.
 */

let selection = null; // {ids: [...]} or {selectAll: true, filters: {...}, total: N}
let pendingRequest = null;

init();

async function init() {
  await ensureSession();

  try {
    selection = JSON.parse(sessionStorage.getItem('batch_selection') || 'null');
  } catch (e) {
    selection = null;
  }

  const count = selection && selection.ids ? selection.ids.length : (selection && selection.selectAll ? selection.total : 0);

  if (!selection || count === 0) {
    document.getElementById('no-selection').classList.remove('hidden');
    document.getElementById('logout-btn').addEventListener('click', () => App.logout());
    return;
  }

  document.getElementById('batch-content').classList.remove('hidden');
  document.getElementById('selection-count').textContent = count;

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
  document.getElementById('backup-before-changes-btn')?.addEventListener('click', async (e) => {
    const status = document.getElementById('backup-before-changes-status');
    e.target.disabled = true;
    status.textContent = t('backup_in_progress');

    try {
      const res = await App.api('/api/site-backups.php?action=start', {
        method: 'POST',
        body: JSON.stringify({ scope: 'database' }),
      });

      const poll = async () => {
        try {
          const r = await App.api(`/api/site-backups.php?action=poll&id=${res.item.id}`);
          if (r.item.status === 'running') {
            setTimeout(poll, 3000);
            return;
          }
          status.textContent = r.item.status === 'completed' ? t('backup_done') : t('backup_failed');
        } catch (err) {
          status.textContent = t('backup_failed');
        } finally {
          e.target.disabled = false;
        }
      };
      poll();
    } catch (err) {
      status.textContent = t('backup_failed');
      e.target.disabled = false;
    }
  });

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
      extraLabel.textContent = t('rounding_step_label');
      extraInput.value = '1000';
    } else if (modeSelect.value === 'ending') {
      extraWrap.classList.remove('hidden');
      extraLabel.textContent = t('rounding_ending_label');
      extraInput.value = '0.99';
    } else {
      extraWrap.classList.add('hidden');
    }
  });

  const stockAction = document.getElementById('stock-action');
  const stockValueLabel = document.getElementById('stock-value-label');
  stockAction.addEventListener('change', () => {
    stockValueLabel.textContent = stockAction.value === 'set'
      ? t('stock_value_set_label')
      : t('stock_value_delta_label');
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
    App.toast(t('select_one_price_field'), 'error');
    return;
  }

  pendingRequest = Object.assign({
    action: 'price',
    percent,
    mode,
    step_or_ending: stepOrEnding,
    apply_to: applyTo,
  }, selection);

  runPreview(pendingRequest, (change) => {
    const parts = [];
    if (change.regular_price) {
      parts.push(`<bdi dir="ltr">${escapeHtml(App.formatToman(change.regular_price.old))} → <strong>${escapeHtml(App.formatToman(change.regular_price.new))}</strong></bdi>`);
    }
    if (change.sale_price) {
      parts.push(`<bdi dir="ltr">${escapeHtml(App.formatToman(change.sale_price.old))} → <strong>${escapeHtml(App.formatToman(change.sale_price.new))}</strong></bdi>`);
    }
    return parts.join(' · ');
  });
}

function previewStock() {
  const stockAction = document.getElementById('stock-action').value;
  const value = parseInt(document.getElementById('stock-value').value || '0', 10);

  pendingRequest = Object.assign({
    action: 'stock',
    stock_action: stockAction,
    value,
  }, selection);

  runPreview(pendingRequest, (change) => {
    return `${t('stock_quantity')}: ${change.stock_quantity.old} → ${change.stock_quantity.new}`;
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
      previewList.innerHTML = `<p class="text-sm text-gray-400">${escapeHtml(t('no_changes_to_apply'))}</p>`;
    } else {
      data.changes.forEach((change) => {
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0';
        row.innerHTML = `
          <span class="text-gray-700 truncate pr-2">${escapeHtml(change.name)}</span>
          <span class="text-gray-500 text-xs whitespace-nowrap">${describeChange(change)}</span>
        `;
        previewList.appendChild(row);
      });
    }

    if (data.truncated) {
      const note = document.createElement('p');
      note.className = 'text-xs text-gray-400 pt-1';
      note.textContent = t('and_n_more', data.hidden_count);
      previewList.appendChild(note);
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
  confirmBtn.textContent = t('applying');

  try {
    const startBody = Object.assign({}, pendingRequest);
    delete startBody.preview;

    const started = await App.api('/api/batch-jobs.php?action=start', {
      method: 'POST',
      body: JSON.stringify(startBody),
    });

    document.getElementById('preview-section').classList.add('hidden');
    pendingRequest = null;
    sessionStorage.removeItem('batch_selection');

    showProgress(started.id, started.total_items);
  } catch (err) {
    App.toast(err.message, 'error');
    confirmBtn.disabled = false;
    confirmBtn.textContent = t('apply_changes');
  }
}

function showProgress(jobId, totalItems) {
  const section = document.getElementById('progress-section');
  const bar = document.getElementById('progress-bar');
  const label = document.getElementById('progress-label');
  const reportList = document.getElementById('progress-report-list');

  section.classList.remove('hidden');
  section.scrollIntoView({ behavior: 'smooth', block: 'start' });
  label.textContent = t('batch_progress', 0, totalItems);
  bar.style.width = '0%';

  const poll = async () => {
    try {
      const data = await App.api(`/api/batch-jobs.php?action=poll&id=${jobId}`);
      const job = data.job;

      const percent = job.total_items > 0 ? Math.round((job.processed_items / job.total_items) * 100) : 100;
      bar.style.width = `${percent}%`;
      label.textContent = t('batch_progress', job.processed_items, job.total_items);

      data.items.forEach((item) => reportList.appendChild(renderReportRow(item)));

      if (job.status === 'completed') {
        const summaryText = `${t('batch_succeeded_count', job.succeeded_items)} · ${t('batch_failed_count', job.failed_items)}`;
        const summary = document.createElement('p');
        summary.className = 'text-sm font-medium text-gray-900 pt-2';
        summary.textContent = summaryText;
        reportList.parentElement.insertBefore(summary, reportList);

        if (job.log_id) {
          App.notifyOnNextPage(summaryText, { logId: job.log_id });
        }
        return;
      }

      setTimeout(poll, 1500);
    } catch (err) {
      App.toast(err.message, 'error');
      setTimeout(poll, 1500);
    }
  };

  poll();
}

function renderReportRow(item) {
  const row = document.createElement('div');
  const ok = item.status === 'success';
  row.className = `flex items-center justify-between text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0 ${ok ? 'text-gray-700' : 'text-red-600'}`;

  const detail = ok
    ? Object.entries(item.change || {}).map(([field, v]) => `${field}: ${v.old} → ${v.new}`).join(' · ')
    : t('batch_item_failed_reason', item.error || '');

  row.innerHTML = `
    <span class="truncate pr-2">${escapeHtml(item.product_name || ('#' + item.product_id))}</span>
    <span class="text-xs whitespace-nowrap">${escapeHtml(detail)}</span>
  `;
  return row;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
