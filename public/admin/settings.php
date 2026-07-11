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
  <title><?php echo htmlspecialchars(t('settings_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('settings')); ?></h1>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm overflow-x-auto no-scrollbar">
      <a href="/admin/users.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/admin/backups.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
      <a href="/admin/site-backups.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('site_backups_heading')); ?></a>
      <a href="/settings.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
  </header>

  <main class="px-4 py-3 space-y-5">
    <section class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('app_version_heading')); ?></h2>
      <p id="app-version-current" class="text-sm text-gray-600"><?php echo htmlspecialchars(t('app_version_current', app_version())); ?></p>
      <p id="app-version-status" class="text-sm text-gray-600"></p>
      <div class="flex gap-2">
        <button id="app-update-check-btn" type="button" class="flex-1 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('app_update_check_btn')); ?></button>
        <button id="app-update-apply-btn" type="button" class="hidden flex-1 rounded-xl bg-gray-900 text-white py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('app_update_btn')); ?></button>
      </div>
    </section>

    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
    <form id="settings-form" class="hidden space-y-5"></form>
  </main>

  <?php render_bottom_nav('settings', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="<?= asset_url('/assets/js/i18n.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/admin-settings.js') ?>"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
