/**
 * Shared helpers for the WooCommerce Product Manager (mobile-first admin app).
 */

const App = {
  /**
   * Returns the CSRF token stored after login.
   */
  csrfToken() {
    return sessionStorage.getItem('csrf_token') || '';
  },

  setCsrfToken(token) {
    sessionStorage.setItem('csrf_token', token || '');
  },

  /**
   * Wraps fetch() with JSON handling, CSRF header, and auth redirect on 401.
   */
  async api(url, options = {}) {
    const opts = Object.assign({}, options);
    opts.headers = Object.assign({}, options.headers);

    if (opts.body && !(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = 'application/json';
    }

    // ponytail: always attach the CSRF token, even on GET - some GET
    // endpoints (e.g. batch-jobs.php?action=poll) mutate state and need it.
    opts.headers['X-CSRF-Token'] = this.csrfToken();

    opts.credentials = 'same-origin';

    const response = await fetch(url, opts);

    if (response.status === 401) {
      window.location.href = '/login.php';
      return new Promise(() => {});
    }

    if (response.status === 409) {
      window.location.href = '/sites.php';
      return new Promise(() => {});
    }

    let data = null;
    try {
      data = await response.json();
    } catch (e) {
      data = null;
    }

    if (!response.ok) {
      const message = (data && data.error) ? data.error : `Request failed (${response.status})`;
      throw new Error(message);
    }

    return data;
  },

  /**
   * Shows a temporary toast notification. type: 'success' | 'error' | 'info'.
   */
  toast(message, type = 'info') {
    let container = document.querySelector('.toast');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast';
      document.body.appendChild(container);
    }

    const item = document.createElement('div');
    item.className = `toast-item ${type}`;
    item.textContent = message;
    container.appendChild(item);

    setTimeout(() => {
      item.remove();
    }, 3000);
  },

  /**
   * Shows an in-app notification for a change that was just made. If
   * `logId` is provided, an "Undo" button is shown with a countdown for
   * the duration of the undo window (10s by default). `productId`, if
   * given, is passed through to `onUndo` so the caller can refresh just
   * that one item instead of reloading everything.
   */
  notify(message, { logId = null, productId = null, duration = 10000 } = {}) {
    let container = document.querySelector('.notif');
    if (!container) {
      container = document.createElement('div');
      container.className = 'notif';
      document.body.appendChild(container);
    }

    const item = document.createElement('div');
    item.className = 'notif-item';

    const text = document.createElement('div');
    text.className = 'notif-text';
    text.textContent = message;
    item.appendChild(text);

    if (logId !== null) {
      const undoBtn = document.createElement('button');
      undoBtn.className = 'notif-undo';
      undoBtn.textContent = `${t('undo')} (${Math.ceil(duration / 1000)})`;
      item.appendChild(undoBtn);

      const bar = document.createElement('div');
      bar.className = 'notif-bar';
      bar.style.animationDuration = `${duration}ms`;
      item.appendChild(bar);

      let remaining = Math.ceil(duration / 1000);
      const tick = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(tick);
          item.remove();
          return;
        }
        undoBtn.textContent = `${t('undo')} (${remaining})`;
      }, 1000);

      const removeTimer = setTimeout(() => item.remove(), duration);

      undoBtn.addEventListener('click', async () => {
        clearInterval(tick);
        clearTimeout(removeTimer);
        undoBtn.disabled = true;
        try {
          await this.api('/api/logs.php?action=undo', {
            method: 'POST',
            body: JSON.stringify({ id: logId }),
          });
          this.toast(t('undo_applied'), 'success');
          if (typeof this.onUndo === 'function') {
            this.onUndo(logId, productId);
          }
        } catch (err) {
          this.toast(err.message || t('undo_failed'), 'error');
        } finally {
          item.remove();
        }
      });
    } else {
      setTimeout(() => item.remove(), duration);
    }

    container.appendChild(item);
  },

  /**
   * Stores a notification to be shown after the next page navigation
   * (e.g. before redirecting to a different page following a save).
   */
  notifyOnNextPage(message, options = {}) {
    sessionStorage.setItem('pending_notif', JSON.stringify({ message, options }));
  },

  /**
   * Shows a notification stored via notifyOnNextPage(), if any.
   */
  showPendingNotify() {
    const raw = sessionStorage.getItem('pending_notif');
    if (!raw) return;
    sessionStorage.removeItem('pending_notif');
    try {
      const { message, options } = JSON.parse(raw);
      this.notify(message, options);
    } catch (e) {
      // ignore malformed entries
    }
  },

  /**
   * Formats a numeric price string for display, using Persian digits and
   * separators when the UI language is Farsi (no currency unit).
   */
  formatPrice(value) {
    const num = parseFloat(value);
    if (isNaN(num)) return '0';
    const locale = (document.documentElement.lang === 'fa') ? 'fa-IR' : 'en-US';
    return num.toLocaleString(locale, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  },

  /**
   * Formats a numeric amount as Iranian Toman, including the currency unit
   * (تومان / Toman) and Persian digits/separators when the UI language is Farsi.
   */
  formatToman(value) {
    const formatted = this.formatPrice(value);
    const unit = (typeof t === 'function') ? t('currency_unit') : 'Toman';
    return `${formatted} ${unit}`;
  },

  /**
   * Logs the user out and redirects to the login page.
   */
  async logout() {
    try {
      await this.api('/api/auth.php?action=logout', { method: 'POST' });
    } catch (e) {
      // ignore
    }
    sessionStorage.clear();
    window.location.href = '/login.php';
  },

  /**
   * Renders a collapsible category checkbox tree into `container` from the
   * flat (parent-before-children, depth-tagged) list returned by
   * /api/categories.php. Each checkbox keeps the `category-checkbox` class
   * and `value` (category id) the calling page already expects, so existing
   * selection-reading code doesn't need to change - only the nesting does.
   * Parents with children start collapsed; use expandCheckedCategoryAncestors()
   * after marking checkboxes checked to reveal the path to a selection.
   */
  renderCategoryCheckboxTree(container, categories) {
    container.innerHTML = '';

    const byParent = {};
    categories.forEach((cat) => {
      const parentId = cat.parent || 0;
      (byParent[parentId] = byParent[parentId] || []).push(cat);
    });

    const renderLevel = (parentId, depth) => {
      const list = byParent[parentId];
      if (!list) return null;

      const levelWrap = document.createElement('div');
      levelWrap.className = depth === 0
        ? 'space-y-1'
        : 'cat-children hidden space-y-1 ms-2 ps-3 border-s border-gray-100 mt-1';

      list.forEach((cat) => {
        const hasChildren = !!byParent[cat.id];

        const row = document.createElement('div');
        row.className = 'flex items-center gap-1.5';

        if (hasChildren) {
          const toggle = document.createElement('button');
          toggle.type = 'button';
          toggle.className = 'cat-toggle h-5 w-5 flex items-center justify-center text-gray-400 flex-shrink-0 text-xs';
          toggle.textContent = '▸';
          toggle.setAttribute('aria-label', 'Expand');
          row.appendChild(toggle);
        } else {
          const spacer = document.createElement('span');
          spacer.className = 'w-5 flex-shrink-0';
          row.appendChild(spacer);
        }

        const label = document.createElement('label');
        label.className = 'flex items-center gap-2 text-sm text-gray-700 flex-1';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = cat.id;
        checkbox.className = 'category-checkbox h-4 w-4 rounded border-gray-300';
        label.appendChild(checkbox);

        const nameSpan = document.createElement('span');
        nameSpan.className = 'cat-name';
        nameSpan.textContent = cat.name;
        label.appendChild(nameSpan);

        if (typeof cat.count === 'number') {
          const countSpan = document.createElement('span');
          countSpan.className = 'text-xs text-gray-400';
          countSpan.textContent = `(${cat.count})`;
          label.appendChild(countSpan);
        }

        row.appendChild(label);
        levelWrap.appendChild(row);

        if (hasChildren) {
          const childWrap = renderLevel(cat.id, depth + 1);
          levelWrap.appendChild(childWrap);

          row.querySelector('.cat-toggle').addEventListener('click', () => {
            const willShow = childWrap.classList.contains('hidden');
            childWrap.classList.toggle('hidden', !willShow);
            row.querySelector('.cat-toggle').textContent = willShow ? '▾' : '▸';
          });
        }
      });

      return levelWrap;
    };

    const root = renderLevel(0, 0);
    if (root) container.appendChild(root);
  },

  /**
   * Renders a taxonomy list into `container` as a set of collapsible
   * (native <details>) sections, one per taxonomy, each collapsed by
   * default so a site with many custom taxonomies doesn't turn the page
   * into one long scroll. Each <details> keeps a `data-rest-base` so
   * existing selection-reading code (querySelectorAll('[data-rest-base]'))
   * keeps working unchanged.
   */
  renderTaxonomySections(container, taxonomies, reason) {
    if (!container) return;
    container.innerHTML = '';

    if (!taxonomies.length) {
      if (reason) {
        const notice = document.createElement('p');
        notice.className = 'text-xs text-gray-400 bg-white rounded-2xl border border-gray-100 p-4';
        notice.textContent = reason === 'no_credentials' ? t('wp_credentials_missing') : t('no_custom_taxonomies');
        container.appendChild(notice);
        container.classList.remove('hidden');
      }
      return;
    }

    taxonomies.forEach((tax) => {
      const details = document.createElement('details');
      details.className = 'bg-white rounded-2xl border border-gray-100 p-4';
      details.dataset.restBase = tax.rest_base;

      const summary = document.createElement('summary');
      summary.className = 'text-sm font-semibold text-gray-900 cursor-pointer';
      summary.textContent = tax.name;
      details.appendChild(summary);

      const list = document.createElement('div');
      list.className = 'space-y-2 max-h-64 overflow-y-auto custom-taxonomy-terms mt-3';
      (tax.terms || []).forEach((term) => list.appendChild(this._taxonomyTermLabel(term)));
      details.appendChild(list);

      container.appendChild(details);
    });

    container.classList.remove('hidden');
  },

  _taxonomyTermLabel(term) {
    const label = document.createElement('label');
    label.className = 'flex items-center gap-2 text-sm text-gray-700';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.value = term.id;
    checkbox.className = 'custom-term-checkbox h-4 w-4 rounded border-gray-300';
    label.appendChild(checkbox);
    const span = document.createElement('span');
    span.textContent = term.name;
    label.appendChild(span);
    return label;
  },

  /**
   * Builds a thumbnail-grid image manager (featured image + gallery) shared
   * by the add-product wizard and the edit page. Each thumbnail shows the
   * actual uploaded image with remove / replace / set-as-featured controls,
   * instead of a bare URL text field - so an upload is immediately visible
   * and every image can be removed or swapped without retyping a URL.
   *
   * @param {{grid: Element, fileInput: Element, uploadStatus?: Element, addUrlBtn?: Element}} opts
   * @returns {{getImages: () => Array<{id:number, src:string}>, setImages: (list: Array) => void}}
   */
  createImageGallery({ grid, fileInput, uploadStatus, addUrlBtn }) {
    let images = [];
    let replaceIndex = -1;

    const iconBtn = (className, label, symbol) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `absolute h-5 w-5 rounded-full bg-black/60 text-white text-xs leading-none flex items-center justify-center ${className}`;
      btn.setAttribute('aria-label', label);
      btn.title = label;
      btn.textContent = symbol;
      return btn;
    };

    function render() {
      grid.innerHTML = '';

      images.forEach((img, idx) => {
        const card = document.createElement('div');
        card.className = 'relative w-24 h-24 flex-shrink-0 rounded-xl overflow-hidden border border-gray-200 bg-gray-50';

        const thumb = document.createElement('img');
        thumb.src = img.src;
        thumb.alt = '';
        thumb.loading = 'lazy';
        thumb.className = 'w-full h-full object-cover';
        card.appendChild(thumb);

        if (idx === 0) {
          const badge = document.createElement('span');
          badge.className = 'absolute bottom-0 inset-x-0 bg-gray-900/70 text-white text-[10px] text-center py-0.5';
          badge.textContent = t('featured_image');
          card.appendChild(badge);
        } else {
          const starBtn = iconBtn('top-1 left-1', t('set_as_featured'), '★');
          starBtn.addEventListener('click', () => {
            const [moved] = images.splice(idx, 1);
            images.unshift(moved);
            render();
          });
          card.appendChild(starBtn);
        }

        const removeBtn = iconBtn('top-1 right-1', t('remove_image'), '×');
        removeBtn.addEventListener('click', () => { images.splice(idx, 1); render(); });
        card.appendChild(removeBtn);

        const replaceBtn = iconBtn('bottom-1 right-1', t('replace_image'), '⟳');
        replaceBtn.addEventListener('click', () => { replaceIndex = idx; fileInput.click(); });
        card.appendChild(replaceBtn);

        grid.appendChild(card);
      });

      const addTile = document.createElement('label');
      addTile.className = 'w-24 h-24 flex-shrink-0 rounded-xl border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-2xl cursor-pointer hover:bg-gray-50';
      addTile.textContent = '+';
      if (fileInput.id) addTile.htmlFor = fileInput.id;
      grid.appendChild(addTile);
    }

    fileInput.addEventListener('change', async () => {
      const files = Array.from(fileInput.files);
      if (!files.length) {
        replaceIndex = -1;
        return;
      }

      fileInput.disabled = true;
      if (uploadStatus) {
        uploadStatus.textContent = t('uploading');
        uploadStatus.classList.remove('hidden');
      }
      try {
        for (const file of files) {
          const formData = new FormData();
          formData.append('image', file);
          const data = await this.api('/api/media.php', { method: 'POST', body: formData });
          const entry = { id: data.item.id || 0, src: data.item.src || '' };
          if (replaceIndex >= 0 && replaceIndex < images.length) {
            images[replaceIndex] = entry;
          } else {
            images.push(entry);
          }
          replaceIndex = -1;
        }
        render();
      } catch (err) {
        this.toast(err.message, 'error');
      } finally {
        replaceIndex = -1;
        fileInput.disabled = false;
        fileInput.value = '';
        if (uploadStatus) uploadStatus.classList.add('hidden');
      }
    });

    if (addUrlBtn) {
      addUrlBtn.addEventListener('click', () => {
        const url = prompt(t('image_url_prompt'));
        if (url && url.trim()) {
          images.push({ id: 0, src: url.trim() });
          render();
        }
      });
    }

    render();

    return {
      getImages: () => images.filter((img) => img.src.trim() !== ''),
      setImages: (list) => {
        images = (list || []).map((img) => ({ id: img.id || 0, src: img.src || '' }));
        render();
      },
    };
  },

  /**
   * Expands every collapsed ancestor of any currently-checked checkbox
   * inside `container` (call after setting .checked on pre-selected
   * categories, e.g. when loading an existing product for edit).
   */
  expandCheckedCategoryAncestors(container) {
    container.querySelectorAll('.category-checkbox:checked').forEach((checkbox) => {
      let node = checkbox.closest('.cat-children');
      while (node) {
        node.classList.remove('hidden');
        const toggle = node.previousElementSibling && node.previousElementSibling.querySelector
          ? node.previousElementSibling.querySelector('.cat-toggle')
          : null;
        if (toggle) toggle.textContent = '▾';
        node = node.parentElement ? node.parentElement.closest('.cat-children') : null;
      }
    });
  },

  escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  },

  stockStatusBadge(status) {
    const map = {
      instock: [t('in_stock'), 'bg-green-100 text-green-700'],
      outofstock: [t('out_of_stock'), 'bg-red-100 text-red-700'],
      onbackorder: [t('backorder'), 'bg-yellow-100 text-yellow-700'],
    };
    const [label, cls] = map[status] || [t('unknown'), 'bg-gray-100 text-gray-600'];
    return `<span class="stock-badge-wrap"><span class="text-[10px] font-medium px-1.5 py-0.5 rounded ${cls}">${label}</span></span>`;
  },

  /**
   * Renders the price for a product (or variation) card/row. Clicking the
   * price turns it into an editable field; on save, it's sent to the API
   * and the card is updated in place with a success notification.
   * `isSelectionMode` lets a caller (e.g. the product list's bulk-select
   * mode) suppress the click-to-edit while selection is active; callers
   * without a selection concept (e.g. the edit page) can omit it.
   */
  renderPriceRow(card, product, isSelectionMode = () => false) {
    const row = card.querySelector('.price-row');
    row.className = 'mt-1 flex items-center gap-2 price-row';
    const stockBadge = App.stockStatusBadge(product.stock_status);

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
      if (isSelectionMode()) return;
      App.showPriceEditor(card, row, product, isSelectionMode);
    });
  },

  showPriceEditor(card, row, product, isSelectionMode = () => false) {
    row.className = 'price-row price-row-editing flex flex-col gap-1.5 w-full';
    row.innerHTML = `
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('regular_price'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any"
               class="price-input regular-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
               value="${App.escapeHtml(product.regular_price ?? '')}">
        <div class="regular-price-preview text-[10px] text-gray-400 mt-0.5"></div>
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sale_price_optional'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any"
               class="price-input sale-price-input w-full rounded-lg border border-gray-300 px-2 py-1 text-xs focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"
               value="${App.escapeHtml(product.on_sale ? (product.sale_price ?? '') : '')}">
        <div class="sale-price-preview text-[10px] text-gray-400 mt-0.5"></div>
      </div>
      <div class="price-edit-error hidden text-[10px] text-red-600"></div>
      <div class="flex gap-1.5">
        <button type="button" class="price-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${App.escapeHtml(t('save'))}</button>
        <button type="button" class="price-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${App.escapeHtml(t('cancel'))}</button>
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
      App.renderPriceRow(card, product, isSelectionMode);
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

        App.renderPriceRow(card, product, isSelectionMode);

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

    const onFocusOut = (e) => {
      if (row.contains(e.relatedTarget)) return;
      cancel();
    };

    row.addEventListener('click', (e) => e.stopPropagation());
    row.addEventListener('keydown', onKeydown);
    row.addEventListener('focusout', onFocusOut);

    regularInput.focus();
    regularInput.select();
  },

  /**
   * Wires up the interactive bits shared by a product/variation card or
   * row: quick stock +/- and (if the markup includes a
   * `.select-checkbox`/`.checkbox-wrap`) the bulk-selection checkbox.
   * `isSelectionMode`/`onSelectChange` let a caller with a bulk-select
   * concept (the product list) participate; callers without one (the edit
   * page's variations section) can omit both and the checkbox, if present
   * in the markup, simply stays inert.
   */
  wireProductElement(el, product, { isSelectionMode = () => false, onSelectChange } = {}) {
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
            badgeWrap.outerHTML = App.stockStatusBadge(result.stock_status);
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

    const checkbox = el.querySelector('.select-checkbox');
    if (checkbox) {
      checkbox.addEventListener('change', () => {
        if (onSelectChange) onSelectChange(product.id, checkbox.checked);
      });
    }

    el.querySelectorAll('.card-link').forEach((link) => {
      link.addEventListener('click', (e) => {
        if (isSelectionMode()) {
          e.preventDefault();
          if (checkbox) {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change'));
          }
        }
      });
    });

    if (isSelectionMode() && checkbox) {
      const wrap = el.querySelector('.checkbox-wrap');
      if (wrap) wrap.classList.remove('hidden');
    }
  },
};

/**
 * Variation list/add UI for a variable product's card, row, or edit-page
 * section. `container` must contain a `.variations-toggle` button and a
 * `.variations-list` element (both already part of the markup wherever
 * this is called). Kept in a closure so its helper functions don't leak
 * into global scope - only App.renderVariationsSection is public.
 */
(function () {
  const variationsCache = new Map();

  function renderVariationsList(listEl, product, data, isSelectionMode, onSelectChange) {
    listEl.innerHTML = '';
    (data.items || []).forEach((variation) => {
      listEl.appendChild(renderVariationRow(variation, isSelectionMode, onSelectChange));
    });
    listEl.appendChild(renderAddVariationRow(listEl, product, data.options || [], isSelectionMode, onSelectChange));
  }

  /**
   * Renders one variation as a compact row reusing the same price-click-edit
   * and stock +/- markup/handlers as a top-level product card - both
   * App.wireProductElement() and App.renderPriceRow() only ever look at
   * `.id`, `.regular_price`, `.stock_quantity`, etc. on the object they're
   * given, so a variation object (same field names) works unmodified.
   */
  function renderVariationRow(variation, isSelectionMode, onSelectChange) {
    const row = document.createElement('div');
    row.className = 'variation-row bg-gray-50 rounded-xl border border-gray-100 p-2 flex gap-2 relative';
    row.dataset.id = variation.id;

    const image = variation.image
      ? `<img src="${App.escapeHtml(variation.image)}" alt="" class="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100">`
      : `<div class="h-10 w-10 rounded-lg bg-gray-100 flex-shrink-0"></div>`;

    row.innerHTML = `
      <div class="checkbox-wrap hidden flex items-center pr-1">
        <input type="checkbox" class="select-checkbox h-4 w-4 rounded border-gray-300">
      </div>
      ${image}
      <div class="flex-1 min-w-0">
        <div class="text-xs font-medium text-gray-800">${App.escapeHtml(variation.attribute_summary || '')}</div>
        <div class="text-[10px] text-gray-400">${App.escapeHtml(variation.sku || '')}</div>
        <div class="mt-1 flex items-center gap-2 price-row"></div>
      </div>
      <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
        <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="1">+</button>
        <span class="stock-qty text-[10px] font-medium text-gray-700">${variation.stock_quantity ?? '-'}</span>
        <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="-1">-</button>
      </div>
    `;

    App.renderPriceRow(row, variation, isSelectionMode);
    App.wireProductElement(row, variation, { isSelectionMode, onSelectChange });
    return row;
  }

  function renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange) {
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
      wrap.appendChild(buildAddVariationForm(listEl, product, options, wrap, isSelectionMode, onSelectChange));
    });

    return wrap;
  }

  function buildAddVariationForm(listEl, product, options, wrap, isSelectionMode, onSelectChange) {
    const form = document.createElement('div');
    form.className = 'bg-white rounded-xl border border-gray-200 p-2 space-y-1.5';

    const selects = options.map((opt) => {
      const selectId = `variation-attr-${product.id}-${opt.name}`.replace(/\s+/g, '-');
      const optionsHtml = opt.options.map((v) => `<option value="${App.escapeHtml(v)}">${App.escapeHtml(v)}</option>`).join('');
      return `
        <div>
          <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(opt.name)}</label>
          <select id="${selectId}" data-attr-name="${App.escapeHtml(opt.name)}" class="variation-attr-select w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
            ${optionsHtml}
          </select>
        </div>`;
    }).join('');

    form.innerHTML = `
      ${selects}
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('regular_price'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sale_price_optional'))}</label>
        <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-sale-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${App.escapeHtml(t('sku'))}</label>
        <input type="text" class="new-variation-sku w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
      </div>
      <div class="new-variation-error hidden text-[10px] text-red-600"></div>
      <div class="flex gap-1.5">
        <button type="button" class="new-variation-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${App.escapeHtml(t('save'))}</button>
        <button type="button" class="new-variation-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${App.escapeHtml(t('cancel'))}</button>
      </div>
    `;

    const errorEl = form.querySelector('.new-variation-error');
    const showError = (msg) => { errorEl.textContent = msg; errorEl.classList.remove('hidden'); };

    form.querySelector('.new-variation-cancel').addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      wrap.innerHTML = '';
      wrap.appendChild(renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange));
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

        wrap.insertAdjacentElement('beforebegin', renderVariationRow(data.item, isSelectionMode, onSelectChange));
        wrap.innerHTML = '';
        wrap.appendChild(renderAddVariationRow(listEl, product, options, isSelectionMode, onSelectChange));

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

  App.renderVariationsSection = function (container, product, { isSelectionMode = () => false, onSelectChange } = {}) {
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
        renderVariationsList(listEl, product, variationsCache.get(product.id), isSelectionMode, onSelectChange);
        return;
      }

      listEl.innerHTML = `<div class="text-xs text-gray-400">${App.escapeHtml(t('loading'))}</div>`;
      try {
        const data = await App.api(`/api/product-variations.php?product_id=${product.id}`);
        variationsCache.set(product.id, data);
        renderVariationsList(listEl, product, data, isSelectionMode, onSelectChange);
      } catch (err) {
        listEl.innerHTML = `
          <div class="text-xs text-red-600">${App.escapeHtml(t('variations_load_error'))}
            <button type="button" class="variations-retry underline ml-1">${App.escapeHtml(t('retry'))}</button>
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
  };
})();

document.addEventListener('DOMContentLoaded', () => {
  App.showPendingNotify();
  const desktopLogout = document.getElementById('logout-btn-desktop');
  if (desktopLogout) desktopLogout.addEventListener('click', () => App.logout());
});
