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
};

document.addEventListener('DOMContentLoaded', () => {
  App.showPendingNotify();
  const desktopLogout = document.getElementById('logout-btn-desktop');
  if (desktopLogout) desktopLogout.addEventListener('click', () => App.logout());
});
