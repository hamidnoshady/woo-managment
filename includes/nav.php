<?php

/**
 * Shared bottom navigation bar.
 *
 * @param string $active One of: products, batch, admin
 * @param array  $user    Current user (['role' => ...])
 */
function render_bottom_nav(string $active, array $user): void
{
    $isSuperadmin = $user['role'] === 'superadmin';
    $cls = fn($key) => $active === $key ? 'text-gray-900' : 'text-gray-400';
    ?>
    <nav class="bottom-nav fixed bottom-0 left-0 right-0 z-20 bg-white border-t border-gray-100 flex">
      <a href="/products.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('products'); ?>">
        <div class="text-lg leading-none mb-0.5">▤</div>Products
      </a>
      <a href="/batch.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('batch'); ?>">
        <div class="text-lg leading-none mb-0.5">%</div>Batch
      </a>
      <?php if ($isSuperadmin): ?>
      <a href="/admin/users.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('admin'); ?>">
        <div class="text-lg leading-none mb-0.5">★</div>Admin
      </a>
      <?php endif; ?>
      <button id="logout-btn" class="flex-1 py-3 text-center text-xs font-medium text-gray-400">
        <div class="text-lg leading-none mb-0.5">⎋</div>Logout
      </button>
    </nav>
    <?php
}

/**
 * Small header row showing the active site with a link to switch sites.
 */
function render_site_switcher(array $site): void
{
    ?>
    <a href="/sites.php" class="flex items-center justify-between gap-2 px-4 py-2 bg-gray-50 border-b border-gray-100 text-xs">
      <span class="text-gray-500">Site: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($site['name']); ?></span></span>
      <span class="text-gray-400 font-medium">Switch &rsaquo;</span>
    </a>
    <?php
}
