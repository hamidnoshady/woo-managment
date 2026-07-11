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
  selectAllMatchingFilters: false,
  lastTotal: 0,
  filters: {
    search: '',
    category: '',
    stock_status: '',
    min_price: '',
    max_price: '',
    on_sale: false,
    sort: 'date-desc',
    taxonomies: {},
  },
};

const listEl = document.getElementById('product-list');
const tableEl = document.getElementById('product-table');
const tableBody = document.getElementById('product-table-body');
const loadMoreWrap = document.getElementById('load-more-wrap');
const loadMoreBtn = document.getElementById('load-more');
const emptyState = document.getElementById('empty-state');

const viewPrefs = {
  view: localStorage.getItem('products_view') === 'table' ? 'table' : 'grid',
  columns: [1, 2, 3].includes(parseInt(localStorage.getItem('products_columns'), 10))
    ? parseInt(localStorage.getItem('products_columns'), 10)
    : 2,
};

init();

async function init() {
  // Bind click handlers first: none of them need categories/taxonomies to
  // already be loaded, and gating them behind three sequential network
  // round-trips left the toolbar buttons unresponsive to any click that
  // happened before those requests finished.
  bindEvents();
  applyViewPrefs();
  await ensureSession();
  await loadCategories();
  await loadCustomTaxonomyFilters();
  await loadProducts(true);

  App.onUndo = (logId, productId) => refreshSingleProduct(productId);
}

/**
 * Returns the list/table container currently visible, used both to render
 * newly-fetched products and to look up an existing row/card to patch.
 */
function activeContainer() {
  return viewPrefs.view === 'table' ? tableBody : listEl;
}

function renderProductElement(product) {
  return viewPrefs.view === 'table' ? renderProductRow(product) : renderProductCard(product);
}

function setView(view) {
  if (viewPrefs.view === view) return;
  viewPrefs.view = view;
  localStorage.setItem('products_view', view);
  applyViewPrefs();
  loadProducts(true);
}

/**
 * Applies the saved view (grid/table) and column count to the DOM and
 * highlights the matching toolbar buttons.
 */
function applyViewPrefs() {
  const isTable = viewPrefs.view === 'table';
  listEl.classList.toggle('hidden', isTable);
  tableEl.classList.toggle('hidden', !isTable);
  // ponytail: Tailwind's `hidden` loses to `lg:grid` at the lg breakpoint
  // (responsive utilities sit later in the stylesheet, same specificity),
  // so toggling the class alone leaves the grid visible on desktop too.
  // Force the hidden side off with an !important inline override, and
  // clear it on the visible side so its own responsive classes apply.
  listEl.style.setProperty('display', isTable ? 'none' : '', isTable ? 'important' : '');
  tableEl.style.setProperty('display', isTable ? '' : 'none', isTable ? '' : 'important');
  document.getElementById('column-choice').classList.toggle('hidden', isTable);

  listEl.classList.remove('lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3');
  listEl.classList.add(`lg:grid-cols-${viewPrefs.columns}`);

  document.querySelectorAll('.view-choice-btn').forEach((btn) => {
    btn.classList.toggle('bg-gray-900', (btn.id === 'view-table-btn') === isTable);
    btn.classList.toggle('text-white', (btn.id === 'view-table-btn') === isTable);
  });
  document.querySelectorAll('.col-choice-btn').forEach((btn) => {
    const active = parseInt(btn.dataset.cols, 10) === viewPrefs.columns;
    btn.classList.toggle('bg-gray-900', active);
    btn.classList.toggle('text-white', active);
  });
}

/**
 * Re-fetches a single product (used after an undo) and patches its card in
 * place, then scrolls it into view with a brief highlight. Avoids resetting
 * the whole list/scroll position the way a full reload would.
 */
async function refreshSingleProduct(productId) {
  if (!productId) return;
  const el = activeContainer().querySelector(`[data-id="${productId}"]`);
  if (!el) return;

  try {
    const data = await App.api(`/api/product.php?id=${productId}&summary=1`);
    const updated = data.item;
    const fresh = renderProductElement(updated);
    el.replaceWith(fresh);
    fresh.scrollIntoView({ behavior: 'smooth', block: 'center' });
    fresh.classList.add('highlight-flash');
    setTimeout(() => fresh.classList.remove('highlight-flash'), 1500);
  } catch (e) {
    // The product may have been deleted/undone away entirely; leave the
    // list as-is rather than forcing a disruptive full reload.
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
      opt.textContent = `${'— '.repeat(cat.depth || 0)}${cat.name} (${cat.count})`;
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

  Object.entries(f.taxonomies).forEach(([restBase, termId]) => {
    if (termId) params.set(`tax_${restBase}`, termId);
  });

  const [orderby, order] = sortToParams(f.sort);
  params.set('orderby', orderby);
  params.set('order', order);

  return params.toString();
}

async function loadCustomTaxonomyFilters() {
  const container = document.getElementById('custom-taxonomy-filters');
  try {
    const data = await App.api('/api/taxonomies.php');
    const items = data.items || [];

    if (!items.length && data.reason) {
      const notice = document.createElement('p');
      notice.className = 'text-xs text-gray-400';
      notice.textContent = data.reason === 'no_credentials' ? t('wp_credentials_missing') : t('no_custom_taxonomies');
      container.appendChild(notice);
      return;
    }

    items.forEach((tax) => {
      const wrap = document.createElement('div');

      const label = document.createElement('label');
      label.className = 'block text-sm font-medium text-gray-700 mb-1';
      label.textContent = tax.name;
      wrap.appendChild(label);

      const select = document.createElement('select');
      select.id = `filter-tax-${tax.rest_base}`;
      select.dataset.restBase = tax.rest_base;
      select.className = 'w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm';

      const allOpt = document.createElement('option');
      allOpt.value = '';
      allOpt.textContent = t('all');
      select.appendChild(allOpt);

      (tax.terms || []).forEach((term) => {
        const opt = document.createElement('option');
        opt.value = term.id;
        opt.textContent = term.name;
        select.appendChild(opt);
      });

      wrap.appendChild(select);
      container.appendChild(wrap);
    });
  } catch (e) {
    // Optional feature; ignore failures (e.g. no WordPress credentials).
  }
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

  const container = activeContainer();

  if (reset) {
    state.page = 1;
    container.innerHTML = '';
    showSkeletons(container);
  }

  try {
    const data = await App.api(`/api/products.php?${buildQuery(state.page)}`);
    if (reset) {
      container.innerHTML = '';
    }

    state.totalPages = data.total_pages;
    state.lastTotal = data.total;
    updateSelectAllMatchingFiltersLink();

    if (data.items.length === 0 && state.page === 1) {
      emptyState.classList.remove('hidden');
    } else {
      emptyState.classList.add('hidden');
      data.items.forEach((product) => container.appendChild(renderProductElement(product)));
      applyRecentChangeHighlights(data.items);
    }

    loadMoreWrap.classList.toggle('hidden', state.page >= state.totalPages);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    state.loading = false;
  }
}

/**
 * Fetches recent-change info for the given products (fire-and-forget - a
 * failure here just means no highlights render, never a user-facing
 * error, since this is a non-essential decoration) and applies a colored
 * border + tooltip dot to each matching card/row already in the DOM.
 */
async function applyRecentChangeHighlights(products) {
  if (products.length === 0) return;

  const ids = products.map((p) => p.id).join(',');
  try {
    const data = await App.api(`/api/recent-changes.php?ids=${ids}`);
    products.forEach((product) => {
      const info = data.items[String(product.id)];
      if (!info) return;
      const el = activeContainer().querySelector(`[data-id="${product.id}"]`);
      if (el) applyRecentChangeHighlight(el, info.category, info.changed_at);
    });
  } catch (e) {
    // Non-essential decoration; ignore failures.
  }
}

function applyRecentChangeHighlight(el, category, changedAt) {
  const isRow = el.tagName === 'TR';
  const borderHost = isRow ? el.querySelector('td:nth-child(3)') : el;
  const dotHost = isRow ? el.querySelector('td:nth-child(2)') : el;
  if (!borderHost || !dotHost) return;

  borderHost.classList.add('recent-change', `recent-change-${category}`);
  dotHost.classList.add('recent-change-dot-host');

  const dot = document.createElement('span');
  dot.className = `recent-change-dot recent-change-dot-${category}`;
  dot.title = `${t(`recent_change_${category}`)} · ${formatRelativeTime(changedAt)}`;
  dotHost.appendChild(dot);
}

function formatRelativeTime(timestamp) {
  const locale = document.documentElement.lang === 'fa' ? 'fa-IR' : 'en-US';
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  const diffMinutes = Math.round((timestamp - Date.now() / 1000) / 60);
  if (Math.abs(diffMinutes) < 60) return rtf.format(diffMinutes, 'minute');
  return rtf.format(Math.round(diffMinutes / 60), 'hour');
}

function showSkeletons(container) {
  if (viewPrefs.view === 'table') return;
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
    container.appendChild(card);
  }
}

function renderProductCard(product) {
  const card = document.createElement('div');
  card.className = 'bg-white rounded-2xl border border-gray-100 p-3 flex gap-3 relative';
  card.dataset.id = product.id;

  const stockBadge = App.stockStatusBadge(product.stock_status);

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
      ${product.type === 'variable' ? '<button type="button" class="variations-toggle text-xs text-gray-500 underline mt-1">' + escapeHtml(t('show_variations')) + '</button><div class="variations-list mt-2 space-y-2 hidden"></div>' : ''}
    </div>
    <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="1">+</button>
      <span class="stock-qty text-xs font-medium text-gray-700">${product.stock_quantity ?? '-'}</span>
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="-1">-</button>
    </div>
  `;

  App.renderPriceRow(card, product, () => state.selectionMode);
  App.wireProductElement(card, product, {
    isSelectionMode: () => state.selectionMode,
    onSelectChange: (id, checked) => {
      if (checked) state.selected.add(id); else state.selected.delete(id);
      updateSelectionBar();
    },
  });
  if (product.type === 'variable') {
    App.renderVariationsSection(card, product, {
      isSelectionMode: () => state.selectionMode,
      onSelectChange: (id, checked) => {
        if (checked) state.selected.add(id); else state.selected.delete(id);
        updateSelectionBar();
      },
    });
  }
  return card;
}

/**
 * Renders a product as a <tr> for table view. Reuses the same price-row,
 * stock-button, and checkbox markup/classes as the card view so
 * App.wireProductElement() and App.renderPriceRow() work unchanged on either.
 */
function renderProductRow(product) {
  const row = document.createElement('tr');
  row.dataset.id = product.id;

  const image = product.image
    ? `<img src="${escapeHtml(product.image)}" alt="" class="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100">`
    : `<div class="h-10 w-10 rounded-lg bg-gray-100 flex-shrink-0"></div>`;

  row.innerHTML = `
    <td class="checkbox-wrap hidden px-3 py-2"><input type="checkbox" class="select-checkbox h-4 w-4 rounded border-gray-300"></td>
    <td class="px-3 py-2"><a href="/product-edit.php?id=${product.id}" class="card-link">${image}</a></td>
    <td class="px-3 py-2 min-w-0">
      <a href="/product-edit.php?id=${product.id}" class="card-link block">
        <div class="text-sm font-medium text-gray-900 line-clamp-1">${escapeHtml(product.name)}</div>
        <div class="text-xs text-gray-400">${escapeHtml(product.sku || '')}</div>
      </a>
    </td>
    <td class="px-3 py-2"><div class="price-row flex items-center gap-2"></div></td>
    <td class="px-3 py-2">
      <div class="stock-control flex items-center gap-1">
        <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="-1">-</button>
        <span class="stock-qty text-xs font-medium text-gray-700 w-6 text-center">${product.stock_quantity ?? '-'}</span>
        <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="1">+</button>
      </div>
    </td>
  `;

  const isSelectionMode = () => state.selectionMode;
  const onSelectChange = (id, checked) => {
    if (checked) state.selected.add(id); else state.selected.delete(id);
    updateSelectionBar();
  };

  App.renderPriceRow(row, product, isSelectionMode);
  App.wireProductElement(row, product, { isSelectionMode, onSelectChange });

  if (product.type !== 'variable') {
    return row;
  }

  const toggleRow = document.createElement('tr');
  toggleRow.className = 'variations-toggle-row';
  toggleRow.innerHTML = `
    <td></td>
    <td colspan="4" class="px-3 pb-2">
      <button type="button" class="variations-toggle text-xs text-gray-500 underline">${escapeHtml(t('show_variations'))}</button>
      <div class="variations-list mt-2 space-y-2 hidden"></div>
    </td>
  `;
  App.renderVariationsSection(toggleRow, product, { isSelectionMode, onSelectChange });

  const fragment = document.createDocumentFragment();
  fragment.appendChild(row);
  fragment.appendChild(toggleRow);
  return fragment;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

    f.taxonomies = {};
    document.querySelectorAll('#custom-taxonomy-filters select').forEach((select) => {
      if (select.value) f.taxonomies[select.dataset.restBase] = select.value;
    });

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
    state.filters.taxonomies = {};

    document.getElementById('filter-category').value = '';
    document.getElementById('filter-stock').value = '';
    document.getElementById('filter-min-price').value = '';
    document.getElementById('filter-max-price').value = '';
    document.getElementById('filter-on-sale').checked = false;
    document.getElementById('filter-sort').value = 'date-desc';
    document.querySelectorAll('#custom-taxonomy-filters select').forEach((select) => {
      select.value = '';
    });

    updateFilterBadge();
    sheet.classList.add('hidden');
    loadProducts(true);
  });

  // Selection mode: one top checkbox both enters/exits selection mode and
  // selects/deselects everything currently loaded.
  const selectAllCheckbox = document.getElementById('select-all-checkbox');
  selectAllCheckbox.addEventListener('change', () => {
    state.selectionMode = selectAllCheckbox.checked;
    state.selectAllMatchingFilters = false;
    if (!state.selectionMode) state.selected.clear();

    document.getElementById('add-fab').classList.toggle('hidden', state.selectionMode);
    document.querySelector('.checkbox-col-header').classList.toggle('hidden', !state.selectionMode);

    activeContainer().querySelectorAll('[data-id]').forEach((el) => {
      const wrap = el.querySelector('.checkbox-wrap');
      const checkbox = el.querySelector('.select-checkbox');
      if (wrap) wrap.classList.toggle('hidden', !state.selectionMode);
      if (checkbox) {
        checkbox.checked = state.selectionMode;
        if (state.selectionMode) state.selected.add(parseInt(el.dataset.id, 10));
      }
    });

    updateSelectionBar();
  });

  document.getElementById('select-all-matching-filters').addEventListener('click', () => {
    state.selectAllMatchingFilters = !state.selectAllMatchingFilters;
    if (state.selectAllMatchingFilters) {
      state.selected.clear();
    }
    updateSelectionBar();
    updateSelectAllMatchingFiltersLink();
  });

  document.getElementById('selection-cancel').addEventListener('click', () => {
    selectAllCheckbox.checked = false;
    selectAllCheckbox.dispatchEvent(new Event('change'));
  });

  document.getElementById('selection-batch').addEventListener('click', () => {
    if (state.selectAllMatchingFilters) {
      sessionStorage.setItem('batch_selection', JSON.stringify({ selectAll: true, filters: state.filters, total: state.lastTotal }));
    } else {
      if (state.selected.size === 0) return;
      sessionStorage.setItem('batch_selection', JSON.stringify({ ids: Array.from(state.selected) }));
    }
    window.location.href = '/batch.php';
  });

  // View (grid/table) and column count toggles
  document.getElementById('view-grid-btn').addEventListener('click', () => setView('grid'));
  document.getElementById('view-table-btn').addEventListener('click', () => setView('table'));
  document.querySelectorAll('.col-choice-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      viewPrefs.columns = parseInt(btn.dataset.cols, 10);
      localStorage.setItem('products_columns', viewPrefs.columns);
      applyViewPrefs();
    });
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
  const selectedCount = state.selectAllMatchingFilters ? state.lastTotal : state.selected.size;

  if (state.selectionMode && selectedCount > 0) {
    bar.classList.remove('hidden');
    count.textContent = `${selectedCount} ${t('selected_count')}`;
  } else {
    bar.classList.add('hidden');
  }

  const total = activeContainer().querySelectorAll('[data-id]').length;
  const selectAllCheckbox = document.getElementById('select-all-checkbox');
  selectAllCheckbox.checked = state.selectionMode && !state.selectAllMatchingFilters && total > 0 && state.selected.size === total;
  selectAllCheckbox.indeterminate = state.selectionMode && !state.selectAllMatchingFilters && state.selected.size > 0 && state.selected.size < total;

  updateSelectAllMatchingFiltersLink();
}

function updateSelectAllMatchingFiltersLink() {
  const link = document.getElementById('select-all-matching-filters');
  const loadedCount = activeContainer().querySelectorAll('[data-id]').length;

  // ponytail: hide rather than show a misleading count - resolving "select
  // all matching filters" server-side doesn't honor min_price/max_price.
  if (!state.selectionMode || state.lastTotal <= loadedCount || state.filters.min_price || state.filters.max_price) {
    link.classList.add('hidden');
    return;
  }

  link.classList.remove('hidden');
  link.textContent = state.selectAllMatchingFilters
    ? t('selected_count') + ': ' + state.lastTotal
    : t('select_all_matching_filters', state.lastTotal);
}

function updateFilterBadge() {
  const f = state.filters;
  const active = !!(f.category || f.stock_status || f.min_price || f.max_price || f.on_sale || f.sort !== 'date-desc' || Object.keys(f.taxonomies).length > 0);
  document.getElementById('filter-badge').classList.toggle('hidden', !active);
}
