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
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
  status: document.getElementById('status'),
  saveBtn: document.getElementById('save-btn'),
  deleteBtn: document.getElementById('delete-btn'),
  viewLink: document.getElementById('view-product-link'),
  productTypeBtns: Array.from(document.querySelectorAll('.product-type-btn')),
  attributesSection: document.getElementById('attributes-section'),
  attributesList: document.getElementById('attributes-list'),
  addAttributeBtn: document.getElementById('add-attribute'),
  attributeSuggestionsList: document.getElementById('attribute-name-suggestions'),
  variationsSection: document.getElementById('variations-section'),
};

let productType = 'simple';
let attributeSuggestions = [];

const imageGallery = App.createImageGallery({
  grid: els.imagesGrid,
  fileInput: els.imageUpload,
  uploadStatus: els.uploadStatus,
  addUrlBtn: els.addImage,
});

init();

async function init() {
  try {
    await ensureSession();
    await loadCategories();
    await loadCustomTaxonomies();
    await loadAttributeSuggestions();

    if (window.PRODUCT_ID > 0) {
      await loadProduct(window.PRODUCT_ID);
      if (window.CURRENT_USER.role === 'admin' || window.CURRENT_USER.role === 'superadmin') {
        els.deleteBtn.classList.remove('hidden');
      }
    } else {
      updateSaveLabel();
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
  App.renderTaxonomySections(els.customTaxonomies, taxonomies, reason);
}

/**
 * Loads {name, options} attribute suggestions from other products so the
 * attribute-name field can autocomplete via a native <datalist> - picking
 * a suggested name fills its options in for you instead of retyping the
 * same "Red, Blue, Green" on every product.
 */
async function loadAttributeSuggestions() {
  try {
    const data = await App.api('/api/product-attributes.php');
    attributeSuggestions = data.items || [];
    els.attributeSuggestionsList.innerHTML = attributeSuggestions
      .map((s) => `<option value="${escapeHtml(s.name)}"></option>`)
      .join('');
  } catch (e) {
    // Optional convenience feature; ignore failures.
  }
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
    updateSaveLabel();

    setProductType(item.type === 'variable' ? 'variable' : 'simple');
    (item.attributes || []).forEach((attr) => addAttributeRow(attr.name, (attr.options || []).join(', ')));
    if (item.type === 'variable' && window.PRODUCT_ID > 0) {
      els.variationsSection.classList.remove('hidden');
      App.renderVariationsSection(els.variationsSection, item);
    }

    if (item.permalink && els.viewLink) {
      els.viewLink.href = item.permalink;
      els.viewLink.classList.remove('hidden');
    }

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

    imageGallery.setImages(item.images || []);
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function updateSaveLabel() {
  const statusText = els.status.options[els.status.selectedIndex].text;
  els.saveBtn.dataset.label = statusText;
  els.saveBtn.textContent = statusText;
}

function bindEvents() {
  els.status.addEventListener('change', updateSaveLabel);

  els.productTypeBtns.forEach((btn) => {
    btn.addEventListener('click', () => setProductType(btn.dataset.type));
  });
  els.addAttributeBtn.addEventListener('click', () => addAttributeRow());
  if (window.PRODUCT_ID <= 0) {
    setProductType('simple');
  }

  els.aiGenerateShort.addEventListener('click', () => generateDescription('short', els.aiGenerateShort, els.shortDescription));
  els.aiGenerateLong.addEventListener('click', () => generateDescription('long', els.aiGenerateLong, els.description));

  let submitting = false;
  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submitting) return;
    submitting = true;
    els.saveBtn.disabled = true;
    els.saveBtn.textContent = t('saving');

    if (productType === 'variable' && collectAttributes().length === 0) {
      App.toast(t('variable_product_needs_attribute'), 'error');
      submitting = false;
      els.saveBtn.disabled = false;
      els.saveBtn.textContent = els.saveBtn.dataset.label || t('save');
      return;
    }

    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      type: productType,
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      description: els.description.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: imageGallery.getImages(),
      taxonomies: collectCustomTaxonomies(),
    };
    if (productType === 'variable') {
      payload.attributes = collectAttributes();
    }

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
      submitting = false;
      els.saveBtn.disabled = false;
      els.saveBtn.textContent = els.saveBtn.dataset.label || t('save');
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

function setProductType(type) {
  productType = type;
  els.productTypeBtns.forEach((btn) => {
    const active = btn.dataset.type === type;
    btn.classList.toggle('bg-gray-900', active);
    btn.classList.toggle('text-white', active);
    btn.classList.toggle('border-gray-900', active);
  });
  els.attributesSection.classList.toggle('hidden', type !== 'variable');
  if (type === 'variable' && els.attributesList.children.length === 0) {
    addAttributeRow();
  }
}

function addAttributeRow(name = '', options = '') {
  const row = document.createElement('div');
  row.className = 'attribute-row flex gap-2 items-start';
  row.innerHTML = `
    <div class="flex-1 space-y-1">
      <input type="text" list="attribute-name-suggestions" class="attribute-name w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_name'))}" value="${escapeHtml(name)}">
      <input type="text" class="attribute-options w-full rounded-lg border border-gray-300 px-2 py-1.5 text-sm" placeholder="${escapeHtml(t('attribute_options_hint'))}" value="${escapeHtml(options)}">
    </div>
    <button type="button" class="remove-attribute-row text-red-500 text-lg leading-none mt-1.5">&times;</button>
  `;
  row.querySelector('.remove-attribute-row').addEventListener('click', () => row.remove());

  const nameInput = row.querySelector('.attribute-name');
  const optionsInput = row.querySelector('.attribute-options');
  nameInput.addEventListener('change', () => {
    if (optionsInput.value.trim() !== '') return;
    const match = attributeSuggestions.find((s) => s.name.toLowerCase() === nameInput.value.trim().toLowerCase());
    if (match) optionsInput.value = match.options.join(', ');
  });

  els.attributesList.appendChild(row);
}

function collectAttributes() {
  return Array.from(els.attributesList.querySelectorAll('.attribute-row')).map((row) => ({
    name: row.querySelector('.attribute-name').value.trim(),
    options: row.querySelector('.attribute-options').value.split(',').map((o) => o.trim()).filter((o) => o !== ''),
  })).filter((attr) => attr.name !== '' && attr.options.length > 0);
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function stripHtml(html) {
  const div = document.createElement('div');
  div.innerHTML = html;
  return div.textContent || '';
}
