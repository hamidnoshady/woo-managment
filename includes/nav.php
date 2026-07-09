<?php

require_once __DIR__ . '/i18n.php';

/**
 * Shared bottom navigation bar.
 *
 * @param string $active One of: products, tasks, logs, settings
 * @param array  $user    Current user (['role' => ...])
 */
function render_bottom_nav(string $active, array $user): void
{
    $isSuperadmin = $user['role'] === 'superadmin';
    $settingsHref = $isSuperadmin ? '/admin/users.php' : '/settings.php';
    $cls = fn($key) => $active === $key ? 'text-gray-900' : 'text-gray-400';
    ?>
    <nav class="bottom-nav fixed bottom-0 left-0 right-0 z-20 bg-white border-t border-gray-100 flex">
      <a href="/products.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('products'); ?>">
        <div class="text-lg leading-none mb-0.5">▤</div><?php echo htmlspecialchars(t('nav_products')); ?>
      </a>
      <a href="/tasks.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('tasks'); ?>">
        <div class="text-lg leading-none mb-0.5">&#10003;</div><?php echo htmlspecialchars(t('nav_tasks')); ?>
      </a>
      <a href="/logs.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('logs'); ?>">
        <div class="text-lg leading-none mb-0.5">🕒</div><?php echo htmlspecialchars(t('nav_logs')); ?>
      </a>
      <a href="<?php echo $settingsHref; ?>" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('settings'); ?>">
        <div class="text-lg leading-none mb-0.5">⚙</div><?php echo htmlspecialchars(t('nav_settings')); ?>
      </a>
      <button id="logout-btn" class="flex-1 py-3 text-center text-xs font-medium text-gray-400">
        <div class="text-lg leading-none mb-0.5">⎋</div><?php echo htmlspecialchars(t('nav_logout')); ?>
      </button>
    </nav>
    <?php
}

/**
 * Small header row showing the active site with a link to switch sites.
 * Hidden on desktop, where the sidebar shows the site switcher instead.
 */
function render_site_switcher(array $site): void
{
    ?>
    <a href="/sites.php" class="mobile-site-switcher flex items-center justify-between gap-2 px-4 py-2 bg-gray-50 border-b border-gray-100 text-xs">
      <span class="text-gray-500"><?php echo htmlspecialchars(t('site_label')); ?>: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($site['name']); ?></span></span>
      <span class="text-gray-400 font-medium"><?php echo htmlspecialchars(t('site_switch')); ?> &rsaquo;</span>
    </a>
    <?php
}

/**
 * Fixed left sidebar shown on desktop (>=1024px) in place of the bottom tab
 * bar. Add the `has-sidebar` class to <body> on pages that include this so
 * content gets the matching inline padding (see app.css).
 *
 * @param string     $active One of: products, tasks, logs, settings
 * @param array|null $site   Current site, if this page is site-scoped (omit for global pages like logs/settings).
 */
function render_desktop_sidebar(string $active, array $user, ?array $site = null): void
{
    $isSuperadmin = $user['role'] === 'superadmin';
    $settingsHref = $isSuperadmin ? '/admin/users.php' : '/settings.php';
    $cls = fn($key) => $active === $key
        ? 'bg-gray-100 text-gray-900'
        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900';
    ?>
    <aside class="desktop-sidebar fixed inset-y-0 left-0 z-30 w-64 flex-col border-r border-gray-100 bg-white">
      <div class="px-4 py-4 border-b border-gray-100">
        <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('app_name')); ?></div>
      </div>

      <?php if ($site !== null): ?>
      <a href="/sites.php" class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 text-xs hover:bg-gray-50">
        <span class="text-gray-500"><?php echo htmlspecialchars(t('site_label')); ?>: <span class="font-medium text-gray-800"><?php echo htmlspecialchars($site['name']); ?></span></span>
        <span class="text-gray-400 font-medium"><?php echo htmlspecialchars(t('site_switch')); ?> &rsaquo;</span>
      </a>
      <?php endif; ?>

      <nav class="flex-1 px-2 py-3 space-y-1">
        <a href="/products.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium <?php echo $cls('products'); ?>">
          <span class="text-base leading-none">▤</span><?php echo htmlspecialchars(t('nav_products')); ?>
        </a>
        <a href="/tasks.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium <?php echo $cls('tasks'); ?>">
          <span class="text-base leading-none">&#10003;</span><?php echo htmlspecialchars(t('nav_tasks')); ?>
        </a>
        <a href="/logs.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium <?php echo $cls('logs'); ?>">
          <span class="text-base leading-none">🕒</span><?php echo htmlspecialchars(t('nav_logs')); ?>
        </a>
        <a href="<?php echo $settingsHref; ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium <?php echo $cls('settings'); ?>">
          <span class="text-base leading-none">⚙</span><?php echo htmlspecialchars(t('nav_settings')); ?>
        </a>
      </nav>

      <div class="px-2 py-3 border-t border-gray-100 space-y-1">
        <?php render_lang_switcher(); ?>
        <button id="logout-btn-desktop" class="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900">
          <span class="text-base leading-none">⎋</span><?php echo htmlspecialchars(t('nav_logout')); ?>
        </button>
      </div>
    </aside>
    <?php
}

/**
 * Small EN/FA language toggle. Pass the current request path so the user
 * stays on the same page after switching.
 */
function render_lang_switcher(?string $returnTo = null): void
{
    $returnTo = $returnTo ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $lang = current_lang();
    ?>
    <div class="flex items-center gap-1 text-xs font-medium">
      <a href="/api/lang.php?lang=en&redirect=<?php echo urlencode($returnTo); ?>"
         class="px-2 py-1 rounded-lg <?php echo $lang === 'en' ? 'bg-gray-900 text-white' : 'text-gray-400'; ?>">EN</a>
      <a href="/api/lang.php?lang=fa&redirect=<?php echo urlencode($returnTo); ?>"
         class="px-2 py-1 rounded-lg <?php echo $lang === 'fa' ? 'bg-gray-900 text-white' : 'text-gray-400'; ?>">فا</a>
    </div>
    <?php
}
