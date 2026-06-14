/**
 * Product list page: search, filters, infinite scroll, quick stock adjust,
 * and multi-select for batch operations.
 */

const state = {
  page: 1,
  totalPages: 1,
  loading: false,
  selectionMode: false,
  selected: new Set(),
  filters: {
    search: '',
    category: '',
    stock_status: '',
    min_price: '',
    max_price: '',
    on_sale: false,
    sort: 'date-desc',
  },
};

const listEl = document.getElementById('product-list');
const loadMoreWrap = document.getElementById('load-more-wrap');
const loadMoreBtn = document.getElementById('load-more');
const emptyState = document.getElementById('empty-state');

init();

async function init() {
  await ensureSession();
  await loadCategories();
  bindEvents();
  await loadProducts(true);

  App.onUndo = () => loadProducts(true);
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

async function loadCategories() {
  try {
    const data = await App.api('/api/categories.php');
    const select = document.getElementById('filter-category');
    data.items.forEach((cat) => {
      const opt = document.createElement('option');
      opt.value = cat.id;
      opt.textContent = `${cat.name} (${cat.count})`;
      select.appendChild(opt);
    });
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function buildQuery(page) {
  const f = state.filters;
  const params = new URLSearchParams();
  params.set('page', page);
  params.set('per_page', 20);

  if (f.search) params.set('search', f.search);
  if (f.category) params.set('category', f.category);
  if (f.stock_status) params.set('stock_status', f.stock_status);
  if (f.min_price) params.set('min_price', f.min_price);
  if (f.max_price) params.set('max_price', f.max_price);
  if (f.on_sale) params.set('on_sale', '1');

  const [orderby, order] = sortToParams(f.sort);
  params.set('orderby', orderby);
  params.set('order', order);

  return params.toString();
}

function sortToParams(sort) {
  switch (sort) {
    case 'title-asc': return ['title', 'asc'];
    case 'title-desc': return ['title', 'desc'];
    case 'price-asc': return ['price', 'asc'];
    case 'price-desc': return ['price', 'desc'];
    default: return ['date', 'desc'];
  }
}

async function loadProducts(reset) {
  if (state.loading) return;
  state.loading = true;

  if (reset) {
    state.page = 1;
    listEl.innerHTML = '';
    showSkeletons();
  }

  try {
    const data = await App.api(`/api/products.php?${buildQuery(state.page)}`);
    if (reset) {
      listEl.innerHTML = '';
    }

    state.totalPages = data.total_pages;

    if (data.items.length === 0 && state.page === 1) {
      emptyState.classList.remove('hidden');
    } else {
      emptyState.classList.add('hidden');
      data.items.forEach((product) => listEl.appendChild(renderProductCard(product)));
    }

    loadMoreWrap.classList.toggle('hidden', state.page >= state.totalPages);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    state.loading = false;
  }
}

function showSkeletons() {
  for (let i = 0; i < 4; i++) {
    const card = document.createElement('div');
    card.className = 'bg-white rounded-2xl border border-gray-100 p-3 flex gap-3';
    card.innerHTML = `
      <div class="skeleton h-16 w-16 rounded-xl flex-shrink-0"></div>
      <div class="flex-1 space-y-2 py-1">
        <div class="skeleton h-3 w-3/4 rounded"></div>
        <div class="skeleton h-3 w-1/2 rounded"></div>
        <div class="skeleton h-3 w-1/3 rounded"></div>
      </div>`;
    listEl.appendChild(card);
  }
}

function renderProductCard(product) {
  const card = document.createElement('div');
  card.className = 'bg-white rounded-2xl border border-gray-100 p-3 flex gap-3 relative';
  card.dataset.id = product.id;

  const stockBadge = stockStatusBadge(product.stock_status);

  const image = product.image
    ? `<img src="${escapeHtml(product.image)}" alt="" class="h-16 w-16 rounded-xl object-cover flex-shrink-0 bg-gray-100">`
    : `<div class="h-16 w-16 rounded-xl bg-gray-100 flex-shrink-0 flex items-center justify-center text-gray-300 text-xs">No image</div>`;

  card.innerHTML = `
    <div class="checkbox-wrap hidden flex items-center pr-1">
      <input type="checkbox" class="select-checkbox h-5 w-5 rounded border-gray-300">
    </div>
    <a href="/product-edit.php?id=${product.id}" class="card-link flex-shrink-0">${image}</a>
    <div class="flex-1 min-w-0">
      <a href="/product-edit.php?id=${product.id}" class="card-link block">
        <div class="text-sm font-medium text-gray-900 line-clamp-2">${escapeHtml(product.name)}</div>
        <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(product.sku || '')}</div>
      </a>
      <div class="mt-1 flex items-center gap-2 price-row"></div>
    </div>
    <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="1">+</button>
      <span class="stock-qty text-xs font-medium text-gray-700">${product.stock_quantity ?? '-'}</span>
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="-1">-</button>
    </div>
  `;

  renderPriceRow(card, product);

  // Quick stock adjust
  card.querySelectorAll('.stock-btn').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      const delta = parseInt(btn.dataset.delta, 10);
      btn.disabled = true;
      try {
        const result = await App.api('/api/stock.php', {
          method: 'POST',
          body: JSON.stringify({ id: product.id, delta }),
        });
        card.querySelector('.stock-qty').textContent = result.stock_quantity;
        product.stock_quantity = result.stock_quantity;
        const badgeWrap = card.querySelector('.stock-badge-wrap');
        if (badgeWrap) {
          badgeWrap.outerHTML = stockStatusBadge(result.stock_status);
        }
        if (result.log_id) {
          App.notify(result.message, { logId: result.log_id });
        }
      } catch (err) {
        App.toast(err.message, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });

  // Selection mode handling
  const checkbox = card.querySelector('.select-checkbox');
  checkbox.addEventListener('change', () => {
    if (checkbox.checked) {
      state.selected.add(product.id);
    } else {
      state.selected.delete(product.id);
    }
    updateSelectionBar();
  });

  card.querySelectorAll('.card-link').forEach((link) => {
    link.addEventListener('click', (e) => {
      if (state.selectionMode) {
        e.preventDefault();
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
      }
    });
  });

  if (state.selectionMode) {
    card.querySelector('.checkbox-wrap').classList.remove('hidden');
  }

  return card;
}

/**
 * Renders the price for a product card. Clicking the price turns it into an
 * editable field; on save, it's sent to the API and the card is updated
 * in place with a success notification (and undo, if available).
 */
function renderPriceRow(card, product) {
  const row = card.querySelector('.price-row');
  const stockBadge = stockStatusBadge(product.stock_status);

  const priceHtml = product.on_sale && product.sale_price
    ? `<span class="text-sm font-semibold text-gray-900">${App.formatToman(product.sale_price)}</span>
       <span class="text-xs text-gray-400 line-through ml-1">${App.formatToman(product.regular_price)}</span>`
    : `<span class="text-sm font-semibold text-gray-900">${App.formatToman(product.price)}</span>`;

  row.innerHTML = `
    <button type="button" class="price-display text-left">${priceHtml}</button>
    ${stockBadge}
  `;

  row.querySelector('.price-display').addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (state.selectionMode) return;
    showPriceEditor(card, row, product);
  });
}

function showPriceEditor(card, row, product) {
  row.innerHTML = `
    <input type="number" inputmode="decimal" min="0" step="any"
           class="price-input regular-price-input w-24 rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
           value="${escapeHtml(product.regular_price ?? '')}">
    ${product.on_sale ? `<input type="number" inputmode="decimal" min="0" step="any"
           class="price-input sale-price-input w-24 rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
           placeholder="${escapeHtml(t('sale_price'))}"
           value="${escapeHtml(product.sale_price ?? '')}">` : ''}
  `;

  const regularInput = row.querySelector('.regular-price-input');
  const saleInput = row.querySelector('.sale-price-input');

  let saved = false;
  const save = async () => {
    if (saved) return;
    saved = true;

    const regularValue = regularInput.value.trim();
    const saleValue = saleInput ? saleInput.value.trim() : null;

    const regularChanged = regularValue !== '' && regularValue !== String(product.regular_price ?? '');
    const saleChanged = saleInput !== null && saleValue !== String(product.sale_price ?? '');

    if (!regularChanged && !saleChanged) {
      renderPriceRow(card, product);
      return;
    }

    const payload = { id: product.id };
    if (regularChanged) payload.regular_price = regularValue;
    if (saleChanged) payload.sale_price = saleValue;

    regularInput.disabled = true;
    if (saleInput) saleInput.disabled = true;

    try {
      const data = await App.api('/api/product.php', {
        method: 'PUT',
        body: JSON.stringify(payload),
      });

      product.regular_price = data.item.regular_price;
      product.sale_price = data.item.sale_price;
      product.price = data.item.price;
      product.on_sale = !!(data.item.sale_price && parseFloat(data.item.sale_price) < parseFloat(data.item.regular_price));

      renderPriceRow(card, product);

      if (data.item.log_id) {
        App.notify(data.item.message, { logId: data.item.log_id });
      }
    } catch (err) {
      App.toast(err.message, 'error');
      renderPriceRow(card, product);
    }
  };

  const cancel = () => {
    saved = true;
    renderPriceRow(card, product);
  };

  const onKeydown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      save();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      cancel();
    }
  };

  const onFocusOut = (e) => {
    if (row.contains(e.relatedTarget)) return;
    save();
  };

  row.addEventListener('click', (e) => e.stopPropagation());
  row.addEventListener('keydown', onKeydown);
  row.addEventListener('focusout', onFocusOut);

  regularInput.focus();
  regularInput.select();
}

function stockStatusBadge(status) {
  const map = {
    instock: [t('in_stock'), 'bg-green-100 text-green-700'],
    outofstock: [t('out_of_stock'), 'bg-red-100 text-red-700'],
    onbackorder: [t('backorder'), 'bg-yellow-100 text-yellow-700'],
  };
  const [label, cls] = map[status] || [t('unknown'), 'bg-gray-100 text-gray-600'];
  return `<span class="stock-badge-wrap"><span class="text-[10px] font-medium px-1.5 py-0.5 rounded ${cls}">${label}</span></span>`;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function bindEvents() {
  // Search with debounce
  let searchTimeout;
  document.getElementById('search-input').addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      state.filters.search = e.target.value.trim();
      loadProducts(true);
    }, 350);
  });

  // Load more
  loadMoreBtn.addEventListener('click', () => {
    state.page += 1;
    loadProducts(false);
  });

  // Filter sheet
  const sheet = document.getElementById('filter-sheet');
  document.getElementById('filter-btn').addEventListener('click', () => sheet.classList.remove('hidden'));
  document.getElementById('filter-close').addEventListener('click', () => sheet.classList.add('hidden'));
  document.getElementById('filter-overlay').addEventListener('click', () => sheet.classList.add('hidden'));

  document.getElementById('filter-apply').addEventListener('click', () => {
    const f = state.filters;
    f.category = document.getElementById('filter-category').value;
    f.stock_status = document.getElementById('filter-stock').value;
    f.min_price = document.getElementById('filter-min-price').value;
    f.max_price = document.getElementById('filter-max-price').value;
    f.on_sale = document.getElementById('filter-on-sale').checked;
    f.sort = document.getElementById('filter-sort').value;

    updateFilterBadge();
    sheet.classList.add('hidden');
    loadProducts(true);
  });

  document.getElementById('filter-reset').addEventListener('click', () => {
    state.filters.category = '';
    state.filters.stock_status = '';
    state.filters.min_price = '';
    state.filters.max_price = '';
    state.filters.on_sale = false;
    state.filters.sort = 'date-desc';

    document.getElementById('filter-category').value = '';
    document.getElementById('filter-stock').value = '';
    document.getElementById('filter-min-price').value = '';
    document.getElementById('filter-max-price').value = '';
    document.getElementById('filter-on-sale').checked = false;
    document.getElementById('filter-sort').value = 'date-desc';

    updateFilterBadge();
    sheet.classList.add('hidden');
    loadProducts(true);
  });

  // Selection mode
  document.getElementById('select-toggle').addEventListener('click', () => {
    state.selectionMode = !state.selectionMode;
    state.selected.clear();
    updateSelectionBar();

    document.getElementById('select-toggle').textContent = state.selectionMode ? t('cancel') : t('select');
    document.getElementById('add-fab').classList.toggle('hidden', state.selectionMode);

    document.querySelectorAll('#product-list > div').forEach((card) => {
      const wrap = card.querySelector('.checkbox-wrap');
      const checkbox = card.querySelector('.select-checkbox');
      if (wrap) wrap.classList.toggle('hidden', !state.selectionMode);
      if (checkbox) checkbox.checked = false;
    });
  });

  document.getElementById('selection-cancel').addEventListener('click', () => {
    document.getElementById('select-toggle').click();
  });

  document.getElementById('selection-batch').addEventListener('click', () => {
    if (state.selected.size === 0) return;
    sessionStorage.setItem('batch_ids', JSON.stringify(Array.from(state.selected)));
    window.location.href = '/batch.php';
  });

  // Logout
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());

  // Infinite scroll
  window.addEventListener('scroll', () => {
    if (state.loading || state.page >= state.totalPages) return;
    if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 600) {
      state.page += 1;
      loadProducts(false);
    }
  });
}

function updateSelectionBar() {
  const bar = document.getElementById('selection-bar');
  const count = document.getElementById('selection-count');
  if (state.selectionMode && state.selected.size > 0) {
    bar.classList.remove('hidden');
    count.textContent = `${state.selected.size} ${t('selected_count')}`;
  } else {
    bar.classList.add('hidden');
  }
}

function updateFilterBadge() {
  const f = state.filters;
  const active = !!(f.category || f.stock_status || f.min_price || f.max_price || f.on_sale || f.sort !== 'date-desc');
  document.getElementById('filter-badge').classList.toggle('hidden', !active);
}
