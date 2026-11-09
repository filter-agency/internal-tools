/**
 * Filter internal-tools time logger for Freshdesk.
 *
 * Renders a Log Time widget in the ticket sidebar. Stores a per-agent personal
 * access token in Freshdesk's instance storage, calls the internal-tools API
 * via request templates declared in config/requests.json, and (optionally)
 * adds a private note back on the ticket recording the time logged.
 */

let client;
const noop = () => null;
const state = {
  token: null,
  user: null,
  ticket: null,
  projects: [],
  selectedProjectId: null,
  selectedTaskId: null,
  selectedAsanaTaskGid: '',
  iparams: { internal_tools_url: '' },
  running: null,
};

const $ = (id) => document.getElementById(id);
const show = (id) => $(id).classList.remove('hidden');
const hide = (id) => $(id).classList.add('hidden');

function showState(name) {
  ['loading', 'connect', 'form'].forEach((s) => hide(s));
  show(name);
}

function setStatus(message, kind) {
  const el = $('status');
  if (!message) { hide('status'); return; }
  el.textContent = message;
  el.className = `status ${kind || 'success'}`;
  show('status');
}

app.initialized()
  .then(async (c) => {
    client = c;
    state.iparams = await client.iparams.get();
    $('token-link').href = `${state.iparams.internal_tools_url.replace(/\/$/, '')}/profile/api-tokens`;

    state.ticket = (await client.data.get('ticket')).ticket;
    const stored = await safeDbGet('user_token');
    if (stored && stored.token) {
      state.token = stored.token;
      try { await loadAfterConnect(); }
      catch (err) { console.warn('Stored token failed; reconnecting.', err); state.token = null; showState('connect'); }
    } else {
      showState('connect');
    }

    bindEvents();
  })
  .catch((err) => {
    console.error('Init failed', err);
    showState('connect');
    $('connect-error').textContent = 'Could not initialise the app. Reload the page.';
    show('connect-error');
  });

async function safeDbGet(key) {
  try { return await client.db.get(key); }
  catch { return null; }
}

function bindEvents() {
  $('connect-btn').addEventListener('click', onConnect);
  $('disconnect').addEventListener('click', (e) => { e.preventDefault(); onDisconnect(); });
  $('project-select').addEventListener('change', onProjectChange);
  $('task-select').addEventListener('change', onTaskChange);
  $('asana-select').addEventListener('change', (e) => { state.selectedAsanaTaskGid = e.target.value; });
  $('hours-input').addEventListener('input', updateSaveLabel);
  $('save-btn').addEventListener('click', onSave);
  $('cancel-btn').addEventListener('click', resetForm);
  $('stop-btn').addEventListener('click', onStopTimer);
  $('token-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') onConnect(); });
}

async function onConnect() {
  const token = $('token-input').value.trim();
  if (!token) return;
  hide('connect-error');
  state.token = token;
  try {
    await loadAfterConnect();
    await client.db.set('user_token', { token });
  } catch (err) {
    console.error(err);
    state.token = null;
    $('connect-error').textContent = 'That token didn\'t work. Check it on the profile page and try again.';
    show('connect-error');
  }
}

async function onDisconnect() {
  state.token = null;
  await client.db.delete('user_token').catch(noop);
  resetForm();
  showState('connect');
}

async function loadAfterConnect() {
  showState('loading');
  const me = await invoke('getMe');
  state.user = me;
  $('agent-name').textContent = `Logged in as ${me.name}`;

  const projectsResp = await invoke('getProjects');
  state.projects = projectsResp.projects;

  const projectSelect = $('project-select');
  projectSelect.innerHTML = '<option value="">— Select a project —</option>';
  // Group options by client_name for the dropdown.
  const grouped = {};
  state.projects.forEach((p) => {
    const key = p.client_name || '—';
    if (!grouped[key]) grouped[key] = [];
    grouped[key].push(p);
  });
  Object.keys(grouped).sort().forEach((clientName) => {
    const og = document.createElement('optgroup');
    og.label = clientName;
    grouped[clientName].forEach((p) => {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.name;
      og.appendChild(opt);
    });
    projectSelect.appendChild(og);
  });

  // Pre-fill date and note from the ticket.
  $('date-input').value = todayIso();
  $('note-input').value = defaultNote();

  // If a timer is already running, surface it.
  await refreshRunningTimer();

  showState('form');
  updateSaveLabel();
}

async function refreshRunningTimer() {
  try {
    const resp = await invoke('getRunningTimer');
    state.running = resp.running;
    if (state.running) {
      const proj = state.projects.find((p) => p.id === state.running.project_id);
      const projName = proj ? proj.name : 'a project';
      $('running-label').textContent = projName;
      show('running-banner');
    } else {
      hide('running-banner');
    }
  } catch (err) {
    console.warn('Could not fetch running timer', err);
  }
}

function onProjectChange(e) {
  state.selectedProjectId = e.target.value ? Number(e.target.value) : null;
  state.selectedTaskId = null;
  state.selectedAsanaTaskGid = '';

  const project = currentProject();
  const taskSelect = $('task-select');
  taskSelect.innerHTML = '<option value="">— Select a task —</option>';
  if (!project) {
    taskSelect.disabled = true;
    hide('asana-row');
    return;
  }

  taskSelect.disabled = false;
  // Billable first, then non-billable.
  const billable = project.tasks.filter((t) => t.is_billable);
  const nonBillable = project.tasks.filter((t) => !t.is_billable);
  const appendGroup = (label, list) => {
    if (list.length === 0) return;
    const og = document.createElement('optgroup');
    og.label = label;
    list.forEach((t) => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.name;
      og.appendChild(opt);
    });
    taskSelect.appendChild(og);
  };
  appendGroup('Billable', billable);
  appendGroup('Non-billable', nonBillable);

  // Asana picker visibility.
  const asanaSelect = $('asana-select');
  asanaSelect.innerHTML = '<option value="">— Select an Asana task —</option>';
  if (project.asana_project_gids?.length) {
    project.asana_tasks.forEach((t) => {
      const opt = document.createElement('option');
      opt.value = t.gid;
      opt.textContent = t.name;
      asanaSelect.appendChild(opt);
    });
    show('asana-row');
  } else {
    hide('asana-row');
  }
}

function onTaskChange(e) {
  state.selectedTaskId = e.target.value ? Number(e.target.value) : null;
}

function updateSaveLabel() {
  const hasHours = $('hours-input').value.trim() !== '';
  $('save-btn').textContent = hasHours ? 'Save entry' : 'Start timer';
}

function currentProject() {
  return state.projects.find((p) => p.id === state.selectedProjectId) || null;
}

function validateForSubmission() {
  if (!state.selectedProjectId) return 'Pick a project first.';
  if (!state.selectedTaskId) return 'Pick a task first.';
  const project = currentProject();
  if (project && project.asana_project_gids?.length && project.asana_task_required && !state.selectedAsanaTaskGid) {
    return 'This project is Asana-linked — pick an Asana task.';
  }
  return null;
}

function buildSubmissionBody(isTimer) {
  const body = {
    project_id: state.selectedProjectId,
    task_id: state.selectedTaskId,
    spent_on: $('date-input').value || todayIso(),
    notes: $('note-input').value.trim() || null,
    asana_task_gid: state.selectedAsanaTaskGid || null,
  };
  if (!isTimer) body.hours = $('hours-input').value.trim();
  return body;
}

async function onSave() {
  const validationError = validateForSubmission();
  if (validationError) {
    setStatus(validationError, 'error');
    return;
  }

  const isTimer = $('hours-input').value.trim() === '';
  const body = buildSubmissionBody(isTimer);

  $('save-btn').disabled = true;
  setStatus(null);
  try {
    await invoke(isTimer ? 'startTimer' : 'createEntry', body);
    setStatus(isTimer ? 'Timer started.' : 'Entry saved.', 'success');
    await refreshRunningTimer();
    if (!isTimer) {
      $('hours-input').value = '';
      updateSaveLabel();
    }
  } catch (err) {
    console.error(err);
    setStatus(extractErrorMessage(err), 'error');
  } finally {
    $('save-btn').disabled = false;
  }
}

async function onStopTimer() {
  $('stop-btn').disabled = true;
  try {
    await invoke('stopTimer');
    setStatus('Timer stopped and saved.', 'success');
    await refreshRunningTimer();
  } catch (err) {
    setStatus(extractErrorMessage(err), 'error');
  } finally {
    $('stop-btn').disabled = false;
  }
}

function currentTask() {
  const project = currentProject();
  return project ? project.tasks.find((t) => t.id === state.selectedTaskId) : null;
}

function defaultNote() {
  if (!state.ticket) return '';
  const t = state.ticket;
  const url = `${window.location.origin || ''}/a/tickets/${t.id}`.replace('http://', 'https://');
  return `[#${t.id}] ${t.subject || 'Support ticket'} — ${url}`;
}

function todayIso() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function resetForm() {
  $('project-select').value = '';
  $('task-select').innerHTML = '<option value="">— Select project first —</option>';
  $('task-select').disabled = true;
  hide('asana-row');
  $('hours-input').value = '';
  $('note-input').value = defaultNote();
  $('date-input').value = todayIso();
  state.selectedProjectId = null;
  state.selectedTaskId = null;
  state.selectedAsanaTaskGid = '';
  setStatus(null);
  updateSaveLabel();
}

async function invoke(template, body) {
  const opts = { context: { token: state.token } };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const resp = await client.request.invokeTemplate(template, opts);
  return JSON.parse(resp.response || 'null');
}

function extractErrorMessage(err) {
  try {
    const status = err.status;
    const parsed = err.response ? JSON.parse(err.response) : {};
    if (parsed.error) return `${parsed.error} (HTTP ${status})`;
    return `Request failed (HTTP ${status})`;
  } catch {
    return 'Request failed.';
  }
}

