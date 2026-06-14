<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';

$user = require_login_page();
$site = require_site_page($user);
$productId = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo $productId > 0 ? 'Edit product' : 'New product'; ?> · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 py-3 flex items-center gap-3">
    <a href="/products.php" class="text-gray-500 text-xl leading-none">&larr;</a>
    <h1 class="text-base font-semibold text-gray-900"><?php echo $productId > 0 ? 'Edit product' : 'New product'; ?></h1>
  </header>
  <?php require_once __DIR__ . '/../includes/nav.php'; render_site_switcher($site); ?>

  <main class="px-4 py-4">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm">Loading...</div>

    <form id="product-form" class="hidden space-y-4">
      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">SKU</label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Pricing</h2>
        <div class="flex gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Regular price</label>
            <input id="regular_price" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Sale price</label>
            <input id="sale_price" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Inventory</h2>
        <div class="flex gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Stock quantity</label>
            <input id="stock_quantity" type="number" step="1" min="0" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Stock status</label>
            <select id="stock_status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
              <option value="instock">In stock</option>
              <option value="outofstock">Out of stock</option>
              <option value="onbackorder">On backorder</option>
            </select>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900">Categories</h2>
        <div id="categories-list" class="space-y-2 max-h-48 overflow-y-auto"></div>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900">Images (URLs)</h2>
        <div id="images-list" class="space-y-2"></div>
        <button type="button" id="add-image" class="text-sm font-medium text-gray-600">+ Add image URL</button>
      </div>

      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Description</h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Short description</label>
          <textarea id="short_description" rows="3" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select id="status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="publish">Published</option>
            <option value="draft">Draft</option>
            <option value="private">Private</option>
          </select>
        </div>
      </div>

      <div class="flex gap-2 pb-4">
        <button type="submit" id="save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
          Save
        </button>
        <button type="button" id="delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-base">
          Delete
        </button>
      </div>
    </form>
  </main>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
    window.PRODUCT_ID = <?php echo (int) $productId; ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/product-edit.js"></script>
</body>
</html>
