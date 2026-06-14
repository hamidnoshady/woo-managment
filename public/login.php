<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

if (current_user() !== null) {
    header('Location: /products.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Sign in · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 text-white text-2xl font-bold">P</div>
      <h1 class="text-xl font-semibold text-gray-900">Product Manager</h1>
      <p class="text-sm text-gray-500 mt-1">Sign in with your mobile number</p>
    </div>

    <form id="login-form" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
      <div>
        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Mobile number</label>
        <input id="phone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" required
               placeholder="09xxxxxxxxx"
               class="w-full rounded-xl border border-gray-300 px-4 py-3 text-base focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>
      <button type="submit" id="submit-btn"
              class="w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
        Send code
      </button>
    </form>

    <p class="text-center text-xs text-gray-400 mt-6">Access is limited to authorized admins and shop managers.</p>
  </div>

  <script src="/assets/js/app.js"></script>
  <script>
    const form = document.getElementById('login-form');
    const submitBtn = document.getElementById('submit-btn');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const phone = document.getElementById('phone').value.trim();

      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';

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
        submitBtn.textContent = 'Send code';
      }
    });
  </script>
</body>
</html>
