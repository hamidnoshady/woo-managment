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
