<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav.php';
require_once __DIR__ . '/../includes/i18n.php';

$user = require_login_page();
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('select_site_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= asset_url('/assets/css/app.css') ?>">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 py-3 flex items-center justify-between">
    <h1 class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('select_a_site')); ?></h1>
  </header>

  <main class="px-4 py-4">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>

    <div id="site-list" class="hidden space-y-3"></div>

    <div id="empty-state" class="hidden text-center py-16 text-gray-400 text-sm">
      <p><?php echo htmlspecialchars(t('no_sites_assigned')); ?></p>
      <?php if ($user['role'] === 'superadmin'): ?>
        <a href="/admin/sites.php" class="inline-block mt-4 rounded-xl bg-gray-900 text-white text-sm font-medium px-4 py-2.5"><?php echo htmlspecialchars(t('add_a_site')); ?></a>
      <?php else: ?>
        <p class="mt-2"><?php echo htmlspecialchars(t('ask_superadmin_for_site')); ?></p>
      <?php endif; ?>
    </div>
  </main>

  <?php render_bottom_nav('products', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="<?= asset_url('/assets/js/i18n.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/app.js') ?>"></script>
  <script src="<?= asset_url('/assets/js/sites.js') ?>"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
