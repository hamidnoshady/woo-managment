/**
 * Step-by-step "Add product" wizard.
 */

const STEP_KEYS = [
  'wizard_step_basic',
  'wizard_step_pricing',
  'wizard_step_inventory',
  'wizard_step_categories',
  'wizard_step_images',
  'wizard_step_description',
  'wizard_step_review',
];

const els = {
  loading: document.getElementById('loading'),
  form: document.getElementById('wizard-form'),
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
  editWhiteBg: document.getElementById('edit-white-bg'),
  editEnhance: document.getElementById('edit-enhance'),
  editResize: document.getElementById('edit-resize'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerate: document.getElementById('ai-generate-description'),
  status: document.getElementById('status'),
  stepIndicator: document.getElementById('step-indicator'),
  progressBar: document.getElementById('progress-bar'),
  backBtn: document.getElementById('wizard-back'),
  nextBtn: document.getElementById('wizard-next'),
  publishBtn: document.getElementById('wizard-publish'),
  reviewSummary: document.getElementById('review-summary'),
};

const steps = Array.from(document.querySelectorAll('.wizard-step'));
let currentStep = 0;

init();

async function init() {
  try {
    await ensureSession();
    await loadCategories();
    await loadCustomTaxonomies();

    addImageRow('');
    bindEvents();
    showStep(0);
  } catch (e) {
    // Don't leave the page stuck on the loading state if anything above
    // throws unexpectedly; surface the error and still show the form so
    // the user isn't left looking at a blank page.
    console.error('Product wizard failed to initialize:', e);
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
    data.items.forEach((cat) => {
      const label = document.createElement('label');
      label.className = 'flex items-center gap-2 text-sm text-gray-700';
      label.innerHTML = `<input type="checkbox" value="${cat.id}" class="category-checkbox h-4 w-4 rounded border-gray-300"> ${escapeHtml(cat.name)}`;
      els.categoriesList.appendChild(label);
    });
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

async function loadCustomTaxonomies() {
  try {
    const data = await App.api('/api/taxonomies.php');
    renderCustomTaxonomies(data.items || []);
  } catch (e) {
    // Optional feature; ignore failures (e.g. no WordPress credentials).
  }
}

function renderCustomTaxonomies(taxonomies) {
  els.customTaxonomies.innerHTML = '';
  if (!taxonomies.length) return;

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

function collectCustomTaxonomies() {
  const result = {};
  els.customTaxonomies.querySelectorAll('[data-rest-base]').forEach((section) => {
    result[section.dataset.restBase] = Array.from(section.querySelectorAll('.custom-term-checkbox:checked')).map((cb) => cb.value);
  });
  return result;
}

function showStep(index) {
  currentStep = index;

  steps.forEach((step) => {
    step.classList.toggle('hidden', Number(step.dataset.step) !== index);
  });

  els.stepIndicator.textContent = t('wizard_step', index + 1, steps.length);
  els.progressBar.style.width = `${((index + 1) / steps.length) * 100}%`;

  els.backBtn.classList.toggle('hidden', index === 0);
  els.nextBtn.classList.toggle('hidden', index === steps.length - 1);
  els.publishBtn.classList.toggle('hidden', index !== steps.length - 1);

  if (index === steps.length - 1) {
    renderReview();
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(index) {
  if (index === 0 && els.name.value.trim() === '') {
    App.toast(t('name_required'), 'error');
    els.name.focus();
    return false;
  }
  return true;
}

function renderReview() {
  const selectedCategories = Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked'))
    .map((cb) => cb.parentElement.textContent.trim());

  const images = Array.from(els.imagesList.querySelectorAll('.image-url'))
    .map((input) => input.value.trim())
    .filter((v) => v !== '');

  const customTaxonomies = [];
  els.customTaxonomies.querySelectorAll('[data-rest-base]').forEach((section) => {
    const names = Array.from(section.querySelectorAll('.custom-term-checkbox:checked'))
      .map((cb) => cb.parentElement.textContent.trim());
    if (names.length) {
      customTaxonomies.push(`${section.querySelector('h2').textContent}: ${names.join(', ')}`);
    }
  });

  const rows = [
    [t('name'), els.name.value.trim() || '—'],
    [t('sku'), els.sku.value.trim() || '—'],
    [t('regular_price'), els.regularPrice.value || '—'],
    [t('sale_price'), els.salePrice.value || '—'],
    [t('stock_quantity'), els.stockQuantity.value || '0'],
    [t('stock_status'), els.stockStatus.options[els.stockStatus.selectedIndex].text],
    [t('categories'), selectedCategories.length ? selectedCategories.join(', ') : '—'],
    [t('images_urls'), images.length ? String(images.length) : '—'],
    [t('status'), els.status.options[els.status.selectedIndex].text],
  ];

  customTaxonomies.forEach((line) => rows.push(['', line]));

  els.reviewSummary.innerHTML = rows.map(([label, value]) => `
    <div class="flex justify-between gap-3 border-b border-gray-50 pb-2 last:border-0 last:pb-0">
      <span class="text-gray-400">${escapeHtml(label)}</span>
      <span class="font-medium text-gray-900 text-right">${escapeHtml(value)}</span>
    </div>
  `).join('');
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

    els.imageUpload.disabled = true;
    try {
      const data = await App.api('/api/media.php', { method: 'POST', body: formData });
      addImageRow(data.item.src);
    } catch (err) {
      App.toast(err.message, 'error');
    } finally {
      els.imageUpload.disabled = false;
      els.imageUpload.value = '';
    }
  });

  els.aiGenerate.addEventListener('click', async () => {
    const originalLabel = els.aiGenerate.textContent;
    els.aiGenerate.disabled = true;
    els.aiGenerate.textContent = t('generating');

    try {
      const payload = {
        name: els.name.value.trim(),
        categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked'))
          .map((cb) => cb.parentElement.textContent.trim()),
        attributes: {
          [t('sku')]: els.sku.value.trim(),
        },
      };
      const data = await App.api('/api/ai.php?action=describe', {
        method: 'POST',
        body: JSON.stringify(payload),
      });
      els.shortDescription.value = data.short_description || '';
      els.description.value = data.description || '';
      App.toast(t('ai_description_generated'), 'success');
    } catch (err) {
      App.toast(err.message, 'error');
    } finally {
      els.aiGenerate.disabled = false;
      els.aiGenerate.textContent = originalLabel;
    }
  });

  els.backBtn.addEventListener('click', () => {
    if (currentStep > 0) showStep(currentStep - 1);
  });

  els.nextBtn.addEventListener('click', () => {
    if (!validateStep(currentStep)) return;
    if (currentStep < steps.length - 1) showStep(currentStep + 1);
  });

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validateStep(0)) {
      showStep(0);
      return;
    }

    const originalLabel = els.publishBtn.textContent;
    els.publishBtn.disabled = true;
    els.publishBtn.textContent = t('wizard_publishing');

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

    try {
      const data = await App.api('/api/product.php', {
        method: 'POST',
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
      els.publishBtn.disabled = false;
      els.publishBtn.textContent = originalLabel;
    }
  });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function escapeAttr(str) {
  return (str ?? '').replace(/"/g, '&quot;');
}
