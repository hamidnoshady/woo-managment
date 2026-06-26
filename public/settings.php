<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
$isSuperadmin = $user['role'] === 'superadmin';

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
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('account_settings_heading')); ?></h1>
    </div>
    <?php if ($isSuperadmin): ?>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/settings.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
    <?php endif; ?>
  </header>

  <main class="px-4 py-3 space-y-4">

    <section class="bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
      <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('account_section')); ?></h2>
      <form id="profile-form" class="space-y-3 text-sm">
        <div>
          <label class="block text-gray-500 mb-1" for="profile-name"><?php echo htmlspecialchars(t('name')); ?></label>
          <input id="profile-name" type="text" value="<?php echo htmlspecialchars($user['name']); ?>"
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500"><?php echo htmlspecialchars(t('mobile_number')); ?></span>
          <span class="font-medium text-gray-900" dir="ltr"><?php echo htmlspecialchars($user['phone']); ?></span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-gray-500"><?php echo htmlspecialchars(t('role')); ?></span>
          <span class="font-medium text-gray-900"><?php echo htmlspecialchars($roleLabel); ?></span>
        </div>
        <button type="submit" id="profile-save-btn" class="w-full rounded-xl bg-gray-900 text-white font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('save')); ?></button>
      </form>
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

    document.getElementById('profile-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('profile-save-btn');
      const name = document.getElementById('profile-name').value.trim();
      btn.disabled = true;
      try {
        await App.api('/api/profile.php', { method: 'PUT', body: JSON.stringify({ name }) });
        App.toast(t('profile_saved'), 'success');
      } catch (err) {
        App.toast(err.message, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  </script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
