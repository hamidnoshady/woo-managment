<?php
// public/admin/site-backups.php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pwa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/nav.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_page();
$site = get_current_site($user);
if ($site === null) {
    header('Location: /products.php');
    exit;
}
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('site_backups_heading')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('admin', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('site_backups_heading')); ?></h1>
    </div>
    <?php if ($user['role'] === 'superadmin'): ?>
    <div class="px-4 pb-3 flex gap-2 text-sm overflow-x-auto no-scrollbar">
      <a href="/admin/users.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/admin/backups.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
      <a href="/admin/site-backups.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t('site_backups_heading')); ?></a>
      <a href="/settings.php" class="flex-shrink-0 whitespace-nowrap text-center rounded-xl px-4 border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
    <?php endif; ?>
  </header>

  <main class="px-4 py-3 space-y-4">
    <section class="bg-white rounded-2xl border border-gray-100 p-4">
      <div class="flex gap-2">
        <button id="run-site-db-backup-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_db_backup')); ?></button>
        <button id="run-site-full-backup-btn" class="flex-1 rounded-xl border border-gray-300 text-gray-700 font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_full_backup')); ?></button>
      </div>
    </section>

    <section>
      <h2 class="text-sm font-semibold text-gray-900 mb-2 px-1"><?php echo htmlspecialchars(t('backup_history')); ?></h2>
      <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
      <div id="backup-list" class="hidden space-y-2"></div>
    </section>
  </main>

  <?php render_bottom_nav('admin', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE_ID = <?php echo (int) $site['id']; ?>;
  </script>
  <script src="<?= asset_url('/assets/js/i18n.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/admin-site-backups.js') ?>"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
