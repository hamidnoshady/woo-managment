<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Select site · Product Manager</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav">

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100 px-4 py-3">
    <h1 class="text-base font-semibold text-gray-900">Select a site</h1>
  </header>

  <main class="px-4 py-4">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm">Loading...</div>

    <div id="site-list" class="hidden space-y-3"></div>

    <div id="empty-state" class="hidden text-center py-16 text-gray-400 text-sm">
      <p>No sites are assigned to your account yet.</p>
      <?php if ($user['role'] === 'superadmin'): ?>
        <a href="/admin/sites.php" class="inline-block mt-4 rounded-xl bg-gray-900 text-white text-sm font-medium px-4 py-2.5">Add a site</a>
      <?php else: ?>
        <p class="mt-2">Ask a superadmin to assign you to a site.</p>
      <?php endif; ?>
    </div>
  </main>

  <?php render_bottom_nav('products', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/sites.js"></script>
</body>
</html>
