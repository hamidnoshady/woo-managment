<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();

$roleLabels = [
    'superadmin'   => t('role_superadmin'),
    'admin'        => t('role_admin'),
    'shop_manager' => t('role_shop_manager'),
];
$roleLabel = $roleLabels[$user['role']] ?? $user['role'];

$lang = current_lang();
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('account_settings_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('account_settings_heading')); ?></h1>
    </div>
  </header>

  <main class="px-4 py-3 space-y-4">

    <section class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('account_section')); ?></h2>
      <div class="space-y-2 text-sm">
        <?php if ($user['name'] !== ''): ?>
        <div class="flex items-center justify-between">
          <span class="text-gray-500"><?php echo htmlspecialchars(t('name')); ?></span>
          <span class="font-medium text-gray-900"><?php echo htmlspecialchars($user['name']); ?></span>
        </div>
        <?php endif; ?>
        <div class="flex items-center justify-between">
          <span class="text-gray-500"><?php echo htmlspecialchars(t('mobile_number')); ?></span>
          <span class="font-medium text-gray-900" dir="ltr"><?php echo htmlspecialchars($user['phone']); ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500"><?php echo htmlspecialchars(t('role')); ?></span>
          <span class="font-medium text-gray-900"><?php echo htmlspecialchars($roleLabel); ?></span>
        </div>
      </div>
    </section>

    <section class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('language_section')); ?></h2>
      <p class="text-xs text-gray-400"><?php echo htmlspecialchars(t('language_section_help')); ?></p>
      <div class="flex gap-2">
        <a href="/api/lang.php?lang=en&redirect=<?php echo urlencode('/settings.php'); ?>"
           class="flex-1 text-center rounded-xl border py-2.5 text-sm font-medium <?php echo $lang === 'en' ? 'bg-gray-900 text-white border-gray-900' : 'border-gray-300 text-gray-700'; ?>">
          <?php echo htmlspecialchars(t('lang_en')); ?>
        </a>
        <a href="/api/lang.php?lang=fa&redirect=<?php echo urlencode('/settings.php'); ?>"
           class="flex-1 text-center rounded-xl border py-2.5 text-sm font-medium <?php echo $lang === 'fa' ? 'bg-gray-900 text-white border-gray-900' : 'border-gray-300 text-gray-700'; ?>">
          <?php echo htmlspecialchars(t('lang_fa')); ?>
        </a>
      </div>
    </section>

  </main>

  <?php render_bottom_nav('settings', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script>
    document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  </script>
</body>
</html>
