/* Grading System front end (vanilla JS) */
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let CSRF = $('meta[name=csrf]').content;

const LABEL = { quiz: 'Quiz', boardwork: 'Boardwork / Recitation', project: 'Project', midterm: 'Midterm Exam', final: 'Final Exam' };
const TABS = [
  ['students', 'Students'], ['attendance', 'Attendance'], ['quiz', 'Quizzes'],
  ['boardwork', 'Boardworks / Recitation'], ['project', 'Projects'],
  ['midterm', 'Midterm Exam'], ['final', 'Final Exam'], ['summary', 'Grade Summary']
];
const S = { section: null, tab: 'students', timer: null, weights: {}, registerMode: false };

/* ---------- helpers ---------- */
async function api(action, data = null, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const opt = data ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(data) } : {};
  const res = await fetch('api.php?' + qs, opt);
  let json;
  try { json = await res.json(); } catch (e) { throw new Error('Server error. Check Apache/PHP.'); }
  if (!res.ok || json.error) {
    if (res.status === 401 && action !== 'login' && action !== 'me') { showLogin(); }
    throw new Error(json.error || 'Request failed');
  }
  return json;
}
function toast(msg, type = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + type; t.textContent = msg;
  $('#toast').appendChild(t);
  setTimeout(() => t.remove(), 3200);
}
function formModal({ title, fields, submitText = 'Save', onSubmit }) {
  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  const fieldHtml = f => f.type === 'select'
    ? `<label>${esc(f.label)}<select name="${f.name}">${f.options.map(o => `<option value="${esc(o.v)}" ${String(o.v) === String(f.value ?? '') ? 'selected' : ''}>${esc(o.l)}</option>`).join('')}</select></label>`
    : f.type === 'textarea'
    ? `<label>${esc(f.label)}<textarea name="${f.name}" placeholder="${esc(f.placeholder || '')}">${esc(f.value || '')}</textarea></label>`
    : `<label>${esc(f.label)}<input name="${f.name}" type="${f.type || 'text'}" value="${esc(f.value ?? '')}" ${f.attrs || ''}></label>`;
  wrap.innerHTML = `<form class="modal"><h3>${esc(title)}</h3>${fields.map(fieldHtml).join('')}
    <div class="modal-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary">${esc(submitText)}</button></div></form>`;
  document.body.appendChild(wrap);
  const form = $('form', wrap), close = () => wrap.remove();
  $('[data-close]', wrap).onclick = close;
  wrap.addEventListener('mousedown', e => { if (e.target === wrap) close(); });
  form.onsubmit = async e => {
    e.preventDefault();
    try { await onSubmit(Object.fromEntries(new FormData(form))); close(); }
    catch (err) { toast(err.message, 'error'); }
  };
  const first = $('input,textarea', form); if (first) first.focus();
}
const fmtDate = d => new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
const fmtFull = d => new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
const pctTxt = v => v === null || v === undefined ? '—' : Number(v).toFixed(2) + '%';
const num = v => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; };
const today = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
// Student photo comes from the biometric database through photo.php; the initial shows if there is no photo.
const avatar = s => `<span class="avatar" title="${esc(s.first_name)} ${esc(s.last_name)}"><b>${esc(String(s.first_name || '?').charAt(0).toUpperCase())}</b><img src="photo.php?student_id=${s.id}" alt="" loading="lazy" onerror="this.remove()"></span>`;
const nameOf = s => `<span class="who-cell">${avatar(s)}<span>${esc(s.last_name)}, ${esc(s.first_name)}</span></span>`;
// Click a photo to enlarge it
document.addEventListener('click', e => {
  const im = e.target.closest('.avatar img');
  if (!im) return;
  const w = document.createElement('div');
  w.className = 'modal-backdrop';
  w.innerHTML = `<div class="photo-view"><img src="${im.src}" alt=""><p>${esc(im.closest('.avatar').title)}</p></div>`;
  w.onclick = () => w.remove();
  document.body.appendChild(w);
});

/* ---------- views ---------- */
function hideAll() { ['#view-login', '#view-sections', '#view-section', '#view-admin', '#view-settings'].forEach(s => $(s).hidden = true); }
function showLogin() {
  clearInterval(S.timer);
  hideAll(); $('#topbar').hidden = true; $('#view-login').hidden = false;
  $('#auth-title').textContent = S.registerMode ? 'Sign up' : 'Log in';
  $('#auth-note').textContent = S.registerMode ? 'Create your teacher account.' : 'Welcome back. Log in to manage your classes.';
  $('#fn-wrap').hidden = !S.registerMode;
  $('#pw2-wrap').hidden = !S.registerMode;
  $('#invite-wrap').hidden = !S.registerMode;
  $('#fn-wrap input').required = S.registerMode;
  $('#pw2-wrap input').required = S.registerMode;
  $('#invite-wrap input').required = S.registerMode;
  $('#auth-btn').textContent = S.registerMode ? 'Create teacher account' : 'Log in';
  const tg = $('#auth-toggle');
  tg.parentElement.hidden = false;
  tg.textContent = S.registerMode ? 'Already have an account? Log in' : "Don't have an account? Sign up";
}
async function boot() {
  const me = await api('me');
  S.weights = me.weights;
  if (me.teacher) {
    const role = me.teacher.role;
    const admin = role === 'admin' || role === 'admin_teacher';
    const dual = role === 'admin_teacher';
    S.isAdmin = admin;
    S.isAdminTeacher = dual;
    S.canSetWeights = role === 'teacher' || role === 'admin_teacher';
    $('#nav-settings').hidden = !S.canSetWeights;
    $('#topbar').hidden = false; $('#who').textContent = me.teacher.full_name;
    $('#nav-sections').hidden = role === 'admin';
    $('#nav-admin').hidden = !admin;
    $('#role-badge').hidden = !admin;
    $('#role-badge').textContent = dual ? 'Admin + Teacher' : 'Admin';
    $('#bio-sync').hidden = !admin;
    $('#bio-import').hidden = !admin;
    role === 'admin' ? showAdmin() : showSections();
  }
  else { S.firstRun = !me.has_teacher; S.allowSignup = me.allow_signup; S.registerMode = S.firstRun; showLogin(); }
}
$('#auth-form').onsubmit = async e => {
  e.preventDefault();
  const d = Object.fromEntries(new FormData(e.target));
  if (S.registerMode && d.password !== d.password2) return toast('Passwords do not match.', 'error');
  try {
    const r = await api(S.registerMode ? 'register' : 'login', d);
    CSRF = r.csrf; e.target.reset(); await boot();
  } catch (err) { toast(err.message, 'error'); }
};
$('#nav-logout').onclick = async () => { await api('logout', {}); S.section = null; await boot(); };
$('#nav-sections').onclick = showSections;
$('#nav-admin').onclick = showAdmin;
$('#nav-settings').onclick = showSettings;
$('#auth-toggle').onclick = e => { e.preventDefault(); S.registerMode = !S.registerMode; showLogin(); };
$('#back').onclick = showSections;


/* ---------- Instructor grading settings ---------- */
const WEIGHT_KEYS = ['attendance', 'quiz', 'boardwork', 'project', 'midterm', 'final'];

async function showSettings() {
  if (!S.canSetWeights) return;
  clearInterval(S.timer);
  hideAll();
  $('#view-settings').hidden = false;

  try {
    const d = await api('weights_get');
    S.weights = d.weights;

    const p = await api('passing_rates_get');
    S.passingRates = p.passing_rates;

    renderWeightSettings();
    renderPassingRates();
  } catch (err) {
    toast(err.message, 'error');
  }
}

function renderWeightSettings() {
  WEIGHT_KEYS.forEach(key => {
    $('#weight-' + key).value = S.weights[key];
  });
  updateWeightsTotal();
}

function renderPassingRates() {
  ['quiz', 'midterm', 'final'].forEach(key => {
    $('#passing-' + key).value = S.passingRates[key];
  });
}

function updateWeightsTotal() {
  const values = WEIGHT_KEYS.map(key => $('#weight-' + key).value.trim());
  const valid = values.every(v => /^\d+$/.test(v));
  const total = valid ? values.reduce((sum, v) => sum + Number(v), 0) : null;
  $('#weights-total').textContent = total === null
    ? 'Total: Please enter six whole numbers'
    : 'Total: ' + total + '%';
  $('#weights-total').style.color = total === 100 ? 'var(--primary)' : 'var(--bad)';
}

WEIGHT_KEYS.forEach(key => {
  $('#weight-' + key).addEventListener('input', updateWeightsTotal);
});

$('#weights-form').onsubmit = async e => {
  e.preventDefault();
  if (!S.canSetWeights) return;

  const weights = {};
  for (const key of WEIGHT_KEYS) {
    const value = $('#weight-' + key).value.trim();
    if (!/^\d+$/.test(value) || Number(value) > 100) {
      toast('Enter whole numbers from 0 to 100 for all categories.', 'error');
      return;
    }
    weights[key] = Number(value);
  }

  if (Object.values(weights).reduce((a, b) => a + b, 0) !== 100) {
    toast('The six grading weights must total exactly 100%.', 'error');
    return;
  }

  const button = $('#weights-form button[type="submit"]');
  button.disabled = true;
  try {
    const result = await api('weights_save', { weights });
    S.weights = result.weights;
    renderWeightSettings();
    toast('Grading weights saved successfully.');
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    button.disabled = false;
  }
};

$('#passing-rates-form').onsubmit = async e => {
  e.preventDefault();

  if (!S.canSetWeights) return;

  const passing_rates = {};

  for (const key of ['quiz', 'midterm', 'final']) {
    const value = $('#passing-' + key).value.trim();

    if (!/^\d+$/.test(value) || Number(value) > 100) {
      toast('Enter whole-number passing rates from 0 to 100.', 'error');
      return;
    }

    passing_rates[key] = Number(value);
  }

  const button = $('#passing-rates-form button[type="submit"]');
  button.disabled = true;

  try {
    const result = await api('passing_rates_save', { passing_rates });

    S.passingRates = result.passing_rates;
    renderPassingRates();

    toast('Passing rates saved successfully.');
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    button.disabled = false;
  }
};

/* ---------- Admin dashboard ---------- */
S.adminOpen = new Set();
async function showAdmin() {
  clearInterval(S.timer);
  hideAll(); $('#view-admin').hidden = false;
  try { S.admin = await api('admin_overview'); renderAdmin(); } catch (err) { toast(err.message, 'error'); }
}
$('#admin-search').oninput = () => renderAdmin();
function renderAdmin() {
  const d = S.admin; if (!d) return;
  const q = $('#admin-search').value.trim().toLowerCase();
  $('#admin-stats').innerHTML = [['Instructors', d.totals.teachers], ['Classes', d.totals.sections], ['Enrolled students', d.totals.students]]
    .map(([l, v]) => `<div class="card stat"><div class="stat-n">${v}</div><div class="muted">${l}</div></div>`).join('');
  const hit = (...v) => v.some(x => String(x || '').toLowerCase().includes(q));
  const html = d.teachers.map(t => {
    const nameHit = !q || hit(t.full_name, t.username);
    const secs = t.sections.filter(s => nameHit || hit(s.year_level, s.section_name, s.year_level + ' ' + s.section_name, s.subject_code, s.subject_name, s.school_year));
    if (!secs.length && !nameHit) return '';
    const stuTotal = t.sections.reduce((n, s) => n + (+s.student_count), 0);
    const active = Number(t.is_active) === 1;
    return `<details class="teacher" data-t="${t.id}" ${q || S.adminOpen.has(String(t.id)) ? 'open' : ''}>
      <summary><strong>${esc(t.full_name)}</strong><span class="muted">@${esc(t.username)}</span>
        <span class="count" style="margin-left:auto;background:#e9efff;color:var(--primary);padding:.15rem .6rem;border-radius:99px;font-size:.85rem;font-weight:600">${t.sections.length} class${t.sections.length == 1 ? '' : 'es'} · ${stuTotal} student${stuTotal == 1 ? '' : 's'}</span></summary>
      <div class="toolbar" style="padding:.6rem 0">
        <span class="badge">${active ? 'ACTIVE ACCOUNT' : 'INACTIVE ACCOUNT'}</span>
        <button class="btn small" data-teacher-status="${t.id}" data-next-status="${active ? 0 : 1}">${active ? 'Deactivate' : 'Reactivate'}</button>
        <button class="btn small" data-teacher-remove="${t.id}" style="background:#b42318;color:white">Remove Teacher</button>
      </div>
      ${secs.length ? `<div class="table-wrap"><table>
        <thead><tr><th>Year &amp; section</th><th>Subject</th><th>School year</th><th class="num">Students</th><th></th></tr></thead>
        <tbody>${secs.map(s => `<tr><td><strong>${esc(s.year_level)} - ${esc(s.section_name)}</strong></td>
          <td>${esc(s.subject_code)} ${esc(s.subject_name)}</td><td>${esc(s.school_year)}</td><td class="num">${s.student_count}</td>
          <td><button class="btn small" data-view="${s.id}">View students</button></td></tr>
          <tr class="stu-row" id="stu-${s.id}" hidden><td colspan="5"></td></tr>`).join('')}</tbody></table></div>`
        : '<div class="empty">No classes assigned yet.</div>'}
    </details>`;
  }).join('');
  $('#admin-list').innerHTML = html || `<div class="card empty">${d.teachers.length ? 'No match for your search.' : 'No instructors have signed up yet.'}</div>`;
}
$('#admin-list').addEventListener('toggle', e => {
  if ($('#admin-search').value.trim()) return;
  const id = e.target.dataset?.t; if (!id) return;
  e.target.open ? S.adminOpen.add(id) : S.adminOpen.delete(id);
}, true);
$('#admin-list').addEventListener('click', async e => {
    const removeBtn = e.target.closest('[data-teacher-remove]');
        if (removeBtn) {
          const id = Number(removeBtn.dataset.teacherRemove);
          const warning = 'PERMANENTLY DELETE THIS TEACHER AND ALL THEIR GRADING SYSTEM RECORDS? Sections, students, assessments, grades, class sessions, and attendance will be deleted. This cannot be undone. Biometric scan history will be preserved.';
          if (!confirm(warning)) return;
    
          removeBtn.disabled = true;
          try {
            await api('admin_teacher_remove', { id });
            toast('Teacher and associated grading records permanently deleted.');
            await showAdmin();
          } catch (err) {
            toast(err.message, 'error');
            removeBtn.disabled = false;
          }
          return;
        }

  const statusBtn = e.target.closest('[data-teacher-status]');
  if (statusBtn) {
    const id = Number(statusBtn.dataset.teacherStatus);
    const next = Number(statusBtn.dataset.nextStatus);
    const verb = next ? 'reactivate' : 'deactivate';
    const warning = next
      ? 'Reactivate this teacher account?'
      : 'Deactivate this teacher account? The teacher will no longer be able to log in, but their classes, students, grades, and attendance will be preserved.';
    if (!confirm(warning)) return;
    statusBtn.disabled = true;
    try {
      await api('admin_teacher_status', { id, is_active: next });
      toast(next ? 'Teacher account reactivated.' : 'Teacher account deactivated.');
      await showAdmin();
    } catch (err) {
      toast(err.message, 'error');
      statusBtn.disabled = false;
    }
    return;
  }

  const b = e.target.closest('[data-view]'); if (!b) return;
  const row = $('#stu-' + b.dataset.view), cell = row.firstElementChild;
  if (!row.hidden) { row.hidden = true; b.textContent = 'View students'; return; }
  row.hidden = false; b.textContent = 'Hide students';
  cell.innerHTML = '<div class="empty">Loading…</div>';
  try {
    const { students } = await api('admin_students', null, { section_id: b.dataset.view });
    cell.innerHTML = students.length ? `<table><thead><tr><th>#</th><th>Student</th><th>Student no.</th><th class="num">Fingerprint ID</th></tr></thead>
      <tbody>${students.map((s, i) => `<tr><td>${i + 1}</td><td>${nameOf(s)}</td><td>${esc(s.student_no)}</td><td class="num">${s.fingerprint_id ?? '—'}</td></tr>`).join('')}</tbody></table>`
      : '<div class="empty">No students enrolled in this class.</div>';
  } catch (err) { cell.innerHTML = `<div class="empty">${esc(err.message)}</div>`; }
});

/* ---------- Manage Students: sections ---------- */
async function showSections() {
  clearInterval(S.timer);
  hideAll(); $('#view-sections').hidden = false; S.section = null;
  if (S.isAdmin && !S.synced) { S.synced = true; try { await api('bio_sync', {}); } catch (e) { /* biometric DB offline: skip */ } }   // once per page load
  const { sections } = await api('sections_list');
  const g = $('#section-grid');
  if (!sections.length) { g.innerHTML = '<div class="card empty" style="grid-column:1/-1">No year &amp; section yet. Click “Add year &amp; section”.</div>'; return; }
  g.innerHTML = sections.map(s => `
    <div class="card sec-card" data-id="${s.id}">
      <div class="actions"><button class="btn small" data-edit="${s.id}">Edit</button><button class="btn small danger" data-del="${s.id}">Delete</button></div>
      <div class="big">${esc(s.year_level)} - ${esc(s.section_name)}</div>
      <div class="muted">${esc(s.subject_code)} ${esc(s.subject_name)}</div>
      <div class="muted">${esc(s.school_year)}</div>
      <span class="count">${s.student_count} student${s.student_count == 1 ? '' : 's'}</span>
      ${S.isAdmin && !s.bio_department_id ? '<div class="notlinked" title="Click Edit and choose a biometric department so new students are added automatically">&#9888; Not linked to biometric</div>' : ''}
    </div>`).join('');
  showUnmatched();
  g.onclick = async e => {
    const edit = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]'), card = e.target.closest('.sec-card');
    if (edit) { e.stopPropagation(); return sectionForm(sections.find(s => s.id == edit.dataset.edit)); }
    if (del) {
      e.stopPropagation();
      if (!confirm('Delete this year & section and ALL its students, attendance and grades?')) return;
      try { await api('section_delete', { id: +del.dataset.del }); showSections(); } catch (err) { toast(err.message, 'error'); }
      return;
    }
    if (card) openSection(sections.find(s => s.id == card.dataset.id));
  };
}
$('#add-section').onclick = () => sectionForm(null);
$('#bio-sync').onclick = async () => {
  try { const r = await api('bio_sync', {}); toast(`Synced ${r.students} student(s) across ${r.sections} linked section(s).`); showSections(); }
  catch (err) { toast(err.message, 'error'); }
};

/* Scans that could not be matched to any student, with the reason */
async function showUnmatched() {
  const box = $('#unmatched'); box.innerHTML = '';
  if (!S.isAdmin) return;
  try {
    const { scans } = await api('unmatched_scans');
    if (!scans.length) return;
    box.innerHTML = `<div class="card warn-card"><h3>&#9888; Recent scans not matched to a student</h3>
      <p class="muted">These fingerprints scanned but are not in any section yet, so no attendance was recorded for them. Fix the reason, then ask the student to scan again.</p>
      <div class="table-wrap"><table><thead><tr><th class="num">Fingerprint ID</th><th>Name in biometric</th><th>Last scan</th><th class="num">Scans</th><th>Why</th></tr></thead>
      <tbody>${scans.map(x => `<tr><td class="num">${x.fingerprint_id}</td><td>${esc(x.name || '—')}</td><td>${esc(x.last_scan)}</td><td class="num">${x.scans}</td>
        <td>${esc(String(x.result).replace(/^not enrolled:?\s*/, '') || 'no matching student')}</td></tr>`).join('')}</tbody></table></div></div>`;
  } catch (e) { /* panel is optional */ }
}

/* Import year & sections + students straight from the biometric database */
$('#bio-import').onclick = async () => {
  let d;
  try { d = await api('bio_departments'); } catch (err) { return toast(err.message, 'error'); }
  if (!d.departments.length) return toast('No departments found in the biometric system.', 'error');
  const guess = n => { const m = String(n).trim().match(/^(.*\S)\s+(\S+)$/); return m ? [m[1], m[2]] : [String(n).trim(), '']; };
  const wrap = document.createElement('div');
  wrap.className = 'modal-backdrop';
  wrap.innerHTML = `<form class="modal wide"><h3>Import from biometric system</h3>
    <p class="muted">Tick the departments to import. Each becomes a year &amp; section with its registered students and fingerprint IDs. Importing again only adds new students and updates existing ones. Year, section and subject are read from the department name (see the README for the format); fix anything that looks wrong.</p>
    <div class="row2">
      <label>School year / term<input name="school_year" placeholder="2026-2027"></label>
      <label>Names in the biometric system are written as
        <select name="name_format"><option value="last_first">Last name first (Dela Cruz Juan)</option><option value="first_last">First name first (Juan Dela Cruz)</option></select></label>
    </div>
    <p class="muted">A comma always means “Last, First”, whichever you choose.</p>
    <div class="table-wrap"><table><thead><tr><th></th><th>Biometric department</th><th>Year level</th><th>Section</th><th>Subject code</th><th>Subject name</th></tr></thead>
    <tbody>${d.departments.map(x => { const p = x.parsed; return `<tr data-dept="${x.id}">
      <td><input type="checkbox" class="pick" ${x.cnt ? '' : 'disabled'} ${x.cnt && !x.linked ? 'checked' : ''}></td>
      <td><strong>${esc(x.department_name)}</strong>${x.linked ? ' <span class="count">already imported</span>' : ''}<small>${x.cnt} student${x.cnt == 1 ? '' : 's'}${x.sample.length ? ' · e.g. ' + x.sample.map(esc).join('; ') : ''}</small></td>
      <td><input data-f="year_level" value="${esc(p.year_level)}"></td><td><input data-f="section_name" value="${esc(p.section_name)}"></td>
      <td><input data-f="subject_code" value="${esc(p.subject_code)}"></td><td><input data-f="subject_name" value="${esc(p.subject_name)}"></td></tr>`; }).join('')}</tbody></table></div>
    <div class="modal-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary">Import selected</button></div></form>`;
  document.body.appendChild(wrap);
  const close = () => wrap.remove();
  $('[data-close]', wrap).onclick = close;
  wrap.addEventListener('mousedown', e => { if (e.target === wrap) close(); });
  $('form', wrap).onsubmit = async e => {
    e.preventDefault();
    const f = e.target;
    const items = [...f.querySelectorAll('tr[data-dept]')].filter(r => $('.pick', r).checked).map(r => {
      const o = { department_id: +r.dataset.dept, school_year: f.school_year.value };
      r.querySelectorAll('[data-f]').forEach(i => o[i.dataset.f] = i.value);
      return o;
    });
    if (!items.length) return toast('Tick at least one department.', 'error');
    try {
      const r = await api('bio_import', { items, name_format: f.name_format.value });
      close();
      toast(`Imported ${r.students} student(s) into ${r.sections} new section(s)` + (r.skipped ? `, ${r.skipped} skipped (duplicate ID or number)` : ''), r.skipped ? 'error' : '');
      showSections();
    } catch (err) { toast(err.message, 'error'); }
  };
};
async function sectionForm(s) {
  let depts = [{ v: '', l: '— Not linked —' }];
  try { const d = await api('bio_departments'); depts = depts.concat(d.departments.map(x => ({ v: x.id, l: `${x.department_name} (${x.cnt})` }))); } catch (e) { /* biometric DB unreachable: skip */ }
  formModal({
    title: s ? 'Edit year & section' : 'Add year & section',
    fields: [
      { name: 'year_level', label: 'Year level / course (e.g. BSIT 1)', value: s?.year_level, attrs: 'required' },
      { name: 'section_name', label: 'Section (e.g. C)', value: s?.section_name, attrs: 'required' },
      { name: 'subject_code', label: 'Subject code (e.g. OS101)', value: s?.subject_code },
      { name: 'subject_name', label: 'Subject name', value: s?.subject_name },
      { name: 'school_year', label: 'School year / term', value: s?.school_year, placeholder: '2026-2027' },
      ...(S.isAdmin ? [
        { name: 'bio_department_id', type: 'select', label: 'Biometric department (new students are added automatically)', options: depts, value: s?.bio_department_id ?? '' },
        { name: 'name_format', type: 'select', label: 'Names in the biometric system are written as', value: s?.name_format || 'last_first',
          options: [{ v: 'last_first', l: 'Last name first (Dela Cruz Juan)' }, { v: 'first_last', l: 'First name first (Juan Dela Cruz)' }] }
      ] : [])
    ],
    onSubmit: async d => {
      const r = await api('section_save', { ...d, id: s?.id });
      if (r.synced) toast(`Linked. ${r.synced} student(s) synced from the biometric system.`);
      showSections();
    }
  });
}

/* ---------- One section with tabs ---------- */
function openSection(sec) {
  S.section = sec;
  hideAll(); $('#view-section').hidden = false;
  $('#sec-title').textContent = `${sec.year_level} - ${sec.section_name}`;
  $('#sec-sub').textContent = [sec.subject_code, sec.subject_name, sec.school_year].filter(Boolean).join(' • ');
  $('#tabs').innerHTML = TABS.map(([k, l]) => `<button class="tab" data-tab="${k}">${l}</button>`).join('');
  $('#tabs').onclick = e => { const t = e.target.closest('[data-tab]'); if (t) setTab(t.dataset.tab); };
  setTab('students');
}
async function setTab(t) {
  clearInterval(S.timer);
  S.tab = t;
  document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  const box = $('#tab-content');
  box.innerHTML = '<div class="empty">Loading…</div>';
  try {
    if (t === 'students') await renderStudents(box);
    else if (t === 'attendance') await renderAttendance(box);
    else if (t === 'summary') await renderSummary(box);
    else await renderAssessments(box, t);
  } catch (err) { box.innerHTML = `<div class="empty">${esc(err.message)}</div>`; }
}

/* ---------- Students tab ---------- */
async function renderStudents(box) {
  const sid = S.section.id;
  const { students } = await api('students_list', null, { section_id: sid });
  const canManageFingerprints = S.isAdminTeacher === true;
  box.innerHTML = `
    <div class="toolbar">
      <button class="btn primary" id="st-add">+ Add student</button>
      <button class="btn" id="st-imp">Bulk import</button>
      <span class="muted">${students.length} enrolled${canManageFingerprints ? ' &middot; the Fingerprint ID links a student to your biometric reader' : ''}</span>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>#</th><th>Student no.</th><th>Name</th>${canManageFingerprints ? '<th class="num">Fingerprint ID</th>' : ''}<th></th></tr></thead>
      <tbody>${students.map((s, i) => `<tr>
        <td>${i + 1}</td><td>${esc(s.student_no)}</td><td>${nameOf(s)}${s.bio_note ? `<small class="bio-note">&#9888; ${esc(s.bio_note)}</small>` : ''}</td>
        ${canManageFingerprints ? `<td class="num">${s.fingerprint_id ?? '<span class="muted">not set</span>'}</td>` : ''}
        <td><button class="btn small" data-e="${s.id}">Edit</button> <button class="btn small danger" data-d="${s.id}">Remove</button></td></tr>`).join('')
        || `<tr><td colspan="${canManageFingerprints ? 5 : 4}" class="empty">No students yet.</td></tr>`}</tbody></table></div>`;
  const form = st => formModal({
    title: st ? 'Edit student' : 'Add student',
    fields: [
      { name: 'student_no', label: 'Student no.', value: st?.student_no, attrs: 'required' },
      { name: 'last_name', label: 'Last name', value: st?.last_name, attrs: 'required' },
      { name: 'first_name', label: 'First name', value: st?.first_name, attrs: 'required' },
      ...(canManageFingerprints ? [{ name: 'fingerprint_id', label: 'Fingerprint ID (1-1000)', type: 'number', value: st?.fingerprint_id, attrs: 'min="1" max="1000"' }] : [])
    ],
    onSubmit: async d => { await api('student_save', { ...d, id: st?.id, section_id: sid }); renderStudents(box); }
  });
  $('#st-add').onclick = () => form(null);
  $('#st-imp').onclick = () => formModal({
    title: 'Bulk import students',
    fields: [{ name: 'text', type: 'textarea',
      label: canManageFingerprints ? 'One student per line: student no., last name, first name, fingerprint ID (optional)' : 'One student per line: student no., last name, first name',
      placeholder: canManageFingerprints
        ? '2026-0001, Dela Cruz, Juan, 12\\n2026-0002, Santos, Maria, 13'
        : '2026-0001, Dela Cruz, Juan\\n2026-0002, Santos, Maria' }],
    submitText: 'Import',
    onSubmit: async d => {
      const r = await api('students_import', { section_id: sid, text: d.text });
      toast(`Imported ${r.added}` + (r.skipped.length ? `, skipped line(s): ${r.skipped.join(', ')}` : ''), r.skipped.length ? 'error' : '');
      renderStudents(box);
    }
  });
  box.querySelector('tbody').onclick = async e => {
    const ed = e.target.closest('[data-e]'), dl = e.target.closest('[data-d]');
    if (ed) form(students.find(s => s.id == ed.dataset.e));
    if (dl && confirm('Remove this student and all their records?')) {
      try { await api('student_delete', { id: +dl.dataset.d }); renderStudents(box); } catch (err) { toast(err.message, 'error'); }
    }
  };
}

/* ---------- Attendance tab (auto-filled by the biometric system) ---------- */
async function renderAttendance(box) {
  const sid = S.section.id;
  box.innerHTML = `
    <div class="toolbar">
      <span class="live">Live from biometric</span>
      <span class="spacer"></span>
      <input type="date" id="att-date" value="${today()}">
      <button class="btn" id="att-add">Add class day</button>
    </div>
    <div class="legend"><span><span class="badge P">P</span> Present</span><span><span class="badge E">E</span> Excused</span><span><span class="badge A">A</span> Absent</span><span>Click a cell to change it. Attendance % counts Present + Excused.</span></div>
    <div id="att-table" style="margin-top:.8rem"></div>`;
  $('#att-add').onclick = async () => {
    try { await api('session_add', { section_id: sid, date: $('#att-date').value }); load(); } catch (err) { toast(err.message, 'error'); }
  };
  let last = '';
  async function load(force = true) {
    const d = await api('attendance_get', null, { section_id: sid });
    const sig = JSON.stringify(d);
    if (!force && sig === last) return;
    last = sig;
    const oldWrap = $('#att-table .table-wrap');
    const savedTop = oldWrap ? oldWrap.scrollTop : 0;
    const savedLeft = oldWrap ? oldWrap.scrollLeft : 0;
    draw(d);
    const newWrap = $('#att-table .table-wrap');
    if (newWrap) {
      newWrap.scrollTop = savedTop;
      newWrap.scrollLeft = savedLeft;
    }
  }
  function draw(d) {
    const rec = {};
    d.records.forEach(r => rec[r.session_id + ':' + r.student_id] = r);
    const wrap = $('#att-table');
    if (!d.rows.length) { wrap.innerHTML = '<div class="empty">Add students first (Students tab).</div>'; return; }
    wrap.innerHTML = `<div class="table-wrap"><table>
      <thead><tr><th class="sticky-col">Student</th>
        ${d.sessions.map(s => `<th class="num" title="${fmtFull(s.session_date)}">${fmtDate(s.session_date)}<button class="x" data-ds="${s.id}" title="Remove this class day">&times;</button></th>`).join('')}
        <th class="num">Present</th><th class="num">Excused</th><th class="num">Absent</th><th class="num">Attendance %</th></tr></thead>
      <tbody>${d.rows.map(s => `<tr><td class="sticky-col">${nameOf(s)}</td>
        ${d.sessions.map(se => {
          const r = rec[se.id + ':' + s.id];
          const st = r ? r.status : 'absent';
          const tip = r ? `AM: ${r.am_in || '-'} / ${r.am_out || '-'}  PM: ${r.pm_in || '-'} / ${r.pm_out || '-'}` : 'No scan';
          return `<td class="num att-cell" data-s="${se.id}" data-st="${s.id}" data-v="${st}" title="${tip}"><span class="badge ${st[0].toUpperCase()}">${st[0].toUpperCase()}</span></td>`;
        }).join('')}
        <td class="num">${s.att_present}</td><td class="num">${s.att_excused}</td><td class="num">${s.att_absent}</td>
        <td class="num pct">${pctTxt(s.pct.attendance)}</td></tr>`).join('')}</tbody></table></div>
      ${d.sessions.length ? '' : '<div class="empty">No class days yet. A day is created automatically at the first fingerprint scan, or add one above.</div>'}`;
    wrap.onclick = async e => {
      const x = e.target.closest('[data-ds]');
      if (x) {
        if (!confirm('Remove this class day and its attendance?')) return;
        try { await api('session_delete', { id: +x.dataset.ds }); load(); } catch (err) { toast(err.message, 'error'); }
        return;
      }
      const c = e.target.closest('.att-cell');
      if (!c) return;
      const next = { absent: 'present', present: 'excused', excused: 'absent' }[c.dataset.v];
      try { await api('attendance_set', { session_id: +c.dataset.s, student_id: +c.dataset.st, status: next }); load(); } catch (err) { toast(err.message, 'error'); }
    };
  }
  await load();
  S.timer = setInterval(() => { if (!document.querySelector('.modal-backdrop')) load(false).catch(() => {}); }, 5000);
}

/* ---------- Quizzes / Boardworks / Projects / Midterm / Final ---------- */
async function renderAssessments(box, cat) {
  const sid = S.section.id;
  const single = cat === 'midterm' || cat === 'final';
  const d = await api('assessments_get', null, { section_id: sid, category: cat });
  const scores = {};
  d.scores.forEach(s => scores[s.assessment_id + ':' + s.student_id] = s.score);
  const weight = S.weights[cat];

  // Each quiz/exam uses the logged-in instructor's passing threshold.
  const rate = d.passing;
  const need = a => num(a.max_score) * rate / 100;
  const fmtN = n => String(Math.round(n * 100) / 100);
  const hasV = v => v !== undefined && v !== null && v !== '';
  const passed = (a, v) => hasV(v) && num(v) + 1e-9 >= need(a);
  const rowInfo = sId => {
    let e = 0, p = 0, ok = 0, n = 0;
    d.assessments.forEach(a => { const v = scores[a.id + ':' + sId]; if (hasV(v)) { e += num(v); p += num(a.max_score); n++; if (rate && passed(a, v)) ok++; } });
    return { pct: n && p > 0 ? e / p * 100 : null, ok, n };
  };
  const rowPct = sId => { const r = rowInfo(sId); return r.pct === null ? '—' : r.pct.toFixed(2) + '%'; };
  const rowResult = sId => {
    if (!rate) return '';
    const r = rowInfo(sId);
    if (!r.n) return '—';
    const pass = r.pct + 1e-9 >= rate;
    return `<span class="res ${pass ? 'pass' : 'fail'}">${pass ? 'Passed' : 'Failed'}</span>${single ? '' : `<small>${r.ok} of ${r.n} passed</small>`}`;
  };
  const refreshRow = sId => {
    $(`[data-pct="${sId}"]`).textContent = rowPct(sId);
    const r = $(`[data-res="${sId}"]`); if (r) r.innerHTML = rowResult(sId);
  };
  const paint = inp => {
    inp.classList.remove('pass', 'fail');
    const a = d.assessments.find(x => x.id == inp.dataset.a);
    if (rate && a && inp.value !== '') inp.classList.add(passed(a, inp.value) ? 'pass' : 'fail');
  };

  box.innerHTML = `
    <div class="toolbar">
      <strong>${LABEL[cat]}</strong><span class="muted">worth ${weight}% of the final grade${rate ? ` &middot; passing: ${rate}% of each item's max score` : ''}</span><span class="spacer"></span>
      ${single ? '' : `<button class="btn primary" id="as-add">+ Add ${LABEL[cat].split(' ')[0].toLowerCase()}</button>`}
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th class="sticky-col">Student</th>
        ${d.assessments.map(a => `<th class="num">${esc(a.title)}<small>out of ${+a.max_score}${rate ? ` &middot; pass &ge; ${fmtN(need(a))}` : ''}</small>
          <button class="btn small" data-ea="${a.id}">Edit</button>${single ? '' : `<button class="x" data-da="${a.id}" title="Delete">&times;</button>`}</th>`).join('')}
        <th class="num">${LABEL[cat]} %</th>${rate ? `<th class="num">Result<small>${rate}% to pass</small></th>` : ''}</tr></thead>
      <tbody>${d.students.map(s => `<tr data-row="${s.id}"><td class="sticky-col">${nameOf(s)}</td>
        ${d.assessments.map(a => `<td class="num"><input class="score${rate && hasV(scores[a.id + ':' + s.id]) ? (passed(a, scores[a.id + ':' + s.id]) ? ' pass' : ' fail') : ''}" type="number" step="any" min="0" max="${a.max_score}"
          data-a="${a.id}" data-s="${s.id}" value="${scores[a.id + ':' + s.id] ?? ''}"></td>`).join('')}
        <td class="num pct" data-pct="${s.id}">${rowPct(s.id)}</td>${rate ? `<td class="num" data-res="${s.id}">${rowResult(s.id)}</td>` : ''}</tr>`).join('')
        || `<tr><td colspan="${d.assessments.length + 3}" class="empty">No students yet.</td></tr>`}</tbody></table></div>
    ${d.assessments.length || single ? '' : '<div class="empty">Nothing recorded yet. Click “Add” to create one, then type the scores.</div>'}
    <p class="muted">Tip: press Enter to jump to the next student. Leave a box empty if not graded yet.</p>`;

  const form = a => formModal({
    title: a ? 'Edit ' + LABEL[cat] : 'Add ' + LABEL[cat],
    fields: [
      { name: 'title', label: 'Title', value: a?.title ?? (single ? LABEL[cat] : ''), attrs: 'required' },
      { name: 'max_score', label: 'Total / max score', type: 'number', value: a?.max_score ?? 100, attrs: 'min="1" step="any" required' }
    ],
    onSubmit: async v => { await api('assessment_save', { ...v, id: a?.id, section_id: sid, category: cat }); renderAssessments(box, cat); }
  });
  const addBtn = $('#as-add'); if (addBtn) addBtn.onclick = () => form(null);

  box.onclick = async e => {
    const ea = e.target.closest('[data-ea]'), da = e.target.closest('[data-da]');
    if (ea) form(d.assessments.find(a => a.id == ea.dataset.ea));
    if (da && confirm('Delete this item and all its scores?')) {
      try { await api('assessment_delete', { id: +da.dataset.da }); renderAssessments(box, cat); } catch (err) { toast(err.message, 'error'); }
    }
  };
  box.oninput = e => {   // pass/fail and percentages update instantly while typing
    const inp = e.target.closest('input.score'); if (!inp) return;
    scores[inp.dataset.a + ':' + inp.dataset.s] = inp.value === '' ? undefined : inp.value;
    paint(inp); refreshRow(inp.dataset.s);
  };
  box.onchange = async e => {
    const inp = e.target.closest('input.score'); if (!inp) return;
    try {
      const r = await api('score_save', { assessment_id: +inp.dataset.a, student_id: +inp.dataset.s, score: inp.value });
      scores[inp.dataset.a + ':' + inp.dataset.s] = r.score;
      inp.classList.remove('err'); inp.classList.add('saved');
      paint(inp); refreshRow(inp.dataset.s);
      setTimeout(() => inp.classList.remove('saved'), 900);
    } catch (err) { inp.classList.add('err'); toast(err.message, 'error'); }
  };
  box.onkeydown = e => {
    const inp = e.target.closest('input.score');
    if (!inp || e.key !== 'Enter') return;
    e.preventDefault();
    const nxt = inp.closest('tr').nextElementSibling;
    const target = nxt && nxt.querySelector(`input[data-a="${inp.dataset.a}"]`);
    if (target) { target.focus(); target.select(); } else inp.blur();
  };
}

/* ---------- Grade Summary ---------- */
async function renderSummary(box) {
  const d = await api('summary', null, { section_id: S.section.id });
  const W = d.weights;
  const P = d.passing.rates;
  const cols = ['attendance', 'quiz', 'boardwork', 'project', 'midterm', 'final'];
  const names = { attendance: 'Attendance', quiz: 'Quizzes', boardwork: 'Boardwork / Recit.', project: 'Project', midterm: 'Midterm', final: 'Final Exam' };
  box.innerHTML = `
    <div class="toolbar"><strong>Grade Summary</strong><span class="muted">weighted: ${cols.map(c => names[c] + ' ' + W[c] + '%').join(' · ')}</span>
      <span class="spacer"></span><button class="btn" id="csv">Export CSV</button><button class="btn" onclick="window.print()">Print</button></div>
    <div class="table-wrap"><table>
      <thead><tr><th class="sticky-col">Student</th>${cols.map(c => `<th class="num">${names[c]}<small>${W[c]}%</small></th>`).join('')}
        <th class="num">Final grade %</th><th class="num">Equivalent</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr><td class="sticky-col">${nameOf(r)}</td>
        ${cols.map(c => { const chk = d.passing.categories.includes(c) && r.pct[c] !== null; return `<td class="num ${chk ? (r.pct[c] + 1e-9 >= P[c] ? 'pass' : 'fail') : ''}">${pctTxt(r.pct[c])}${chk ? `<small>${P[c]}% to pass</small>` : ''}</td>`; }).join('')}
        <td class="num pct">${r.overall.toFixed(2)}%${r.missing ? `<small>${r.missing} pending</small>` : ''}</td>
        <td class="num ${r.grade === '5.00' ? 'grade-fail' : 'grade-pass'}">${r.grade}</td></tr>`).join('')
        || '<tr><td colspan="9" class="empty">No students yet.</td></tr>'}</tbody></table></div>
    <p class="muted">Category % = points earned ÷ points possible. A category with no scores yet counts as 0 in the final grade and is shown as “pending”. Quiz, Midterm and Final results use their respective instructor-set passing rates.</p>`;
  $('#csv').onclick = () => {
    const head = ['Student no.', 'Last name', 'First name', ...cols.map(c => names[c] + ' %'), 'Final grade %', 'Equivalent'];
    const lines = d.rows.map(r => [r.student_no, r.last_name, r.first_name, ...cols.map(c => r.pct[c] ?? ''), r.overall, r.grade]);
    const csv = [head, ...lines].map(l => l.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['\ufeff' + csv], { type: 'text/csv' }));
    a.download = `grades_${S.section.year_level}-${S.section.section_name}.csv`;
    a.click();
  };
}

boot().catch(err => toast(err.message, 'error'));
