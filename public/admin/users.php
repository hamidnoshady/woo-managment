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
  <title>Manage users · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900">Users</h1>
      <button id="add-btn" class="text-sm font-medium text-gray-600 active:text-gray-900">+ Add</button>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium">Users</a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium">Sites</a>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm">Loading...</div>
    <div id="user-list" class="hidden space-y-3"></div>
  </main>

  <?php render_bottom_nav('admin', $user); ?>

  <!-- User form sheet -->
  <div id="user-sheet" class="hidden fixed inset-0 z-40">
    <div id="user-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="user-sheet-title" class="text-base font-semibold text-gray-900">Add user</h2>
        <button id="user-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="user-form" class="space-y-4">
        <input type="hidden" id="user-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mobile number</label>
          <input id="user-phone" type="tel" inputmode="numeric" placeholder="09xxxxxxxxx" required
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
          <input id="user-name" type="text" placeholder="Optional"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
          <select id="user-role" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="admin">Admin</option>
            <option value="shop_manager">Shop manager</option>
            <option value="superadmin">Superadmin</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Assigned sites</label>
          <div id="user-sites-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
          <p class="text-xs text-gray-400 mt-1">Superadmins automatically have access to all sites.</p>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="button" id="user-delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-sm">Delete</button>
          <button type="submit" id="user-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-users.js"></script>
</body>
</html>
