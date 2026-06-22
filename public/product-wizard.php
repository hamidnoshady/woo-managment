<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/i18n.php';

$user = require_login_page();
$site = require_site_page($user);
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('product_wizard_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php require_once __DIR__ . '/../includes/nav.php'; render_desktop_sidebar('products', $user, $site); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 py-3 flex items-center gap-3">
    <a href="/products.php" class="text-gray-500 text-xl leading-none">&larr;</a>
    <div class="flex-1">
      <h1 class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('product_wizard_heading')); ?></h1>
      <p id="step-indicator" class="text-xs text-gray-400"></p>
    </div>
    <?php render_lang_switcher('/product-wizard.php'); ?>
  </header>
  <?php render_site_switcher($site); ?>

  <!-- Progress bar -->
  <div class="px-4 pt-3">
    <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
      <div id="progress-bar" class="h-full bg-gray-900 transition-all" style="width: 0%"></div>
    </div>
  </div>

  <main class="px-4 py-4">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>

    <form id="wizard-form" class="hidden space-y-4">

      <!-- Step 1: Basic info -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="0">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_basic')); ?></h2>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="name" type="text" required class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('sku')); ?></label>
          <input id="sku" type="text" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
      </section>

      <!-- Step 2: Pricing -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="1">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_pricing')); ?></h2>
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
      </section>

      <!-- Step 3: Inventory -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="2">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_inventory')); ?></h2>
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
      </section>

      <!-- Step 4: Categories & custom taxonomies -->
      <section class="wizard-step space-y-4" data-step="3">
        <div class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
          <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_categories')); ?></h2>
          <div id="categories-list" class="space-y-2 max-h-48 overflow-y-auto"></div>
        </div>
        <div id="custom-taxonomies" class="hidden space-y-3"></div>
      </section>

      <!-- Step 5: Images -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-3" data-step="4">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_images')); ?></h2>
        <div id="images-list" class="space-y-2"></div>
        <div class="flex flex-wrap gap-2">
          <button type="button" id="add-image" class="text-sm font-medium text-gray-600"><?php echo htmlspecialchars(t('add_image_url')); ?></button>
          <label class="text-sm font-medium text-gray-600 cursor-pointer">
            <?php echo htmlspecialchars(t('upload_image')); ?>
            <input type="file" id="image-upload" accept="image/*" class="hidden">
          </label>
        </div>
        <div class="space-y-1 pt-1">
          <p class="text-xs font-medium text-gray-500"><?php echo htmlspecialchars(t('image_edit_options')); ?></p>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input id="edit-white-bg" type="checkbox" class="h-4 w-4 rounded border-gray-300">
            <?php echo htmlspecialchars(t('image_edit_white_bg')); ?>
          </label>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input id="edit-enhance" type="checkbox" class="h-4 w-4 rounded border-gray-300">
            <?php echo htmlspecialchars(t('image_edit_enhance')); ?>
          </label>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input id="edit-resize" type="checkbox" class="h-4 w-4 rounded border-gray-300">
            <?php echo htmlspecialchars(t('image_edit_resize')); ?>
          </label>
        </div>
      </section>

      <!-- Step 6: Description -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-4" data-step="5">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_description')); ?></h2>
          <button type="button" id="ai-generate-description" class="text-sm font-medium text-gray-600"><?php echo htmlspecialchars(t('generate_with_ai')); ?></button>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('short_description')); ?></label>
          <textarea id="short_description" rows="3" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('long_description')); ?></label>
          <textarea id="description" rows="6" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('status')); ?></label>
          <select id="status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="publish"><?php echo htmlspecialchars(t('status_publish')); ?></option>
            <option value="draft"><?php echo htmlspecialchars(t('status_draft')); ?></option>
            <option value="private"><?php echo htmlspecialchars(t('status_private')); ?></option>
          </select>
        </div>
      </section>

      <!-- Step 7: Review -->
      <section class="wizard-step bg-white rounded-2xl border border-gray-100 p-4 space-y-3" data-step="6">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('wizard_step_review')); ?></h2>
        <p class="text-xs text-gray-400"><?php echo htmlspecialchars(t('wizard_review_help')); ?></p>
        <div id="review-summary" class="space-y-2 text-sm text-gray-700"></div>
      </section>

      <!-- Navigation -->
      <div class="flex gap-2 pb-4">
        <button type="button" id="wizard-back" class="hidden rounded-xl border border-gray-300 text-gray-700 font-medium py-3 px-4 text-base">
          <?php echo htmlspecialchars(t('wizard_back')); ?>
        </button>
        <button type="button" id="wizard-next" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition">
          <?php echo htmlspecialchars(t('wizard_next')); ?>
        </button>
        <button type="submit" id="wizard-publish" class="hidden flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
          <?php echo htmlspecialchars(t('wizard_publish')); ?>
        </button>
      </div>
    </form>
  </main>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/product-wizard.js"></script>
</body>
</html>
