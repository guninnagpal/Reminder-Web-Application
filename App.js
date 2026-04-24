// ── CONFIG ── (update these with your PHP backend URL)
const API_BASE = 'api/'; // Your PHP backend path

// ── STATE ──
let allReminders = [];
let selectedColor = '7';

// ── INIT ──
window.onload = () => {
  setDefaultDate();
  checkSession();

  // Meet toggle preview
  const meetToggle = document.getElementById('meet-toggle');
  const meetPreview = document.getElementById('meet-preview');
  if (meetToggle) {
    meetToggle.addEventListener('change', () => {
      meetPreview.style.display = meetToggle.checked ? 'flex' : 'none';
    });
  }
};

function setDefaultDate() {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  document.getElementById('task-date').value = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}`;
  document.getElementById('task-time').value = `${pad(now.getHours()+1)}:00`;
}

// ── AUTH ──
async function checkSession() {
  try {
    const res = await fetch(API_BASE + 'auth.php?action=status');
    const data = await res.json();
    if (data.logged_in) {
      showApp(data.user);
      loadReminders();
    } else {
      showLogin();
    }
  } catch {
    showLogin();
  }
}

function loginWithGoogle() {
  window.location.href = API_BASE + 'auth.php?action=login';
}

function logout() {
  window.location.href = API_BASE + 'auth.php?action=logout';
}

function showLogin() {
  document.getElementById('login-screen').classList.add('active');
  document.getElementById('app-screen').classList.remove('active');
}

function showApp(user) {
  document.getElementById('login-screen').classList.remove('active');
  document.getElementById('app-screen').classList.add('active');
  if (user) {
    document.getElementById('user-name').textContent = user.name || 'User';
    document.getElementById('user-email').textContent = user.email || '';
    if (user.picture) {
      document.getElementById('user-avatar').src = user.picture;
    }
  }
}

// ── TABS ──
function showTab(name, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  if (el) el.classList.add('active');
  if (name === 'upcoming') loadReminders();
  event.preventDefault();
}

// ── REMINDERS ──
async function loadReminders() {
  try {
    const res = await fetch(API_BASE + 'reminders.php?action=list');
    const data = await res.json();
    if (data.success) {
      allReminders = data.reminders || [];
      renderDashboard();
      renderUpcoming(allReminders);
    }
  } catch (e) {
    console.error('Load error:', e);
  }
}

function renderDashboard() {
  const now = new Date();
  const today = allReminders.filter(r => {
    const d = new Date(r.start);
    return d.toDateString() === now.toDateString();
  });
  const weekEnd = new Date(now); weekEnd.setDate(weekEnd.getDate() + 7);
  const thisWeek = allReminders.filter(r => {
    const d = new Date(r.start);
    return d >= now && d <= weekEnd;
  });

  document.getElementById('stat-today').textContent = today.length;
  document.getElementById('stat-week').textContent = thisWeek.length;
  document.getElementById('stat-total').textContent = allReminders.length;

  const upcoming = allReminders.slice(0, 5);
  const el = document.getElementById('dashboard-list');
  if (upcoming.length === 0) {
    el.innerHTML = '<div class="empty-state">No reminders yet. Add your first one!</div>';
  } else {
    el.innerHTML = upcoming.map(r => reminderCard(r)).join('');
  }
}

function renderUpcoming(list) {
  const el = document.getElementById('upcoming-list');
  if (list.length === 0) {
    el.innerHTML = '<div class="empty-state">No reminders found.</div>';
  } else {
    el.innerHTML = list.map(r => reminderCard(r)).join('');
  }
}

function reminderCard(r) {
  // RFC3339 format aata hai PHP se — directly parse karo
  const start = new Date(r.start);

  const dateStr = start.toLocaleDateString('en-IN', {
    weekday: 'short', month: 'short', day: 'numeric',
    timeZone: 'Asia/Kolkata'
  });
  const timeStr = start.toLocaleTimeString('en-IN', {
    hour: '2-digit', minute: '2-digit', hour12: true,
    timeZone: 'Asia/Kolkata'
  });
  const meetBtn = r.meet_link
    ? `<a href="${r.meet_link}" target="_blank" class="meet-link">🎥 Join Meet</a>`
    : '';
  return `
    <div class="reminder-card" data-id="${r.id}">
      <div class="reminder-icon">${r.meet_link ? '🎥' : '📌'}</div>
      <div class="reminder-info">
        <div class="reminder-title">${escHtml(r.title)}</div>
        <div class="reminder-time">📅 ${dateStr} &nbsp; ⏰ ${timeStr}</div>
        ${r.description ? `<div class="reminder-desc">${escHtml(r.description)}</div>` : ''}
        ${meetBtn}
      </div>
      <button class="delete-btn" onclick="deleteReminder('${r.id}')" title="Delete">🗑</button>
    </div>`;
}

function filterReminders() {
  const q = document.getElementById('search-box').value.toLowerCase();
  const filtered = allReminders.filter(r =>
    r.title.toLowerCase().includes(q) || (r.description || '').toLowerCase().includes(q)
  );
  renderUpcoming(filtered);
}

// ── SUBMIT ──
async function submitReminder() {
  const title = document.getElementById('task-title').value.trim();
  const desc  = document.getElementById('task-desc').value.trim();
  const date  = document.getElementById('task-date').value;
  const time  = document.getElementById('task-time').value;
  const dur   = document.getElementById('task-duration').value;
  const rem   = document.getElementById('task-reminder').value;
  const msg   = document.getElementById('form-msg');

  const meet   = document.getElementById('meet-toggle')?.checked || false;

  if (!title || !date || !time) {
    showMsg(msg, '⚠️ Please fill in title, date and time.', 'error');
    return;
  }

  const btn = document.querySelector('.submit-btn');
  btn.disabled = true;
  document.getElementById('submit-label').textContent = '⏳ Adding to Calendar...';
  msg.className = 'form-msg'; msg.textContent = '';

  try {
    const res = await fetch(API_BASE + 'reminders.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create', title, description: desc, date, time, duration: dur, reminder_minutes: rem, color_id: selectedColor, add_meet: meet })
    });
    const data = await res.json();
    if (data.success) {
      let successMsg = '✅ Added to Google Calendar!';
      if (data.meet_link) {
        successMsg += ` <a href="${data.meet_link}" target="_blank" class="meet-link">🎥 Join Meet</a>`;
        msg.innerHTML = successMsg;
        msg.className = 'form-msg success';
      } else {
        showMsg(msg, successMsg, 'success');
      }
      showToast(meet ? '🎥 Event + Meet link created!' : 'Reminder added!', 'success');
      clearForm();
      loadReminders();
    } else {
      showMsg(msg, '❌ ' + (data.error || 'Failed to add reminder.'), 'error');
    }
  } catch {
    showMsg(msg, '❌ Network error. Check your connection.', 'error');
  } finally {
    btn.disabled = false;
    document.getElementById('submit-label').textContent = '📅 Add to Google Calendar';
  }
}

async function deleteReminder(id) {
  if (!confirm('Remove this reminder from Google Calendar?')) return;
  try {
    const res = await fetch(API_BASE + 'reminders.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', event_id: id })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Reminder deleted.', 'success');
      loadReminders();
    } else {
      showToast('Failed to delete: ' + (data.error || ''), 'error');
    }
  } catch {
    showToast('Network error.', 'error');
  }
}

function clearForm() {
  document.getElementById('task-title').value = '';
  document.getElementById('task-desc').value = '';
  setDefaultDate();
}

function selectColor(el) {
  document.querySelectorAll('.color-dot').forEach(d => d.classList.remove('selected'));
  el.classList.add('selected');
  selectedColor = el.dataset.val;
}

// ── UTILS ──
function showMsg(el, text, type) {
  el.textContent = text;
  el.className = 'form-msg ' + type;
}

function showToast(text, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = text;
  t.className = 'toast ' + type + ' show';
  setTimeout(() => t.classList.remove('show'), 3500);
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}