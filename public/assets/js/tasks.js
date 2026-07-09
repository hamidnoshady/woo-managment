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

function bindEvents() {
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
