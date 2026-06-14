<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/nav.php';

$user = require_superadmin_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Manage sites · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900">Sites</h1>
      <button id="add-btn" class="text-sm font-medium text-gray-600 active:text-gray-900">+ Add</button>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium">Users</a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium">Sites</a>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm">Loading...</div>
    <div id="site-list" class="hidden space-y-3"></div>
  </main>

  <?php render_bottom_nav('admin', $user); ?>

  <!-- Site form sheet -->
  <div id="site-sheet" class="hidden fixed inset-0 z-40">
    <div id="site-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="site-sheet-title" class="text-base font-semibold text-gray-900">Add site</h2>
        <button id="site-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="site-form" class="space-y-4">
        <input type="hidden" id="site-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Site name</label>
          <input id="site-name" type="text" required placeholder="My Store"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Store URL</label>
          <input id="site-url" type="url" required placeholder="https://example.com"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Consumer key</label>
          <input id="site-ck" type="text" autocomplete="off" placeholder="ck_..."
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          <p id="site-ck-hint" class="hidden text-xs text-gray-400 mt-1"></p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Consumer secret</label>
          <input id="site-cs" type="text" autocomplete="off" placeholder="cs_..."
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm font-mono focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
          <p id="site-cs-hint" class="hidden text-xs text-gray-400 mt-1"></p>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input id="site-verify-ssl" type="checkbox" checked class="h-4 w-4 rounded border-gray-300">
          Verify SSL certificate
        </label>

        <div class="flex gap-2 pt-2">
          <button type="button" id="site-delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-sm">Delete</button>
          <button type="submit" id="site-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-sites.js"></script>
</body>
</html>
