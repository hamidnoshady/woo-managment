<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('logs_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('logs_heading')); ?></h1>
    </div>
    <?php if ($user['role'] === 'superadmin'): ?>
    <div class="px-4 pb-3 flex gap-2">
      <button data-scope="mine" class="scope-btn flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm font-medium"><?php echo htmlspecialchars(t('my_logs')); ?></button>
      <button data-scope="site" class="scope-btn flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm font-medium"><?php echo htmlspecialchars(t('site_logs')); ?></button>
      <button data-scope="system" class="scope-btn flex-1 rounded-xl border border-gray-300 px-3 py-2 text-sm font-medium"><?php echo htmlspecialchars(t('system_logs')); ?></button>
    </div>
    <?php endif; ?>
  </header>

  <main class="px-4 py-3">
    <div id="log-list" class="space-y-2"></div>
    <div id="load-more-wrap" class="hidden py-4 text-center">
      <button id="load-more" class="rounded-xl border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('load_more')); ?></button>
    </div>
    <div id="empty-state" class="hidden text-center py-16 text-gray-400">
      <p class="text-sm"><?php echo htmlspecialchars(t('no_logs')); ?></p>
    </div>
  </main>

  <?php render_bottom_nav('logs', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/logs.js"></script>
</body>
</html>
