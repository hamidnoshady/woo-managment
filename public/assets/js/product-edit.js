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
  imagesList: document.getElementById('images-list'),
  addImage: document.getElementById('add-image'),
  shortDescription: document.getElementById('short_description'),
  status: document.getElementById('status'),
  saveBtn: document.getElementById('save-btn'),
  deleteBtn: document.getElementById('delete-btn'),
};

init();

async function init() {
  await ensureSession();
  await loadCategories();

  if (window.PRODUCT_ID > 0) {
    await loadProduct(window.PRODUCT_ID);
    if (window.CURRENT_USER.role === 'admin' || window.CURRENT_USER.role === 'superadmin') {
      els.deleteBtn.classList.remove('hidden');
    }
  } else {
    addImageRow('');
  }

  els.loading.classList.add('hidden');
  els.form.classList.remove('hidden');

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
    els.status.value = item.status || 'publish';

    const selectedCategoryIds = new Set((item.categories || []).map((c) => String(c.id)));
    els.categoriesList.querySelectorAll('.category-checkbox').forEach((cb) => {
      cb.checked = selectedCategoryIds.has(cb.value);
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

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    els.saveBtn.disabled = true;
    els.saveBtn.textContent = 'Saving...';

    const payload = {
      name: els.name.value.trim(),
      sku: els.sku.value.trim(),
      regular_price: els.regularPrice.value,
      sale_price: els.salePrice.value,
      stock_quantity: els.stockQuantity.value === '' ? 0 : parseInt(els.stockQuantity.value, 10),
      stock_status: els.stockStatus.value,
      short_description: els.shortDescription.value,
      status: els.status.value,
      categories: Array.from(els.categoriesList.querySelectorAll('.category-checkbox:checked')).map((cb) => cb.value),
      images: Array.from(els.imagesList.querySelectorAll('.image-url'))
        .map((input) => input.value.trim())
        .filter((v) => v !== ''),
    };

    if (window.PRODUCT_ID > 0) {
      payload.id = window.PRODUCT_ID;
    }

    try {
      const data = await App.api('/api/product.php', {
        method: window.PRODUCT_ID > 0 ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });
      App.toast('Product saved', 'success');
      window.location.href = `/product-edit.php?id=${data.item.id}`;
    } catch (err) {
      App.toast(err.message, 'error');
    } finally {
      els.saveBtn.disabled = false;
      els.saveBtn.textContent = 'Save';
    }
  });

  els.deleteBtn.addEventListener('click', async () => {
    if (!confirm('Delete this product permanently?')) return;

    els.deleteBtn.disabled = true;
    try {
      await App.api(`/api/product.php?id=${window.PRODUCT_ID}`, { method: 'DELETE' });
      App.toast('Product deleted', 'success');
      window.location.href = '/products.php';
    } catch (err) {
      App.toast(err.message, 'error');
      els.deleteBtn.disabled = false;
    }
  });
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
