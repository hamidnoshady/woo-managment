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
  <title>Verify code · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-900 text-white text-2xl font-bold">P</div>
      <h1 class="text-xl font-semibold text-gray-900">Enter verification code</h1>
      <p class="text-sm text-gray-500 mt-1">We sent a code via SMS to <span id="phone-display" class="font-medium text-gray-700"></span></p>
    </div>

    <form id="verify-form" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
      <div>
        <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Verification code</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required
               placeholder="•••••" maxlength="8"
               class="w-full text-center tracking-widest text-lg rounded-xl border border-gray-300 px-4 py-3 focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
      </div>
      <button type="submit" id="submit-btn"
              class="w-full rounded-xl bg-gray-900 text-white font-medium py-3 text-base active:scale-[0.99] transition disabled:opacity-50">
        Verify &amp; sign in
      </button>
      <button type="button" id="back-btn" class="w-full rounded-xl border border-gray-300 text-gray-700 font-medium py-3 text-base">
        Use a different number
      </button>
    </form>
  </div>

  <script src="/assets/js/app.js"></script>
  <script>
    const phone = sessionStorage.getItem('login_phone') || '';
    if (!phone) {
      window.location.href = '/login.php';
    }
    document.getElementById('phone-display').textContent = phone;

    const form = document.getElementById('verify-form');
    const submitBtn = document.getElementById('submit-btn');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const code = document.getElementById('code').value.trim();

      submitBtn.disabled = true;
      submitBtn.textContent = 'Verifying...';

      try {
        const result = await App.api('/api/auth.php?action=verify-otp', {
          method: 'POST',
          body: JSON.stringify({ phone, code }),
        });
        App.setCsrfToken(result.csrf_token);
        sessionStorage.removeItem('login_phone');
        window.location.href = '/products.php';
      } catch (err) {
        App.toast(err.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Verify & sign in';
      }
    });

    document.getElementById('back-btn').addEventListener('click', () => {
      sessionStorage.removeItem('login_phone');
      window.location.href = '/login.php';
    });
  </script>
</body>
</html>
