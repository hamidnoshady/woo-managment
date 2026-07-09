/**
 * Tasks page: list, create/edit sheet. Detail/comments/notify (Task 16) and
 * push opt-in/Jalali date input (Task 17) extend this same file.
 */

let currentTasks = [];
let assignableUsers = [];

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

async function loadAssignableUsers() {
  if (!window.IS_TASK_MANAGER) return;
  try {
    const data = await App.api('/api/site-users.php');
    assignableUsers = data.items;
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function renderAssigneeCheckboxes(selectedIds = []) {
  const container = document.getElementById('task-assignees-list');
  container.innerHTML = '';
  assignableUsers.forEach((u) => {
    const label = document.createElement('label');
    label.className = 'flex items-center gap-2 text-sm text-gray-700';
    const checked = selectedIds.includes(u.id) ? 'checked' : '';
    label.innerHTML = `<input type="checkbox" class="task-assignee-checkbox h-4 w-4 rounded border-gray-300" value="${u.id}" ${checked}> ${escapeHtml(u.name || u.phone)}`;
    container.appendChild(label);
  });
}

function priorityBadgeClass(priority) {
  if (priority === 'high') return 'bg-red-100 text-red-700';
  if (priority === 'low') return 'bg-gray-100 text-gray-600';
  return 'bg-amber-100 text-amber-700';
}

function renderTaskCard(task) {
  const div = document.createElement('div');
  div.className = 'task-card bg-white rounded-2xl border border-gray-100 p-4 cursor-pointer';
  div.dataset.id = task.id;

  const assigneeNames = task.assignees.map((a) => escapeHtml(a.name || a.phone)).join(', ');

  div.innerHTML = `
    <div class="flex items-start justify-between gap-2">
      <h3 class="text-sm font-semibold text-gray-900">${escapeHtml(task.title)}</h3>
      <span class="text-xs px-2 py-0.5 rounded-full ${priorityBadgeClass(task.priority)}">${t('task_priority_' + task.priority)}</span>
    </div>
    <p class="text-xs text-gray-500 mt-1">${t('task_status_' + task.status)}${task.due_date ? ' · ' + escapeHtml(task.due_date) : ''}</p>
    <p class="text-xs text-gray-400 mt-1">${assigneeNames}</p>
  `;

  div.addEventListener('click', () => openTaskDetail(task.id));
  return div;
}

async function loadTasks() {
  document.getElementById('loading').classList.remove('hidden');
  document.getElementById('task-list').classList.add('hidden');
  document.getElementById('empty-state').classList.add('hidden');

  try {
    const data = await App.api('/api/tasks.php');
    currentTasks = data.items;

    const list = document.getElementById('task-list');
    list.innerHTML = '';
    currentTasks.forEach((task) => list.appendChild(renderTaskCard(task)));

    document.getElementById('loading').classList.add('hidden');
    if (currentTasks.length === 0) {
      document.getElementById('empty-state').classList.remove('hidden');
    } else {
      list.classList.remove('hidden');
    }
  } catch (e) {
    document.getElementById('loading').classList.add('hidden');
    App.toast(e.message, 'error');
  }
}

function openTaskSheet(task = null) {
  document.getElementById('task-sheet-title').textContent = task ? t('edit_task') : t('add_task');
  document.getElementById('task-id').value = task ? task.id : '';
  document.getElementById('task-title').value = task ? task.title : '';
  document.getElementById('task-description').value = task ? task.description : '';
  document.getElementById('task-priority').value = task ? task.priority : 'medium';
  renderAssigneeCheckboxes(task ? task.assignees.map((a) => a.id) : []);
  document.getElementById('task-sheet').classList.remove('hidden');
}

function closeTaskSheet() {
  document.getElementById('task-sheet').classList.add('hidden');
}

let currentDetailTaskId = null;

async function loadComments(taskId) {
  const container = document.getElementById('task-detail-comments');
  container.innerHTML = '';
  const data = await App.api(`/api/task-comments.php?task_id=${taskId}`);
  data.items.forEach((c) => {
    const div = document.createElement('div');
    div.className = 'text-xs bg-gray-50 rounded-xl px-3 py-2';
    div.innerHTML = `<span class="font-medium text-gray-700">${escapeHtml(c.user_name)}</span>: <span class="text-gray-600">${escapeHtml(c.body)}</span>`;
    container.appendChild(div);
  });
}

async function openTaskDetail(taskId) {
  const task = currentTasks.find((t2) => t2.id === taskId);
  if (!task) return;

  currentDetailTaskId = taskId;

  document.getElementById('task-detail-title').textContent = task.title;
  document.getElementById('task-detail-description').textContent = task.description;
  document.getElementById('task-detail-due').textContent = task.due_date ? `${t('task_due_date')}: ${task.due_date}` : '';
  document.getElementById('task-detail-assignees').textContent = `${t('task_assignees_label')}: ${task.assignees.map((a) => a.name || a.phone).join(', ')}`;
  document.getElementById('task-detail-status').value = task.status;
  document.getElementById('task-detail-notify').classList.toggle('hidden', !window.IS_TASK_MANAGER);

  await loadComments(taskId);

  document.getElementById('task-detail-sheet').classList.remove('hidden');
}

function closeTaskDetail() {
  document.getElementById('task-detail-sheet').classList.add('hidden');
  currentDetailTaskId = null;
}

async function sendNotify(channel) {
  try {
    const data = await App.api('/api/task-notify.php', {
      method: 'POST',
      body: JSON.stringify({ id: currentDetailTaskId, channel }),
    });
    const allOk = data.results.every((r) => r.ok);
    App.toast(allOk ? t('task_notify_sent') : t('task_notify_failed'), allOk ? 'success' : 'error');
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function bindDetailEvents() {
  document.getElementById('task-detail-close').addEventListener('click', closeTaskDetail);
  document.getElementById('task-detail-overlay').addEventListener('click', closeTaskDetail);

  document.getElementById('task-detail-status').addEventListener('change', async (event) => {
    try {
      await App.api('/api/task-update.php', {
        method: 'POST',
        body: JSON.stringify({ id: currentDetailTaskId, status: event.target.value }),
      });
      loadTasks();
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });

  document.getElementById('task-notify-sms-btn').addEventListener('click', () => sendNotify('sms'));
  document.getElementById('task-notify-push-btn').addEventListener('click', () => sendNotify('push'));

  document.getElementById('task-comment-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = document.getElementById('task-comment-input');
    const body = input.value.trim();
    if (!body) return;

    try {
      await App.api('/api/task-comments.php', {
        method: 'POST',
        body: JSON.stringify({ task_id: currentDetailTaskId, body }),
      });
      input.value = '';
      await loadComments(currentDetailTaskId);
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });
}

function bindEvents() {
  bindDetailEvents();

  const addFab = document.getElementById('add-fab');
  if (addFab) {
    addFab.addEventListener('click', () => openTaskSheet());
  }

  document.getElementById('task-sheet-close').addEventListener('click', closeTaskSheet);
  document.getElementById('task-overlay')?.addEventListener('click', closeTaskSheet);

  document.getElementById('task-form').addEventListener('submit', async (event) => {
    event.preventDefault();

    const id = document.getElementById('task-id').value;
    const assigneeIds = Array.from(document.querySelectorAll('.task-assignee-checkbox:checked')).map((el) => parseInt(el.value, 10));

    const payload = {
      title: document.getElementById('task-title').value,
      description: document.getElementById('task-description').value,
      priority: document.getElementById('task-priority').value,
      assignee_ids: assigneeIds,
    };

    try {
      if (id) {
        payload.id = parseInt(id, 10);
        await App.api('/api/task-update.php', { method: 'POST', body: JSON.stringify(payload) });
      } else {
        await App.api('/api/tasks.php', { method: 'POST', body: JSON.stringify(payload) });
      }
      closeTaskSheet();
      loadTasks();
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  bindEvents();
  loadAssignableUsers();
  loadTasks();
});
