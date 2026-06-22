<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Users.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/nav.php';

start_app_session();

$pdo = Database::get();
$hasSuperadmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn() > 0;

if ($hasSuperadmin) {
    header('Location: /login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form submission. Please reload the page and try again.';
    } else {
        $phone = normalize_phone((string) ($_POST['phone'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($phone === '') {
            $error = 'Please enter a valid mobile number (format 09xxxxxxxxx).';
        } else {
            $existing = find_user_by_phone($phone);
            if ($existing !== null) {
                update_user((int) $existing['id'], ['name' => $name !== '' ? $name : $existing['name'], 'role' => 'superadmin']);
            } else {
                create_user($phone, $name, 'superadmin');
            }

            update_settings([
                'superadmin_phones'  => $phone,
                'kavenegar_api_key'  => trim((string) ($_POST['kavenegar_api_key'] ?? '')),
                'kavenegar_template' => trim((string) ($_POST['kavenegar_template'] ?? '')) ?: 'verify',
            ]);

            header('Location: /login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('install_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="absolute top-4 right-4"><?php render_lang_switcher('/install.php'); ?></div>
  <div class="w-full max-w-sm py-8">
    <div class="text-center mb-8">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 text-white text-2xl font-bold">P</div>
      <h1 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars(t('install_heading')); ?></h1>
      <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(t('install_subheading')); ?></p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="mb-4 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm px-4 py-3"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <div>
        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('install_your_number')); ?></label>
        <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" required
               placeholder="09xxxxxxxxx" dir="ltr"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(t('install_number_help')); ?></p>
      </div>

      <div>
        <label for="name" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('install_your_name')); ?></label>
        <input id="name" name="name" type="text" placeholder="<?php echo htmlspecialchars(t('optional')); ?>"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <div class="pt-2 border-t border-gray-100">
        <p class="text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('install_kavenegar_heading')); ?></p>
        <p class="text-xs text-gray-400 mb-3"><?php echo htmlspecialchars(t('install_kavenegar_help')); ?></p>
      </div>

      <div>
        <label for="kavenegar_api_key" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('kavenegar_api_key_label')); ?></label>
        <input id="kavenegar_api_key" name="kavenegar_api_key" type="text" autocomplete="off" dir="ltr"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base font-mono focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <div>
        <label for="kavenegar_template" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('kavenegar_template_label')); ?></label>
        <input id="kavenegar_template" name="kavenegar_template" type="text" placeholder="verify" dir="ltr"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <button type="submit"
              class="w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition">
        <?php echo htmlspecialchars(t('install_submit')); ?>
      </button>
    </form>

    <p class="text-center text-xs text-gray-400 mt-6">
      <?php echo htmlspecialchars(t('install_footer')); ?>
    </p>
  </div>
  <?php render_pwa_register_script(); ?>
</body>
</html>
