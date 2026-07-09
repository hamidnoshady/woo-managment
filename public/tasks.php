<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
$site = require_site_page($user);
$isManager = in_array($user['role'], ['superadmin', 'admin'], true);
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('tasks_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('tasks', $user, $site); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <?php render_site_switcher($site); ?>
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('tasks_heading')); ?></h1>
      <button id="push-enable-btn" class="text-sm font-medium text-gray-600"><?php echo htmlspecialchars(t('task_enable_push')); ?></button>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
    <div id="task-list" class="hidden space-y-3"></div>
    <div id="empty-state" class="hidden text-center py-16 text-gray-400">
      <p class="text-sm"><?php echo htmlspecialchars(t('task_no_tasks')); ?></p>
    </div>
  </main>

  <?php if ($isManager): ?>
  <button id="add-fab"
     class="fixed right-4 bottom-20 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-gray-900 text-white text-2xl shadow-lg active:scale-95 transition">
    +
  </button>
  <?php endif; ?>

  <?php render_bottom_nav('tasks', $user); ?>

  <!-- Task create/edit sheet -->
  <div id="task-sheet" class="hidden fixed inset-0 z-40">
    <div id="task-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="task-sheet-title" class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('add_task')); ?></h2>
        <button id="task-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="task-form" class="space-y-4">
        <input type="hidden" id="task-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_title_label')); ?></label>
          <input id="task-title" type="text" required
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_description_label')); ?></label>
          <textarea id="task-description" rows="3"
                    class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
        </div>

        <div id="task-due-date-field">
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_due_date')); ?></label>
          <!-- Populated by tasks.js: native <input type=date> for en, a Jalali y/m/d select trio for fa (see Task 17). -->
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_priority')); ?></label>
          <select id="task-priority" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="low"><?php echo htmlspecialchars(t('task_priority_low')); ?></option>
            <option value="medium" selected><?php echo htmlspecialchars(t('task_priority_medium')); ?></option>
            <option value="high"><?php echo htmlspecialchars(t('task_priority_high')); ?></option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_assignees_label')); ?></label>
          <div id="task-assignees-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="submit" id="task-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm"><?php echo htmlspecialchars(t('save')); ?></button>
        </div>
      </form>
    </div>
  </div>

  <!-- Task detail sheet -->
  <div id="task-detail-sheet" class="hidden fixed inset-0 z-40">
    <div id="task-detail-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="task-detail-title" class="text-base font-semibold text-gray-900"></h2>
        <button id="task-detail-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <p id="task-detail-description" class="text-sm text-gray-600 mb-3"></p>
      <p id="task-detail-due" class="text-xs text-gray-500 mb-1"></p>
      <p id="task-detail-assignees" class="text-xs text-gray-500 mb-3"></p>

      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_status_todo')); ?> / <?php echo htmlspecialchars(t('task_status_done')); ?></label>
        <select id="task-detail-status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
          <option value="todo"><?php echo htmlspecialchars(t('task_status_todo')); ?></option>
          <option value="in_progress"><?php echo htmlspecialchars(t('task_status_in_progress')); ?></option>
          <option value="done"><?php echo htmlspecialchars(t('task_status_done')); ?></option>
        </select>
      </div>

      <div id="task-detail-notify" class="hidden flex gap-2 mb-4">
        <button id="task-notify-sms-btn" class="flex-1 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('task_send_sms')); ?></button>
        <button id="task-notify-push-btn" class="flex-1 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('task_send_push')); ?></button>
      </div>

      <h3 class="text-sm font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars(t('task_comments')); ?></h3>
      <div id="task-detail-comments" class="space-y-2 mb-3"></div>

      <form id="task-comment-form" class="flex gap-2">
        <input id="task-comment-input" type="text" placeholder="<?php echo htmlspecialchars(t('task_comment_placeholder')); ?>"
               class="flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
        <button type="submit" class="rounded-xl bg-gray-900 text-white px-4 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('task_send_comment')); ?></button>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
    window.IS_TASK_MANAGER = <?php echo json_encode($isManager); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/tasks.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
