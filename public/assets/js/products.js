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

const variationsCache = new Map();

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
      ${product.type === 'variable' ? '<button type="button" class="variations-toggle text-xs text-gray-500 underline mt-1">' + escapeHtml(t('show_variations')) + '</button><div class="variations-list mt-2 space-y-2 hidden"></div>' : ''}
    </div>
    <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="1">+</button>
      <span class="stock-qty text-xs font-medium text-gray-700">${product.stock_quantity ?? '-'}</span>
      <button class="stock-btn rounded-lg border border-gray-300 w-7 h-7 text-sm leading-none" data-delta="-1">-</button>
    </div>
  `;

  renderPriceRow(card, product);
  wireProductElement(card, product);
  if (product.type === 'variable') {
    wireVariationsToggle(card, product);
  }
  return card;
}

/**
 * Renders a product as a <tr> for table view. Reuses the same price-row,
 * stock-button, and checkbox markup/classes as the card view so
 * wireProductElement() and renderPriceRow() work unchanged on either.
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

  renderPriceRow(row, product);
  wireProductElement(row, product);

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
  wireVariationsToggle(toggleRow, product);

  const fragment = document.createDocumentFragment();
  fragment.appendChild(row);
  fragment.appendChild(toggleRow);
  return fragment;
}

/**
 * Wires up the interactive bits shared by both the grid card and table row:
 * quick stock +/-, the selection checkbox, and click-to-select on the
 * card/title links.
 */
function wireProductElement(el, product) {
  el.querySelectorAll('.stock-btn').forEach((btn) => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      const delta = parseInt(btn.dataset.delta, 10);
      const otherBtn = [...el.querySelectorAll('.stock-btn')].find((b) => b !== btn);
      const qtyEl = el.querySelector('.stock-qty');
      const qtyOriginal = qtyEl.textContent;
      btn.disabled = true;
      if (otherBtn) otherBtn.disabled = true;
      qtyEl.classList.add('opacity-50');
      try {
        const result = await App.api('/api/stock.php', {
          method: 'POST',
          body: JSON.stringify({
            id: product.id,
            delta,
            current_quantity: product.stock_quantity,
            current_status: product.stock_status,
            name: product.name,
          }),
        });
        qtyEl.textContent = result.stock_quantity;
        product.stock_quantity = result.stock_quantity;
        product.stock_status = result.stock_status;
        const badgeWrap = el.querySelector('.stock-badge-wrap');
        if (badgeWrap) {
          badgeWrap.outerHTML = stockStatusBadge(result.stock_status);
        }
        if (result.log_id) {
          App.notify(result.message, { logId: result.log_id, productId: product.id });
        }
      } catch (err) {
        qtyEl.textContent = qtyOriginal;
        App.toast(err.message, 'error');
      } finally {
        btn.disabled = false;
        if (otherBtn) otherBtn.disabled = false;
        qtyEl.classList.remove('opacity-50');
      }
    });
  });

  // Selection mode handling
  const checkbox = el.querySelector('.select-checkbox');
  checkbox.addEventListener('change', () => {
    if (checkbox.checked) {
      state.selected.add(product.id);
    } else {
      state.selected.delete(product.id);
    }
    updateSelectionBar();
  });

  el.querySelectorAll('.card-link').forEach((link) => {
    link.addEventListener('click', (e) => {
      if (state.selectionMode) {
        e.preventDefault();
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
      }
    });
  });

  if (state.selectionMode) {
    el.querySelector('.checkbox-wrap').classList.remove('hidden');
  }
}

function wireVariationsToggle(container, product) {
  const toggleBtn = container.querySelector('.variations-toggle');
  const listEl = container.querySelector('.variations-list');

  toggleBtn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();

    const expanded = !listEl.classList.contains('hidden');
    if (expanded) {
      listEl.classList.add('hidden');
      toggleBtn.textContent = t('show_variations');
      return;
    }

    listEl.classList.remove('hidden');
    toggleBtn.textContent = t('hide_variations');

    if (variationsCache.has(product.id)) {
      renderVariationsList(listEl, product, variationsCache.get(product.id));
      return;
    }

    listEl.innerHTML = `<div class="text-xs text-gray-400">${escapeHtml(t('loading'))}</div>`;
    try {
      const data = await App.api(`/api/product-variations.php?product_id=${product.id}`);
      variationsCache.set(product.id, data);
      renderVariationsList(listEl, product, data);
    } catch (err) {
      listEl.innerHTML = `
        <div class="text-xs text-red-600">${escapeHtml(t('variations_load_error'))}
          <button type="button" class="variations-retry underline ml-1">${escapeHtml(t('retry'))}</button>
        </div>`;
      listEl.querySelector('.variations-retry').addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        toggleBtn.textContent = t('show_variations');
        listEl.classList.add('hidden');
        toggleBtn.click();
      });
    }
  });
}

function renderVariationsList(listEl, product, data) {
  listEl.innerHTML = '';
  (data.items || []).forEach((variation) => {
    listEl.appendChild(renderVariationRow(variation));
  });
  listEl.appendChild(renderAddVariationRow(listEl, product, data.options || []));
}

/**
 * Renders one variation as a compact row reusing the same price-click-edit
 * and stock +/- markup/handlers as a top-level product card - both
 * wireProductElement() and renderPriceRow() only ever look at `.id`,
 * `.regular_price`, `.stock_quantity`, etc. on the object they're given,
 * so a variation object (same field names) works unmodified.
 */
function renderVariationRow(variation) {
  const row = document.createElement('div');
  row.className = 'variation-row bg-gray-50 rounded-xl border border-gray-100 p-2 flex gap-2 relative';
  row.dataset.id = variation.id;

  const image = variation.image
    ? `<img src="${escapeHtml(variation.image)}" alt="" class="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100">`
    : `<div class="h-10 w-10 rounded-lg bg-gray-100 flex-shrink-0"></div>`;

  row.innerHTML = `
    <div class="checkbox-wrap hidden flex items-center pr-1">
      <input type="checkbox" class="select-checkbox h-4 w-4 rounded border-gray-300">
    </div>
    ${image}
    <div class="flex-1 min-w-0">
      <div class="text-xs font-medium text-gray-800">${escapeHtml(variation.attribute_summary || '')}</div>
      <div class="text-[10px] text-gray-400">${escapeHtml(variation.sku || '')}</div>
      <div class="mt-1 flex items-center gap-2 price-row"></div>
    </div>
    <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
      <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="1">+</button>
      <span class="stock-qty text-[10px] font-medium text-gray-700">${variation.stock_quantity ?? '-'}</span>
      <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="-1">-</button>
    </div>
  `;

  renderPriceRow(row, variation);
  wireProductElement(row, variation);
  return row;
}

function renderAddVariationRow(listEl, product, options) {
  const wrap = document.createElement('div');
  wrap.className = 'add-variation-wrap';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'add-variation-btn text-xs text-gray-500 underline';
  btn.textContent = t('add_variation');
  wrap.appendChild(btn);

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    wrap.innerHTML = '';
    wrap.appendChild(buildAddVariationForm(listEl, product, options, wrap));
  });

  return wrap;
}

function buildAddVariationForm(listEl, product, options, wrap) {
  const form = document.createElement('div');
  form.className = 'bg-white rounded-xl border border-gray-200 p-2 space-y-1.5';

  const selects = options.map((opt) => {
    const selectId = `variation-attr-${product.id}-${opt.name}`.replace(/\s+/g, '-');
    const optionsHtml = opt.options.map((v) => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
    return `
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(opt.name)}</label>
        <select id="${selectId}" data-attr-name="${escapeHtml(opt.name)}" class="variation-attr-select w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
          ${optionsHtml}
        </select>
      </div>`;
  }).join('');

  form.innerHTML = `
    ${selects}
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('regular_price'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('sale_price_optional'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-sale-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('sku'))}</label>
      <input type="text" class="new-variation-sku w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div class="new-variation-error hidden text-[10px] text-red-600"></div>
    <div class="flex gap-1.5">
      <button type="button" class="new-variation-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${escapeHtml(t('save'))}</button>
      <button type="button" class="new-variation-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${escapeHtml(t('cancel'))}</button>
    </div>
  `;

  const errorEl = form.querySelector('.new-variation-error');
  const showError = (msg) => { errorEl.textContent = msg; errorEl.classList.remove('hidden'); };

  form.querySelector('.new-variation-cancel').addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    wrap.innerHTML = '';
    wrap.appendChild(renderAddVariationRow(listEl, product, options));
  });

  form.querySelector('.new-variation-save').addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    errorEl.classList.add('hidden');

    const attributes = {};
    form.querySelectorAll('.variation-attr-select').forEach((sel) => {
      attributes[sel.dataset.attrName] = sel.value;
    });
    if (options.length === 0 || Object.values(attributes).some((v) => !v)) {
      showError(t('variation_attribute_required'));
      return;
    }

    const regularPrice = form.querySelector('.new-variation-price').value.trim();
    const salePrice = form.querySelector('.new-variation-sale-price').value.trim();
    const sku = form.querySelector('.new-variation-sku').value.trim();

    const saveBtn = form.querySelector('.new-variation-save');
    saveBtn.disabled = true;
    saveBtn.textContent = t('saving_variation');

    try {
      const payload = { product_id: product.id, attributes };
      if (regularPrice !== '') payload.regular_price = regularPrice;
      if (salePrice !== '') payload.sale_price = salePrice;
      if (sku !== '') payload.sku = sku;

      const data = await App.api('/api/product-variations.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const cached = variationsCache.get(product.id) || { items: [], options };
      cached.items = [...cached.items, data.item];
      variationsCache.set(product.id, cached);

      wrap.insertAdjacentElement('beforebegin', renderVariationRow(data.item));
      wrap.innerHTML = '';
      wrap.appendChild(renderAddVariationRow(listEl, product, options));

      if (data.item.log_id) {
        App.notify(data.item.message, { logId: data.item.log_id });
      }
    } catch (err) {
      showError(err.message);
      saveBtn.disabled = false;
      saveBtn.textContent = t('save');
    }
  });

  return form;
}

/**
 * Renders the price for a product card. Clicking the price turns it into an
 * editable field; on save, it's sent to the API and the card is updated
 * in place with a success notification (and undo, if available).
 */
function renderPriceRow(card, product) {
  const row = card.querySelector('.price-row');
  row.className = 'mt-1 flex items-center gap-2 price-row';
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
  row.className = 'price-row price-row-editing flex flex-col gap-1.5 w-full';
  row.innerHTML = `
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('regular_price'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any"
             class="price-input regular-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
             value="${escapeHtml(product.regular_price ?? '')}">
      <div class="regular-price-preview text-[10px] text-gray-400 mt-0.5"></div>
    </div>
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('sale_price_optional'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any"
             class="price-input sale-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
             value="${escapeHtml(product.on_sale ? (product.sale_price ?? '') : '')}">
      <div class="sale-price-preview text-[10px] text-gray-400 mt-0.5"></div>
    </div>
    <div class="price-edit-error hidden text-[10px] text-red-600"></div>
    <div class="flex gap-1.5">
      <button type="button" class="price-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${escapeHtml(t('save'))}</button>
      <button type="button" class="price-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${escapeHtml(t('cancel'))}</button>
    </div>
  `;

  const regularInput = row.querySelector('.regular-price-input');
  const saleInput = row.querySelector('.sale-price-input');
  const regularPreview = row.querySelector('.regular-price-preview');
  const salePreview = row.querySelector('.sale-price-preview');
  const errorEl = row.querySelector('.price-edit-error');

  const updatePreview = (input, previewEl) => {
    const value = parseFloat(input.value);
    previewEl.textContent = !isNaN(value) && input.value.trim() !== '' ? App.formatToman(value) : '';
  };
  updatePreview(regularInput, regularPreview);
  updatePreview(saleInput, salePreview);
  regularInput.addEventListener('input', () => updatePreview(regularInput, regularPreview));
  saleInput.addEventListener('input', () => updatePreview(saleInput, salePreview));

  const showError = (message) => {
    errorEl.textContent = message;
    errorEl.classList.remove('hidden');
  };
  const clearError = () => errorEl.classList.add('hidden');

  let resolved = false;
  const cancel = () => {
    if (resolved) return;
    resolved = true;
    renderPriceRow(card, product);
  };

  const save = async () => {
    if (resolved) return;
    clearError();

    const regularValue = regularInput.value.trim();
    const saleValue = saleInput.value.trim();
    const regularNum = parseFloat(regularValue);
    const saleNum = saleValue === '' ? null : parseFloat(saleValue);

    if (regularValue === '' || isNaN(regularNum) || regularNum <= 0) {
      showError(t('price_required'));
      regularInput.focus();
      return;
    }
    if (saleNum !== null && (isNaN(saleNum) || saleNum < 0 || saleNum >= regularNum)) {
      showError(t('sale_price_must_be_lower'));
      saleInput.focus();
      return;
    }

    const regularChanged = regularValue !== String(product.regular_price ?? '');
    const saleChanged = saleValue !== String(product.on_sale ? (product.sale_price ?? '') : '');

    if (!regularChanged && !saleChanged) {
      cancel();
      return;
    }

    const payload = {
      id: product.id,
      regular_price: regularValue,
      sale_price: saleValue === '' ? '' : saleValue,
      before: { regular_price: product.regular_price ?? '', sale_price: product.on_sale ? (product.sale_price ?? '') : '' },
    };

    resolved = true;
    regularInput.disabled = true;
    saleInput.disabled = true;
    const saveBtn = row.querySelector('.price-save');
    const saveBtnOriginalText = saveBtn.textContent;
    saveBtn.disabled = true;
    saveBtn.textContent = t('saving');
    row.querySelector('.price-cancel').disabled = true;

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
        App.notify(data.item.message, { logId: data.item.log_id, productId: product.id });
      }
    } catch (err) {
      resolved = false;
      App.toast(err.message, 'error');
      regularInput.disabled = false;
      saleInput.disabled = false;
      saveBtn.disabled = false;
      saveBtn.textContent = saveBtnOriginalText;
      row.querySelector('.price-cancel').disabled = false;
      showError(err.message);
    }
  };

  row.querySelector('.price-save').addEventListener('click', (e) => { e.stopPropagation(); save(); });
  row.querySelector('.price-cancel').addEventListener('click', (e) => { e.stopPropagation(); cancel(); });

  const onKeydown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      save();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      cancel();
    }
  };

  // Clicking away cancels (reverts) rather than silently saving - an
  // explicit Save click is required to actually commit a price change.
  const onFocusOut = (e) => {
    if (row.contains(e.relatedTarget)) return;
    cancel();
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
