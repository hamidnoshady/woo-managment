<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
$site = require_site_page($user);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Products · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <!-- Header -->
  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <?php render_site_switcher($site); ?>
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900">Products</h1>
      <button id="select-toggle" class="text-sm font-medium text-gray-600 active:text-gray-900">Select</button>
    </div>
    <div class="px-4 pb-3 flex gap-2">
      <div class="relative flex-1">
        <input id="search-input" type="search" placeholder="Search by name or SKU"
               class="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>
      <button id="filter-btn" class="relative rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium text-gray-700">
        Filters
        <span id="filter-badge" class="hidden absolute -top-1.5 -right-1.5 h-4 w-4 rounded-full bg-gray-900 text-white text-[10px] leading-4 text-center"></span>
      </button>
    </div>
  </header>

  <!-- Product list -->
  <main class="px-4 py-3">
    <div id="product-list" class="space-y-3"></div>
    <div id="load-more-wrap" class="hidden py-4 text-center">
      <button id="load-more" class="rounded-xl border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700">Load more</button>
    </div>
    <div id="empty-state" class="hidden text-center py-16 text-gray-400">
      <p class="text-sm">No products found.</p>
    </div>
  </main>

  <!-- Add button (floating) -->
  <a href="/product-edit.php" id="add-fab"
     class="fixed right-4 bottom-20 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-gray-900 text-white text-2xl shadow-lg active:scale-95 transition">
    +
  </a>

  <!-- Selection action bar -->
  <div id="selection-bar" class="hidden fixed bottom-16 left-0 right-0 z-30 bg-gray-900 text-white px-4 py-3 flex items-center justify-between">
    <span id="selection-count" class="text-sm">0 selected</span>
    <div class="flex gap-2">
      <button id="selection-cancel" class="rounded-lg border border-white/30 px-3 py-2 text-sm">Cancel</button>
      <button id="selection-batch" class="rounded-lg bg-white text-gray-900 px-3 py-2 text-sm font-medium">Batch actions</button>
    </div>
  </div>

  <!-- Bottom navigation -->
  <?php render_bottom_nav('products', $user); ?>

  <!-- Filter sheet -->
  <div id="filter-sheet" class="hidden fixed inset-0 z-40">
    <div id="filter-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-gray-900">Filters</h2>
        <button id="filter-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
          <select id="filter-category" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="">All categories</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Stock status</label>
          <select id="filter-stock" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="">All</option>
            <option value="instock">In stock</option>
            <option value="outofstock">Out of stock</option>
            <option value="onbackorder">On backorder</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Price range</label>
          <div class="flex gap-2">
            <input id="filter-min-price" type="number" min="0" placeholder="Min" class="w-1/2 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <input id="filter-max-price" type="number" min="0" placeholder="Max" class="w-1/2 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
          </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input id="filter-on-sale" type="checkbox" class="h-4 w-4 rounded border-gray-300">
          On sale only
        </label>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Sort by</label>
          <select id="filter-sort" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="date-desc">Newest first</option>
            <option value="title-asc">Name (A-Z)</option>
            <option value="title-desc">Name (Z-A)</option>
            <option value="price-asc">Price (low to high)</option>
            <option value="price-desc">Price (high to low)</option>
          </select>
        </div>
      </div>

      <div class="mt-6 flex gap-2">
        <button id="filter-reset" class="flex-1 rounded-xl border border-gray-300 py-3 text-sm font-medium text-gray-700">Reset</button>
        <button id="filter-apply" class="flex-1 rounded-xl bg-gray-900 py-3 text-sm font-medium text-white">Apply</button>
      </div>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/products.js"></script>
</body>
</html>
