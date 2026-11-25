<?php
require __DIR__ . '/config.php';
start_session();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$dbError = null;
try { db()->query('SELECT 1 FROM teachers LIMIT 1'); } catch (Throwable $e) { $dbError = $e->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= htmlspecialchars($_SESSION['csrf']) ?>">
  <title>Grading System</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<?php if ($dbError): ?>
  <main class="center">
    <div class="card auth">
      <h2>Database not ready</h2>
      <p class="muted">Start Apache and MySQL in XAMPP, then import <code>database.sql</code> in phpMyAdmin. Check the credentials in <code>config.php</code>.</p>
      <pre class="err-box"><?= htmlspecialchars($dbError) ?></pre>
    </div>
  </main>
<?php else: ?>
  <header class="topbar" id="topbar" hidden>
    <div class="brand">&#127891; Grading System</div>
    <nav>
      <button class="navbtn" id="nav-sections">Manage Students</button>
      <button class="navbtn" id="nav-settings" hidden>Grading Settings</button>
      <button class="navbtn" id="nav-admin" hidden>Admin Dashboard</button>
      <span class="role-badge" id="role-badge" hidden>Admin</span>
      <span class="who" id="who"></span>
      <button class="navbtn ghost" id="nav-logout">Log out</button>
    </nav>
  </header>

  <main>
    <!-- Login / first-time setup -->
    <section id="view-login" class="center" hidden>
      <form class="card auth" id="auth-form" autocomplete="off">
        <h2 id="auth-title">Log in</h2>
        <p class="muted" id="auth-note"></p>
        <label id="fn-wrap" hidden>Full name<input name="full_name"></label>
        <label>Username<input name="username" required></label>
        <label>Password<input name="password" type="password" required></label>
        <label id="pw2-wrap" hidden>Confirm password<input name="password2" type="password"></label>
        <label id="invite-wrap" hidden>Teacher invitation code<input name="invite_code" type="password" autocomplete="off"></label>
        <button class="btn primary block" id="auth-btn">Log in</button>
        <p class="auth-toggle"><a href="#" id="auth-toggle"></a></p>
      </form>
    </section>

    <!-- Manage Students: all year & sections -->
    <section id="view-sections" hidden>
      <div class="page-head">
        <div><h1>Manage Students</h1><p class="muted">Pick a year &amp; section to open its attendance, quizzes, boardworks, projects and exams.</p></div>
        <div class="toolbar">
          <button class="btn" id="bio-sync" title="Pull new and changed students from the biometric system into your linked sections">Sync now</button>
          <button class="btn" id="bio-import">Import from biometric system</button>
          <button class="btn primary" id="add-section">+ Add year &amp; section</button>
        </div>
      </div>
      <div class="grid" id="section-grid"></div>
      <div id="unmatched"></div>
    </section>


    <!-- Instructor grading settings -->
    <section id="view-settings" hidden>
      <div class="page-head">
        <div>
          <h1>Grading Settings</h1>
          <p class="muted">Set the percentage contribution of each grading category. These settings apply to your own classes only.</p>
        </div>
      </div>
      <div class="card" style="max-width:620px">
        <form id="weights-form">
          <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem">
            <label>Attendance (%)
              <input type="number" id="weight-attendance" min="0" max="100" step="1" required>
            </label>
            <label>Quizzes (%)
              <input type="number" id="weight-quiz" min="0" max="100" step="1" required>
            </label>
            <label>Boardwork / Recitation (%)
              <input type="number" id="weight-boardwork" min="0" max="100" step="1" required>
            </label>
            <label>Projects (%)
              <input type="number" id="weight-project" min="0" max="100" step="1" required>
            </label>
            <label>Midterm Exam (%)
              <input type="number" id="weight-midterm" min="0" max="100" step="1" required>
            </label>
            <label>Final Exam (%)
              <input type="number" id="weight-final" min="0" max="100" step="1" required>
            </label>
          </div>
          <p id="weights-total" style="font-weight:700;margin:1rem 0">Total: 100%</p>
          <p class="muted">All six weights must add up to exactly 100% before saving.</p>
          <button type="submit" class="btn primary">Save grading weights</button>
        </form>
      </div>
      <div class="card" style="max-width:620px;margin-top:1rem">
        <form id="passing-rates-form">
          <h2>Passing Rates</h2>
          <p class="muted">Set the minimum percentage required to pass each quiz or exam. Each score is checked against its own maximum score. These settings apply to your own classes only.</p>

          <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem">
            <label>Quizzes (%)
              <input type="number" id="passing-quiz" min="0" max="100" step="1" required>
            </label>

            <label>Midterm Exam (%)
              <input type="number" id="passing-midterm" min="0" max="100" step="1" required>
            </label>

            <label>Final Exam (%)
              <input type="number" id="passing-final" min="0" max="100" step="1" required>
            </label>
          </div>

          <p class="muted">Each passing rate must be a whole number from 0 to 100.</p>
          <button type="submit" class="btn primary">Save passing rates</button>
        </form>
      </div>
    </section>

    <!-- Admin dashboard: every instructor, class and enrolled student -->
    <section id="view-admin" hidden>
      <div class="page-head">
        <div><h1>Admin Dashboard</h1><p class="muted">Every instructor, their classes (subject, year &amp; section) and the students enrolled in each.</p></div>
        <input type="search" id="admin-search" class="search" placeholder="Search instructor, subject, year or section…">
      </div>
      <div class="stats" id="admin-stats"></div>
      <div id="admin-list"></div>
    </section>

    <!-- One year & section -->
    <section id="view-section" hidden>
      <div class="page-head">
        <div>
          <button class="btn ghost small" id="back">&larr; All sections</button>
          <h1 id="sec-title"></h1>
          <p class="muted" id="sec-sub"></p>
        </div>
      </div>
      <div class="tabs" id="tabs"></div>
      <div class="card" id="tab-content"></div>
    </section>
  </main>
  <div id="toast"></div>
  <script src="app.js"></script>
<?php endif; ?>
</body>
</html>
