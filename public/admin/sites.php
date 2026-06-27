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
  <title><?php echo htmlspecialchars(t('manage_sites_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t("sites")); ?></h1>
      <div class="flex items-center gap-2">
        <button id="add-btn" class="text-sm font-medium text-gray-600 active:text-gray-900"><?php echo htmlspecialchars(t('add_btn')); ?></button>
      </div>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/admin/backups.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
      <a href="/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
    <div id="site-list" class="hidden space-y-3"></div>
  </main>

  <?php render_bottom_nav('settings', $user); ?>

  <!-- Site form sheet -->
  <div id="site-sheet" class="hidden fixed inset-0 z-40">
    <div id="site-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="site-sheet-title" class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('add_site')); ?></h2>
        <button id="site-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="site-form" class="space-y-4">
        <input type="hidden" id="site-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('site_name')); ?></label>
          <input id="site-name" type="text" required placeholder="My Store"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('store_url')); ?></label>
          <input id="site-url" type="url" required placeholder="https://example.com"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input id="site-verify-ssl" type="checkbox" checked class="h-4 w-4 rounded border-gray-300">
          <?php echo htmlspecialchars(t('verify_ssl')); ?>
        </label>

        <div class="space-y-2 border-t border-gray-100 pt-3 mt-3">
          <h3 class="text-xs font-semibold text-gray-500 uppercase"><?php echo htmlspecialchars(t('site_connection_heading')); ?></h3>
          <div id="site-agent-status" class="text-xs text-gray-400"></div>
          <button type="button" id="generate-agent-token-btn" class="w-full rounded-xl border border-gray-300 text-gray-700 font-medium py-2 text-sm"><?php echo htmlspecialchars(t('generate_pairing_token')); ?></button>
          <div id="agent-token-display" class="hidden text-xs font-mono bg-gray-50 rounded-lg p-2 break-all" dir="ltr"></div>
        </div>
        <div class="space-y-2 border-t border-gray-100 pt-3 mt-3">
          <h3 class="text-xs font-semibold text-gray-500 uppercase"><?php echo htmlspecialchars(t('site_backups_heading')); ?></h3>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="site-backup-enabled">
            <?php echo htmlspecialchars(t('site_backup_enabled')); ?>
          </label>
          <select id="site-backup-schedule" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm">
            <option value="off"><?php echo htmlspecialchars(t('schedule_off')); ?></option>
            <option value="daily"><?php echo htmlspecialchars(t('schedule_daily')); ?></option>
            <option value="weekly"><?php echo htmlspecialchars(t('schedule_weekly')); ?></option>
          </select>
          <input type="number" id="site-backup-retention" min="1" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm" placeholder="<?php echo htmlspecialchars(t('backup_retention_days_placeholder')); ?>">
        </div>

        <div class="flex gap-2 pt-2">
          <button type="button" id="site-delete-btn" class="hidden rounded-xl border border-red-300 text-red-600 font-medium py-3 px-4 text-sm"><?php echo htmlspecialchars(t('delete')); ?></button>
          <button type="submit" id="site-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm"><?php echo htmlspecialchars(t('save')); ?></button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-sites.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
