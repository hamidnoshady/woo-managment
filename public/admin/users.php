<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pwa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/nav.php';

$user = require_superadmin_page();
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('manage_users_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('users')); ?></h1>
      <div class="flex items-center gap-2">
        <button id="add-btn" class="text-sm font-medium text-gray-600 active:text-gray-900"><?php echo htmlspecialchars(t('add_btn')); ?></button>
      </div>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/admin/backups.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
      <a href="/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
    <div id="user-list" class="hidden space-y-3"></div>
  </main>

  <?php render_bottom_nav('settings', $user); ?>

  <!-- User form sheet -->
  <div id="user-sheet" class="hidden fixed inset-0 z-40">
    <div id="user-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="user-sheet-title" class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('add_user')); ?></h2>
        <button id="user-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="user-form" class="space-y-4">
        <input type="hidden" id="user-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('mobile_number')); ?></label>
          <input id="user-phone" type="tel" inputmode="numeric" placeholder="09xxxxxxxxx" required
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="user-name" type="text" placeholder="<?php echo htmlspecialchars(t('optional')); ?>"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('role')); ?></label>
          <select id="user-role" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="admin"><?php echo htmlspecialchars(t('role_admin')); ?></option>
            <option value="shop_manager"><?php echo htmlspecialchars(t('role_shop_manager')); ?></option>
            <option value="superadmin"><?php echo htmlspecialchars(t('role_superadmin')); ?></option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('assigned_sites')); ?></label>
          <div id="user-sites-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
          <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(t('superadmin_all_sites_note')); ?></p>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="button" id="user-delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-sm"><?php echo htmlspecialchars(t('delete')); ?></button>
          <button type="submit" id="user-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm"><?php echo htmlspecialchars(t('save')); ?></button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-users.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
