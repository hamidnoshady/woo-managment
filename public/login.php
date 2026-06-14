<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/nav.php';

$pdo = Database::get();
$hasSuperadmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn() > 0;
if (!$hasSuperadmin) {
    header('Location: /install.php');
    exit;
}

if (current_user() !== null) {
    header('Location: /products.php');
    exit;
}
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('sign_in_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="absolute top-4 right-4"><?php render_lang_switcher('/login.php'); ?></div>
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 text-white text-2xl font-bold">P</div>
      <h1 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars(t('app_name')); ?></h1>
      <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(t('sign_in_heading')); ?></p>
    </div>

    <form id="login-form" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
      <div>
        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('mobile_number')); ?></label>
        <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" required
               placeholder="09xxxxxxxxx" dir="ltr"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base text-center focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>
      <button type="submit" id="submit-btn"
              class="w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
        <?php echo htmlspecialchars(t('send_code')); ?>
      </button>
    </form>

    <p class="text-center text-xs text-gray-400 mt-6"><?php echo htmlspecialchars(t('access_limited')); ?></p>
  </div>

  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script>
    const form = document.getElementById('login-form');
    const submitBtn = document.getElementById('submit-btn');
    const sendLabel = submitBtn.textContent.trim();

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const phone = document.getElementById('phone').value.trim();

      submitBtn.disabled = true;
      submitBtn.textContent = t('sending');

      try {
        await App.api('/api/auth.php?action=request-otp', {
          method: 'POST',
          body: JSON.stringify({ phone }),
        });
        sessionStorage.setItem('login_phone', phone);
        window.location.href = '/verify.php';
      } catch (err) {
        App.toast(err.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = sendLabel;
      }
    });
  </script>
</body>
</html>
