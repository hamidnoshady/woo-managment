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

    const method = (opts.method || 'GET').toUpperCase();
    if (method !== 'GET') {
      opts.headers['X-CSRF-Token'] = this.csrfToken();
    }

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
};

document.addEventListener('DOMContentLoaded', () => {
  App.showPendingNotify();
  const desktopLogout = document.getElementById('logout-btn-desktop');
  if (desktopLogout) desktopLogout.addEventListener('click', () => App.logout());
});
