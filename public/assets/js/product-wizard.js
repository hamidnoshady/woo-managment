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
  imagesGrid: document.getElementById('images-grid'),
  addImage: document.getElementById('add-image'),
  imageUpload: document.getElementById('image-upload'),
  uploadStatus: document.getElementById('upload-status'),
  shortDescription: document.getElementById('short_description'),
  description: document.getElementById('description'),
  aiGenerateShort: document.getElementById('ai-generate-short'),
  aiGenerateLong: document.getElementById('ai-generate-long'),
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
    .map((cb) => cb.parentElement.querySelector('.cat-name').textContent.trim());

  const images = imageGallery.getImages();

  const customTaxonomies = [];
  els.customTaxonomies.querySelectorAll('[data-rest-base]').forEach((section) => {
    const names = Array.from(section.querySelectorAll('.custom-term-checkbox:checked'))
      .map((cb) => cb.parentElement.textContent.trim());
    if (names.length) {
      customTaxonomies.push(`${section.querySelector('summary').textContent}: ${names.join(', ')}`);
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

function updatePublishLabel() {
  const statusText = els.status.options[els.status.selectedIndex].text;
  els.publishBtn.dataset.label = statusText;
  els.publishBtn.textContent = statusText;
}

function bindEvents() {
  els.status.addEventListener('change', updatePublishLabel);
  updatePublishLabel();

  els.aiGenerateShort.addEventListener('click', () => generateDescription('short', els.aiGenerateShort, els.shortDescription));
  els.aiGenerateLong.addEventListener('click', () => generateDescription('long', els.aiGenerateLong, els.description));

  els.backBtn.addEventListener('click', () => {
    if (currentStep > 0) showStep(currentStep - 1);
  });

  els.nextBtn.addEventListener('click', () => {
    if (!validateStep(currentStep)) return;
    if (currentStep < steps.length - 1) showStep(currentStep + 1);
  });

  let submitting = false;
  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submitting) return;
    if (!validateStep(0)) {
      showStep(0);
      return;
    }

    submitting = true;
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
      images: imageGallery.getImages(),
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
      submitting = false;
      els.publishBtn.disabled = false;
      els.publishBtn.textContent = els.publishBtn.dataset.label;
    }
  });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}
