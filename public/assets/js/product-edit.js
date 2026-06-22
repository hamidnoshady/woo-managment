/**
 * Add / edit product page.
 */

const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('product-form'),
  name: document.getElementById('name'),
  sku: document.getElementById('sku'),
  regularPrice: document.getElementById('regular_price'),
  salePrice: document.getElementById('sale_price'),
  stockQuantity: document.getElementById('stock_quantity'),
  stockStatus: document.getElementById('stock_status'),
  categoriesList: document.getElementById('categories-list'),
  customTaxonomies: document.getElementById('custom-taxonomies'),
  imagesList: document.getElementById('images-list'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  editWhiteBg: document.getElementById('edit-white-bg'),
  editEnhance: document.getElementById('edit-enhance'),
  editResize: document.getElementById('edit-resize'),
  editAi: document.getElementById('edit-ai'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  saveBtn: document.getElementById('save-btn'),
  deleteBtn: document.getElementById('delete-btn'),
};

init();

async function init() {
  try {
    await ensureSession();
    await loadCategories();
    await loadCustomTaxonomies();

    if (window.PRODUCT_ID > 0) {
      await loadProduct(window.PRODUCT_ID);
      if (window.CURRENT_USER.role === 'admin' || window.CURRENT_USER.role === 'superadmin') {
        els.deleteBtn.classList.remove('hidden');
      }
    } else {
      addImageRow('');
    }

    bindEvents();
  } catch (e) {
    console.error('Product edit page failed to initialize:', e);
    App.toast(e.message || 'Failed to load the form.', 'error');
  } finally {
    els.loading.classList.add('hidden');
    els.form.classList.remove('hidden');
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

async function loadCategories() {
  try {
    const data = await App.api('/api/categories.php');
    App.renderCategoryCheckboxTree(els.categoriesList, data.items);
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

async function loadCustomTaxonomies() {
  try {
    const data = await App.api('/api/taxonomies.php');
    renderCustomTaxonomies(data.items || [], data.reason);
  } catch (e) {
    renderCustomTaxonomies([], 'error');
  }
}

function renderCustomTaxonomies(taxonomies, reason) {
  els.customTaxonomies.innerHTML = '';

  if (!taxonomies.length) {
    if (reason) {
      const notice = document.createElement('p');
      notice.className = 'text-xs text-gray-400 bg-white rounded-2xl border border-gray-100 p-4';
      notice.textContent = reason === 'no_credentials' ? t('wp_credentials_missing') : t('no_custom_taxonomies');
      els.customTaxonomies.appendChild(notice);
      els.customTaxonomies.classList.remove('hidden');
    }
    return;
  }

  taxonomies.forEach((tax) => {
    const section = document.createElement('div');
    section.className = 'bg-white rounded-2xl border border-gray-100 p-4 space-y-3';
    section.dataset.restBase = tax.rest_base;

    const heading = document.createElement('h2');
    heading.className = 'text-sm font-semibold text-gray-900';
    heading.textContent = tax.name;
    section.appendChild(heading);

    const list = document.createElement('div');
    list.className = 'space-y-2 max-h-48 overflow-y-auto custom-taxonomy-terms';

    (tax.terms || []).forEach((term) => {
      const label = document.createElement('label');
      label.className = 'flex items-center gap-2 text-sm text-gray-700';
      label.innerHTML = `<input type="checkbox" value="${term.id}" class="custom-term-checkbox h-4 w-4 rounded border-gray-300"> ${escapeHtml(term.name)}`;
      list.appendChild(label);
    });

    section.appendChild(list);
    els.customTaxonomies.appendChild(section);
  });

  els.customTaxonomies.classList.remove('hidden');
}

async function loadProduct(id) {
  try {
    const data = await App.api(`/api/product.php?id=${id}`);
    const item = data.item;

    els.name.value = item.name || '';
    els.sku.value = item.sku || '';
    els.regularPrice.value = item.regular_price || '';
    els.salePrice.value = item.sale_price || '';
    els.stockQuantity.value = item.stock_quantity ?? '';
    els.stockStatus.value = item.stock_status || 'instock';
    els.shortDescription.value = stripHtml(item.short_description || '');
    els.description.value = stripHtml(item.description || '');
    els.status.value = item.status || 'publish';

    const selectedCategoryIds = new Set((item.categories || []).map((c) => String(c.id)));
    els.categoriesList.querySelectorAll('.category-checkbox').forEach((cb) => {
      cb.checked = selectedCategoryIds.has(cb.value);
    });
    App.expandCheckedCategoryAncestors(els.categoriesList);

    const taxonomies = item.taxonomies || {};
    els.customTaxonomies.querySelectorAll('[data-rest-base]').forEach((section) => {
      const selected = new Set((taxonomies[section.dataset.restBase] || []).map(String));
      section.querySelectorAll('.custom-term-checkbox').forEach((cb) => {
        cb.checked = selected.has(cb.value);
      });
    });

    const images = item.images || [];
    if (images.length === 0) {
      addImageRow('');
    } else {
      images.forEach((img) => addImageRow(img.src));
    }
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function addImageRow(value) {
  const row = document.createElement('div');
  row.className = 'flex gap-2';
  row.innerHTML = `
    <input type="url" value="${escapeAttr(value)}" placeholder="https://example.com/image.jpg"
           class="image-url flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
    <button type="button" class="remove-image rounded-xl border border-gray-300 px-3 text-gray-500">&times;</button>
  `;
  row.querySelector('.remove-image').addEventListener('click', () => row.remove());
  els.imagesList.appendChild(row);
}

function bindEvents() {
  els.addImage.addEventListener('click', () => addImageRow(''));

  els.imageUpload.addEventListener('change', async () => {
    const file = els.imageUpload.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('image', file);
    formData.append('white_bg', els.editWhiteBg.checked ? '1' : '0');
    formData.append('enhance', els.editEnhance.checked ? '1' : '0');
    formData.append('resize_frame', els.editResize.checked ? '1' : '0');
    formData.append('ai_edit', els.editAi.checked ? '1' : '0');

    els.imageUpload.disabled = true;
    els.uploadStatus.textContent = t('uploading');
    els.uploadStatus.classList.remove('hidden');
    try {
      const data = await App.api('/api/media.php', { method: 'POST', body: formData });
      addImageRow(data.item.src);
    } catch (err) {
      App.toast(err.message, 'error');
    } finally {
      els.imageUpload.disabled = false;
      els.imageUpload.value = '';
      els.uploadStatus.classList.add('hidden');
    }
  });

  els.aiGenerateShort.addEventListener('click', () => generateDescription('short', els.aiGenerateShort, els.shortDescription));
  els.aiGenerateLong.addEventListener('click', () => generateDescription('long', els.aiGenerateLong, els.description));

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const originalSaveLabel = els.saveBtn.textContent;
    els.saveBtn.disabled = true;
    els.saveBtn.textContent = t('saving');

    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: Array.from(els.imagesList.querySelectorAll('.image-url'))
        .map((input) => input.value.trim())
        .filter((v) => v !== ''),
      taxonomies: collectCustomTaxonomies(),
    };

    if (window.PRODUCT_ID > 0) {
      payload.id = window.PRODUCT_ID;
    }

    try {
      const data = await App.api('/api/product.php', {
        method: window.PRODUCT_ID > 0 ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });
      App.toast(t('product_saved'), 'success');
      if (data.item.log_id) {
        App.notifyOnNextPage(data.item.message, { logId: data.item.log_id });
      }
      window.location.href = `/product-edit.php?id=${data.item.id}`;
    } catch (err) {
      App.toast(err.message, 'error');
    } finally {
      els.saveBtn.disabled = false;
      els.saveBtn.textContent = originalSaveLabel;
    }
  });

  els.deleteBtn.addEventListener('click', async () => {
    if (!confirm(t('delete_product_confirm'))) return;

    els.deleteBtn.disabled = true;
    try {
      const data = await App.api(`/api/product.php?id=${window.PRODUCT_ID}`, { method: 'DELETE' });
      App.toast(t('product_deleted'), 'success');
      if (data.message) {
        App.notifyOnNextPage(data.message);
      }
      window.location.href = '/products.php';
    } catch (err) {
      App.toast(err.message, 'error');
      els.deleteBtn.disabled = false;
    }
  });
}

/**
 * Generates just one description field (short or long) via AI, so
 * regenerating one doesn't touch the other.
 */
async function generateDescription(field, btn, targetEl) {
  const originalLabel = btn.textContent;
  btn.disabled = true;
  btn.textContent = t('generating');

  try {
    const payload = {
      name: els.name.value.trim(),
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked'))
        .map((cb) => cb.parentElement.querySelector('.cat-name').textContent.trim()),
      attributes: {
        [t('sku')]: els.sku.value.trim(),
      },
    };
    const data = await App.api(`/api/ai.php?action=describe_${field}`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    targetEl.value = (field === 'short' ? data.short_description : data.description) || '';
    App.toast(t('ai_description_generated'), 'success');
  } catch (err) {
    App.toast(err.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalLabel;
  }
}

function collectCustomTaxonomies() {
  const result = {};
  els.customTaxonomies.querySelectorAll('[data-rest-base]').forEach((section) => {
    result[section.dataset.restBase] = Array.from(section.querySelectorAll('.custom-term-checkbox:checked')).map((cb) => cb.value);
  });
  return result;
}

function stripHtml(html) {
  const div = document.createElement('div');
  div.innerHTML = html;
  return div.textContent || '';
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function escapeAttr(str) {
  return (str ?? '').replace(/"/g, '&quot;');
}
