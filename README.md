# WooCommerce Product Manager (Mobile-First)

A lightweight, mobile-first admin web app for managing products in an existing
WooCommerce store. Built in plain PHP (no Composer/build step) so it can be
deployed to any shared PHP 8+ host by uploading files.

## Features

- **Login via SMS OTP** (Kavenegar) — no passwords. Accounts and all app
  settings are managed in-app: the first run takes you to a one-time setup
  page (`/install.php`) that creates the first superadmin account, after
  which everything (Kavenegar key, OTP, session, and additional superadmin
  phone numbers) is configured from *Admin → Settings*.
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
  - `superadmin`: manages WooCommerce sites, admin/shop-manager accounts, and
    all app settings (in *Admin → Sites*, *Admin → Users*, and
    *Admin → Settings*), plus everything an `admin` can do on any site.
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
  includes/      # PHP classes & helpers (WooCommerce client, auth, settings, etc.)
  data/          # SQLite database for users/sites/settings/OTP codes (created automatically)
  public/        # web root — point your domain/subdomain here
    install.php  # one-time first-run setup wizard (creates the first superadmin)
    api/         # backend JSON endpoints
    admin/       # superadmin-only pages (manage sites, users & settings)
    assets/      # CSS/JS
    *.php        # pages (login, sites, products, product-edit, batch, ...)
```

`includes/` and `data/` are outside `public/` and additionally protected with
`.htaccess` (`Require all denied`) in case your web root is ever pointed at
the project root by mistake.

There is **no config file to create or edit**. Everything (Kavenegar API key,
OTP settings, session settings, superadmin phone numbers, WooCommerce sites,
and users) is stored in the SQLite database (`data/app.sqlite`) and managed
from the app itself.

## Setup

### 1. Get WooCommerce REST API credentials (do this first)

In WordPress: *WooCommerce → Settings → Advanced → REST API → Add key*.
Give it **Read/Write** permissions and copy the Consumer key/secret — you'll
enter these in the app later, in *Admin → Sites*.

### 2. Set up Kavenegar OTP (optional at install time)

- Create an account at [kavenegar.com](https://kavenegar.com) and get your
  API key.
- Create a "Verify Lookup" template (e.g. named `verify`) with a single
  token, e.g.: `کد ورود شما: %token%`

You can enter these during the initial setup wizard, or skip and add them
later from *Admin → Settings*.

### 3. Upload to your host

Upload the entire `wc-product-manager` directory to your server, and point
your domain/subdomain's document root at `wc-product-manager/public`.

If you can't change the document root (e.g. you must use a subfolder of
`public_html`), upload the whole project as a subfolder, but make sure
`includes/` and `data/` are placed **outside** the publicly served folder —
or rely on the included `.htaccess` files as a second line of defense
(requires `AllowOverride All` / Apache).

### 4. Permissions

Ensure the web server user can create/write `data/app.sqlite` (the `data/`
folder needs to be writable, e.g. `chmod 775 data`).

### 5. Run the first-run setup wizard

Visit your domain. Since no superadmin exists yet, you'll be redirected to
`/install.php` automatically. Fill in:

- Your mobile number (format `09xxxxxxxxx`) and (optionally) your name — this
  becomes the first superadmin account.
- (Optional) Kavenegar API key and template name — can be left blank and set
  later.

Submitting the form creates your superadmin account and redirects to
`/login.php`. **This page is automatically disabled once a superadmin
account exists**, so it's safe to leave on the server.

### 6. Log in and finish configuration

- Log in with the phone number you used in step 5 to receive an OTP via SMS
  (requires the Kavenegar key to be set — either during install or in
  *Admin → Settings*).
- Go to *Admin → Settings* to review/update the Kavenegar key & template, OTP
  behavior (code length, expiry, rate limits), session lifetime, and the list
  of bootstrap superadmin phone numbers.
- Go to *Admin → Sites* and add your WooCommerce store(s) (name, store URL,
  consumer key/secret, SSL verification).
- Go to *Admin → Users* to create `admin` / `shop_manager` accounts (or
  additional `superadmin` accounts) and assign each one to one or more sites.
- Each user (including superadmin) picks/switches their active site from
  `/sites.php`, accessible from the bottom nav. Superadmins can access every
  site; other roles only see the sites assigned to them.

## Installing on cPanel

1. **Create the app directory.** In cPanel → *File Manager* (or via FTP/SSH),
   upload the project, e.g. to `~/wc-product-manager` (outside `public_html`
   if possible).

2. **Point a domain/subdomain at `public/`.**
   - cPanel → *Domains* (or *Subdomains*) → create/edit the domain and set its
     **Document Root** to `wc-product-manager/public`.
   - If your plan doesn't let you set a custom document root, upload the
     project as a subfolder of `public_html` (e.g.
     `public_html/wc-product-manager`) and create an **Addon Domain** or
     **Alias** whose document root is
     `public_html/wc-product-manager/public`.

3. **Check PHP version & extensions.**
   - cPanel → *MultiPHP Manager* → select PHP 8.0+ for the domain.
   - cPanel → *MultiPHP INI Editor* → confirm `curl` and `pdo_sqlite` (or
     `sqlite3`) extensions are enabled (they're on by default on most hosts).

4. **Make `data/` writable.**
   - File Manager → right-click `data/` → *Permissions* → set to `775`
     (or `777` if your host's PHP runs as a different user).

5. **Run the setup wizard.**
   - Visit your domain — you'll be redirected to `/install.php`.
   - Enter your mobile number (and Kavenegar key/template if you have them
     ready) and submit. This creates your superadmin account.

6. **Finish configuration from the app** (no file edits needed):
   - *Admin → Settings* — Kavenegar key/template, OTP & session settings,
     bootstrap superadmin phone numbers.
   - *Admin → Sites* — connect your WooCommerce store(s).
   - *Admin → Users* — add more team members and assign sites.

7. **(Recommended) Force HTTPS.** cPanel → *SSL/TLS Status* → enable
   *AutoSSL*, then enable *Force HTTPS Redirect* for the domain. Login
   cookies are marked `secure` automatically when the request is HTTPS.

## Adding more superadmins

There are two ways to grant the `superadmin` role to additional accounts:

- **Admin → Users** (recommended): as an existing superadmin, add a new user
  (or edit an existing one) and set their role to **Superadmin**. They can
  then log in with their phone number immediately — no further setup needed.
- **Admin → Settings**: add the phone number to the comma-separated
  "Bootstrap superadmin phone numbers" list. The *first time* that number
  logs in, an account is auto-created with the `superadmin` role. This is
  mainly useful for pre-authorizing someone who doesn't have an account yet.

If you ever lose access to all superadmin accounts, you can restore access by
opening `data/app.sqlite` with any SQLite client and updating a user's `role`
column to `superadmin` (or deleting all rows from the `users` table so
`/install.php` runs again).

## Security notes

- WooCommerce API credentials and the Kavenegar API key are only ever used
  server-side; the browser never sees them. Site credentials shown in the
  *Admin → Sites* page are masked (only the first/last few characters).
- All write operations (`/api/*.php` POST/PUT/DELETE) require a valid session
  and a matching CSRF token (`X-CSRF-Token` header).
- OTP requests are rate-limited per phone number (default: 3 requests per
  10 minutes), and codes expire after 2 minutes.
- Anyone can request an OTP, but logging in only creates an account if the
  phone number is listed in the "Bootstrap superadmin phone numbers" setting
  (bootstraps a superadmin) or already has an account created by a superadmin
  via *Admin → Users*.
- Managing sites, users, and settings (`/admin/sites.php`, `/admin/users.php`,
  `/admin/settings.php`, and the corresponding write APIs) is restricted to
  `superadmin` accounts. Superadmins cannot change their own role or delete
  their own account.
- `/install.php` only works while no superadmin account exists yet; once one
  is created, the page redirects to `/login.php`.
- Every product/stock/batch API call operates on the currently selected site
  and re-checks that the logged-in user is allowed to access it.
