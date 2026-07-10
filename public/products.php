<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
$site = require_site_page($user);
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('products_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('products', $user, $site); ?>

  <!-- Header -->
  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <?php render_site_switcher($site); ?>
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('products_heading')); ?></h1>
      <div class="flex items-center gap-2">
        <div id="column-choice" class="hidden lg:flex items-center rounded-lg border border-gray-300 overflow-hidden">
          <button type="button" data-cols="1" class="col-choice-btn px-2 py-1 text-xs font-medium text-gray-600">1</button>
          <button type="button" data-cols="2" class="col-choice-btn px-2 py-1 text-xs font-medium text-gray-600 border-s border-gray-300">2</button>
          <button type="button" data-cols="3" class="col-choice-btn px-2 py-1 text-xs font-medium text-gray-600 border-s border-gray-300">3</button>
        </div>
        <div class="flex items-center rounded-lg border border-gray-300 overflow-hidden">
          <button type="button" id="view-grid-btn" title="<?php echo htmlspecialchars(t('grid_view')); ?>" class="view-choice-btn px-2 py-1.5 text-xs font-medium text-gray-600">⊞</button>
          <button type="button" id="view-table-btn" title="<?php echo htmlspecialchars(t('table_view')); ?>" class="view-choice-btn px-2 py-1.5 text-xs font-medium text-gray-600 border-s border-gray-300">☰</button>
        </div>
      </div>
    </div>
    <div class="px-4 pb-3 flex gap-2">
      <div class="relative flex-1">
        <input id="search-input" type="search" placeholder="<?php echo htmlspecialchars(t('search_placeholder')); ?>"
               class="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>
      <button id="filter-btn" class="relative rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium text-gray-700">
        <?php echo htmlspecialchars(t('filters')); ?>
        <span id="filter-badge" class="hidden absolute -top-1.5 -right-1.5 h-4 w-4 rounded-full bg-gray-900 text-white text-[10px] leading-4 text-center"></span>
      </button>
    </div>
  </header>

  <!-- Content: product grid + (on desktop) persistent filter panel -->
  <div class="px-4 py-3 lg:flex lg:gap-6 lg:items-start">

    <!-- Product grid -->
    <main class="lg:order-1 lg:flex-1 lg:min-w-0">
      <label class="flex items-center gap-2 px-1 pb-2 text-sm text-gray-600">
        <input type="checkbox" id="select-all-checkbox" class="h-4 w-4 rounded border-gray-300">
        <?php echo htmlspecialchars(t('select')); ?>
      </label>
      <div id="product-list" class="space-y-3 lg:space-y-0 lg:grid lg:grid-cols-2 lg:gap-4"></div>
      <table id="product-table" class="hidden w-full text-sm bg-white rounded-2xl border border-gray-100 overflow-hidden">
        <thead class="bg-gray-50 text-gray-500 text-xs">
          <tr>
            <th class="checkbox-col-header hidden px-3 py-2 w-8"></th>
            <th class="px-3 py-2 w-16"></th>
            <th class="px-3 py-2 text-start"><?php echo htmlspecialchars(t('name')); ?></th>
            <th class="px-3 py-2 text-start"><?php echo htmlspecialchars(t('price')); ?></th>
            <th class="px-3 py-2 text-start"><?php echo htmlspecialchars(t('stock')); ?></th>
          </tr>
        </thead>
        <tbody id="product-table-body" class="divide-y divide-gray-100"></tbody>
      </table>
      <div id="load-more-wrap" class="hidden py-4 text-center">
        <button id="load-more" class="rounded-xl border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('load_more')); ?></button>
      </div>
      <div id="empty-state" class="hidden text-center py-16 text-gray-400">
        <p class="text-sm"><?php echo htmlspecialchars(t('no_products_found')); ?></p>
      </div>
    </main>

    <!-- Filter sheet (mobile: bottom-sheet modal; desktop: persistent side panel) -->
    <div id="filter-sheet" class="hidden fixed inset-0 z-40 lg:w-80 xl:w-96 lg:flex-shrink-0 lg:order-2">
      <div id="filter-overlay" class="absolute inset-0 bg-black/40"></div>
      <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto lg:p-5 lg:border lg:border-gray-100">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('filters')); ?></h2>
          <button id="filter-close" class="text-gray-400 text-xl leading-none lg:hidden">&times;</button>
        </div>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('category')); ?></label>
            <select id="filter-category" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value=""><?php echo htmlspecialchars(t('all_categories')); ?></option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('stock_status')); ?></label>
            <select id="filter-stock" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value=""><?php echo htmlspecialchars(t('all')); ?></option>
              <option value="instock"><?php echo htmlspecialchars(t('in_stock')); ?></option>
              <option value="outofstock"><?php echo htmlspecialchars(t('out_of_stock')); ?></option>
              <option value="onbackorder"><?php echo htmlspecialchars(t('on_backorder')); ?></option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('price_range')); ?></label>
            <div class="flex gap-2">
              <input id="filter-min-price" type="number" min="0" placeholder="<?php echo htmlspecialchars(t('min')); ?>" class="w-1/2 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <input id="filter-max-price" type="number" min="0" placeholder="<?php echo htmlspecialchars(t('max')); ?>" class="w-1/2 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            </div>
          </div>

          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input id="filter-on-sale" type="checkbox" class="h-4 w-4 rounded border-gray-300">
            <?php echo htmlspecialchars(t('on_sale_only')); ?>
          </label>

          <div id="custom-taxonomy-filters" class="space-y-4"></div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sort_by')); ?></label>
            <select id="filter-sort" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value="date-desc"><?php echo htmlspecialchars(t('sort_newest')); ?></option>
              <option value="title-asc"><?php echo htmlspecialchars(t('sort_title_asc')); ?></option>
              <option value="title-desc"><?php echo htmlspecialchars(t('sort_title_desc')); ?></option>
              <option value="price-asc"><?php echo htmlspecialchars(t('sort_price_asc')); ?></option>
              <option value="price-desc"><?php echo htmlspecialchars(t('sort_price_desc')); ?></option>
            </select>
          </div>
        </div>

        <div class="mt-6 flex gap-2">
          <button id="filter-reset" class="flex-1 rounded-xl border border-gray-300 py-3 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('reset')); ?></button>
          <button id="filter-apply" class="flex-1 rounded-xl bg-gray-900 py-3 text-sm font-medium text-white"><?php echo htmlspecialchars(t('apply')); ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Add button (floating) -->
  <a href="/product-wizard.php" id="add-fab"
     class="fixed right-4 bottom-20 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-gray-900 text-white text-2xl shadow-lg active:scale-95 transition">
    +
  </a>

  <!-- Selection action bar -->
  <div id="selection-bar" class="selection-bar-desktop hidden fixed bottom-16 left-0 right-0 z-30 bg-gray-900 text-white px-4 py-3 flex flex-col gap-2">
    <div class="flex items-center justify-between">
      <span id="selection-count" class="text-sm">0 <?php echo htmlspecialchars(t('selected_count')); ?></span>
      <div class="flex gap-2">
        <button id="selection-cancel" class="rounded-lg border border-white/30 px-3 py-2 text-sm"><?php echo htmlspecialchars(t('cancel')); ?></button>
        <button id="selection-batch" class="rounded-lg bg-white text-gray-900 px-3 py-2 text-sm font-medium"><?php echo htmlspecialchars(t('batch_actions')); ?></button>
      </div>
    </div>
    <button id="select-all-matching-filters" class="hidden text-xs text-white/80 underline text-left"></button>
  </div>

  <!-- Bottom navigation (mobile only; see desktop sidebar above) -->
  <?php render_bottom_nav('products', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
  </script>
  <script src="<?= asset_url('/assets/js/i18n.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/products.js') ?>"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
