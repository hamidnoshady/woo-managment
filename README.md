# WooCommerce Product Manager (Mobile-First)

A lightweight, mobile-first admin web app for managing products in an existing
WooCommerce store. Built in plain PHP (no Composer/build step) so it can be
deployed to any shared PHP 8+ host by uploading files.

## Features

- **Login via SMS OTP** (Kavenegar) — no passwords. Accounts are created and
  managed in-app (see *Multi-site & user management* below); the first time a
  phone number listed under `superadmins` in `config.php` logs in, a
  superadmin account is created automatically.
- **Multi-site**: connect any number of WooCommerce stores, each with its own
  REST API credentials, and switch between the ones you're assigned to.
- **Product list**: mobile cards with image, price, stock, search, and filters
  (category, stock status, price range, on-sale only), infinite scroll.
- **Quick stock +/-** buttons directly on each product card.
- **Add / edit / delete products**: name, SKU, prices, stock, categories,
  images (by URL), description, status.
- **Batch mode**: multi-select products and apply bulk actions:
  - Increase or decrease price by a percentage (regular and/or sale price),
    with rounding modes: none, nearest whole number, round up, round down,
    round to a step (e.g. nearest 1000), or round to an ending decimal
    (e.g. `.99`).
  - Set or adjust stock quantity for many products at once.
  - Preview all changes before applying.
- **Roles**:
  - `superadmin`: manages WooCommerce sites and admin/shop-manager accounts
    (in *Admin → Sites* and *Admin → Users*), plus everything an `admin` can do
    on any site.
  - `admin`: full product access on their assigned site(s), including delete
    and bulk price changes.
  - `shop_manager`: edit products and stock on their assigned site(s), no
    delete / bulk pricing.

## Requirements

- PHP 8.0+ with `curl` and `pdo_sqlite` extensions enabled (both are included
  by default on virtually all shared hosts).
- An existing WordPress + WooCommerce store with the REST API enabled.
- A [Kavenegar](https://kavenegar.com) account with a Verify Lookup template
  configured for OTP codes.

## Directory layout

```
wc-product-manager/
  config/        # config.php (your credentials, NOT committed)
  includes/      # PHP classes & helpers (WooCommerce client, auth, etc.)
  data/          # SQLite database for users/sites/OTP codes (created automatically)
  public/        # web root — point your domain/subdomain here
    api/         # backend JSON endpoints
    admin/       # superadmin-only pages (manage sites & users)
    assets/      # CSS/JS
    *.php        # pages (login, sites, products, product-edit, batch, ...)
```

`config/`, `includes/`, and `data/` are outside `public/` and additionally
protected with `.htaccess` (`Require all denied`) in case your web root is
ever pointed at the project root by mistake.

## Setup

1. **Get WooCommerce REST API credentials**
   In WordPress: *WooCommerce → Settings → Advanced → REST API → Add key*.
   Give it **Read/Write** permissions. Copy the Consumer key/secret. You'll
   enter these in the app after logging in (step 6), not in `config.php`.

2. **Set up Kavenegar OTP**
   - Create an account at kavenegar.com and get your API key.
   - Create a "Verify Lookup" template (e.g. named `verify`) with a single
     token, e.g.: `کد ورود شما: %token%`

3. **Configure the app**
   ```bash
   cp config/config.sample.php config/config.php
   ```
   Edit `config/config.php`:
   - `kavenegar.api_key` and `kavenegar.template`
   - `superadmins`: list the mobile number(s) (format `09xxxxxxxxx`) that
     should become superadmins on first login. Everyone else (admins and
     shop managers) is created later from the *Admin → Users* page.

4. **Upload**
   Upload the entire `wc-product-manager` directory to your server, and point
   your domain/subdomain's document root at `wc-product-manager/public`.

   If you can't change the document root (e.g. you must use a subfolder of
   `public_html`), upload the whole project as a subfolder, but make sure
   `config/`, `includes/`, and `data/` are placed **outside** the publicly
   served folder — or rely on the included `.htaccess` files as a second
   line of defense (requires `AllowOverride All` / Apache).

5. **Permissions**
   Ensure the web server user can create/write `data/app.sqlite`
   (the `data/` folder needs to be writable).

6. **Open the app**
   Visit your domain — you'll be redirected to `/login.php`. Log in with a
   phone number listed under `superadmins` to receive an OTP via SMS; this
   bootstraps your superadmin account.

   Then, as superadmin:
   - Go to *Admin → Sites* and add your WooCommerce store(s) (name, store URL,
     consumer key/secret, SSL verification).
   - Go to *Admin → Users* to create `admin` / `shop_manager` accounts and
     assign each one to one or more sites.
   - Each user (including superadmin) picks/switches their active site from
     `/sites.php`, accessible from the bottom nav. Superadmins can access
     every site; other roles only see the sites assigned to them.

## Security notes

- WooCommerce API credentials and the Kavenegar API key are only ever used
  server-side; the browser never sees them. Site credentials shown in the
  *Admin → Sites* page are masked (only the first/last few characters).
- All write operations (`/api/*.php` POST/PUT/DELETE) require a valid session
  and a matching CSRF token (`X-CSRF-Token` header).
- OTP requests are rate-limited per phone number (default: 3 requests per
  10 minutes), and codes expire after 2 minutes.
- Anyone can request an OTP, but logging in only creates an account if the
  phone number is listed under `superadmins` (bootstraps a superadmin) or
  already has an account created by a superadmin via *Admin → Users*.
- Managing sites and users (`/admin/sites.php`, `/admin/users.php`, and the
  corresponding write APIs) is restricted to `superadmin` accounts.
  Superadmins cannot change their own role or delete their own account.
- Every product/stock/batch API call operates on the currently selected site
  and re-checks that the logged-in user is allowed to access it.
