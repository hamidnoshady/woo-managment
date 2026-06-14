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
