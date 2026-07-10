<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/i18n.php';

$user = require_login_page();
$site = require_site_page($user);
$productId = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars($productId > 0 ? t('edit_product') : t('new_product')); ?> · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php require_once __DIR__ . '/../includes/nav.php'; render_desktop_sidebar('products', $user, $site); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 py-3 flex items-center gap-3">
    <a href="/products.php" class="text-gray-500 text-xl leading-none">&larr;</a>
    <h1 class="text-base font-semibold text-gray-900 flex-1"><?php echo htmlspecialchars($productId > 0 ? t('edit_product') : t('new_product')); ?></h1>
    <a id="view-product-link" href="#" target="_blank" rel="noopener" class="hidden text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('view_on_site')); ?></a>
  </header>
  <?php render_site_switcher($site); ?>

  <main class="px-4 py-4 lg:max-w-2xl lg:mx-auto">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>

    <form id="product-form" class="hidden space-y-4">
      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('pricing')); ?></h2>
        <div class="flex gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('regular_price')); ?></label>
            <input id="regular_price" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sale_price')); ?></label>
            <input id="sale_price" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('inventory')); ?></h2>
        <div class="flex gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('stock_quantity')); ?></label>
            <input id="stock_quantity" type="number" step="1" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('stock_status')); ?></label>
            <select id="stock_status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value="instock"><?php echo htmlspecialchars(t('in_stock')); ?></option>
              <option value="outofstock"><?php echo htmlspecialchars(t('out_of_stock')); ?></option>
              <option value="onbackorder"><?php echo htmlspecialchars(t('on_backorder')); ?></option>
            </select>
          </div>
        </div>
      </div>

      <details class="bg-white rounded-2xl border border-gray-100 p-4" open>
        <summary class="text-sm font-semibold text-gray-900 cursor-pointer"><?php echo htmlspecialchars(t('categories')); ?></summary>
        <div id="categories-list" class="space-y-2 max-h-48 overflow-y-auto mt-3"></div>
      </details>

      <div id="custom-taxonomies" class="hidden space-y-3"></div>

      <details class="bg-white rounded-2xl border border-gray-100 p-4" open>
        <summary class="text-sm font-semibold text-gray-900 cursor-pointer"><?php echo htmlspecialchars(t('images_urls')); ?></summary>
        <div class="space-y-3 mt-3">
          <div id="images-grid" class="flex flex-wrap gap-3"></div>
          <input type="file" id="image-upload" accept="image/*" multiple class="hidden">
          <button type="button" id="add-image" class="text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('add_image_url')); ?></button>
          <p id="upload-status" class="hidden text-xs text-blue-600"></p>
          <p class="text-xs text-gray-400"><?php echo htmlspecialchars(t('wizard_images_gallery_hint')); ?></p>
        </div>
      </details>

      <details class="bg-white rounded-2xl border border-gray-100 p-4">
        <summary class="text-sm font-semibold text-gray-900 cursor-pointer"><?php echo htmlspecialchars(t('description')); ?></summary>
        <div class="space-y-4 mt-3">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('short_description')); ?></label>
              <button type="button" id="ai-generate-short" class="text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('generate_with_ai')); ?></button>
            </div>
            <textarea id="short_description" rows="3" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('long_description')); ?></label>
              <button type="button" id="ai-generate-long" class="text-xs font-medium text-blue-600"><?php echo htmlspecialchars(t('generate_with_ai')); ?></button>
            </div>
            <textarea id="description" rows="6" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
          </div>
        </div>
      </details>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-2">
        <label class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('status')); ?></label>
        <select id="status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
          <option value="publish"><?php echo htmlspecialchars(t('status_publish')); ?></option>
          <option value="draft"><?php echo htmlspecialchars(t('status_draft')); ?></option>
          <option value="private"><?php echo htmlspecialchars(t('status_private')); ?></option>
        </select>
      </div>

      <div class="flex gap-2 pb-4">
        <button type="submit" id="save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
          <?php echo htmlspecialchars(t('save')); ?>
        </button>
        <button type="button" id="delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-base">
          <?php echo htmlspecialchars(t('delete')); ?>
        </button>
      </div>
    </form>
  </main>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
    window.PRODUCT_ID = <?php echo (int) $productId; ?>;
  </script>
  <script src="<?= asset_url('/assets/js/i18n.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/product-edit.js') ?>"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
