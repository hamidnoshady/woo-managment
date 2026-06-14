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
  <title>Batch actions · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <?php render_site_switcher($site); ?>
    <div class="px-4 py-3 flex items-center gap-3">
      <a href="/products.php" class="text-gray-500 text-xl leading-none">&larr;</a>
      <h1 class="text-base font-semibold text-gray-900">Batch actions</h1>
    </div>
  </header>

  <main class="px-4 py-4 space-y-4">

    <div id="no-selection" class="hidden bg-white rounded-2xl border border-gray-100 p-6 text-center">
      <p class="text-sm text-gray-500 mb-3">No products selected.</p>
      <a href="/products.php" class="inline-block rounded-xl bg-gray-900 text-white text-sm font-medium px-4 py-2.5">Select products</a>
    </div>

    <div id="batch-content" class="hidden space-y-4">
      <div class="bg-white rounded-2xl border border-gray-100 p-4">
        <p class="text-sm text-gray-600"><span id="selection-count" class="font-semibold text-gray-900">0</span> products selected</p>
      </div>

      <!-- Price adjustment (admin only) -->
      <div id="price-section" class="hidden bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Price adjustment</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Change (%)</label>
          <div class="flex items-center gap-2">
            <button type="button" id="price-sign" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-medium w-16">+</button>
            <input id="price-percent" type="number" step="0.01" min="0" value="10"
                   class="flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
            <span class="text-sm text-gray-500">%</span>
          </div>
          <p class="text-xs text-gray-400 mt-1">Increase or decrease price by a percentage.</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Apply to</label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input id="apply-regular" type="checkbox" checked class="h-4 w-4 rounded border-gray-300"> Regular price
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input id="apply-sale" type="checkbox" class="h-4 w-4 rounded border-gray-300"> Sale price
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Rounding</label>
          <select id="price-mode" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="none">No rounding (2 decimals)</option>
            <option value="nearest">Round to nearest whole number</option>
            <option value="up">Round up</option>
            <option value="down">Round down</option>
            <option value="step">Round to nearest step...</option>
            <option value="ending">Round to ending (e.g. .99)...</option>
          </select>
        </div>

        <div id="price-extra" class="hidden">
          <label id="price-extra-label" class="block text-sm font-medium text-gray-700 mb-1">Step</label>
          <input id="price-extra-input" type="number" step="0.01" min="0" value="1000"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <button type="button" id="price-preview-btn" class="w-full rounded-xl border border-gray-300 py-3 text-sm font-medium text-gray-700">
          Preview changes
        </button>
      </div>

      <!-- Stock adjustment -->
      <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Stock adjustment</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Action</label>
          <select id="stock-action" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="delta">Increase / decrease by amount</option>
            <option value="set">Set exact quantity</option>
          </select>
        </div>

        <div>
          <label id="stock-value-label" class="block text-sm font-medium text-gray-700 mb-1">Amount (use negative to decrease)</label>
          <input id="stock-value" type="number" step="1" value="1"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <button type="button" id="stock-preview-btn" class="w-full rounded-xl border border-gray-300 py-3 text-sm font-medium text-gray-700">
          Preview changes
        </button>
      </div>

      <!-- Preview / confirm -->
      <div id="preview-section" class="hidden bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900">Preview</h2>
        <div id="preview-list" class="space-y-2 max-h-72 overflow-y-auto"></div>
        <div class="flex gap-2 pt-2">
          <button type="button" id="preview-cancel" class="flex-1 rounded-xl border border-gray-300 py-3 text-sm font-medium text-gray-700">Cancel</button>
          <button type="button" id="preview-confirm" class="flex-1 rounded-xl bg-gray-900 text-white py-3 text-sm font-medium">Apply changes</button>
        </div>
      </div>
    </div>
  </main>

  <?php render_bottom_nav('batch', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/batch.js"></script>
</body>
</html>
