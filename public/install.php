<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Users.php';
require_once __DIR__ . '/../includes/auth.php';

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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Initial setup · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm py-8">
    <div class="text-center mb-8">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 text-white text-2xl font-bold">P</div>
      <h1 class="text-xl font-semibold text-gray-900">Initial setup</h1>
      <p class="text-sm text-gray-500 mt-1">Create the first superadmin account</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="mb-4 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm px-4 py-3"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <div>
        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Your mobile number</label>
        <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" required
               placeholder="09xxxxxxxxx"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        <p class="text-xs text-gray-400 mt-1">You'll use this number to sign in with an SMS code.</p>
      </div>

      <div>
        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Your name</label>
        <input id="name" name="name" type="text" placeholder="Optional"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <div class="pt-2 border-t border-gray-100">
        <p class="text-sm font-medium text-gray-700 mb-1">Kavenegar SMS (optional)</p>
        <p class="text-xs text-gray-400 mb-3">Needed to send login codes. You can also set this later from Admin → Settings.</p>
      </div>

      <div>
        <label for="kavenegar_api_key" class="block text-sm font-medium text-gray-700 mb-1">Kavenegar API key</label>
        <input id="kavenegar_api_key" name="kavenegar_api_key" type="text" autocomplete="off"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base font-mono focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <div>
        <label for="kavenegar_template" class="block text-sm font-medium text-gray-700 mb-1">Kavenegar template name</label>
        <input id="kavenegar_template" name="kavenegar_template" type="text" placeholder="verify"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>

      <button type="submit"
              class="w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition">
        Create superadmin account
      </button>
    </form>

    <p class="text-center text-xs text-gray-400 mt-6">
      This page is only available until the first superadmin account is created.
    </p>
  </div>
</body>
</html>
