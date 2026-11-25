<?php
require __DIR__ . '/config.php';
start_session();

function fail(string $m, int $c = 400): void { json_out(['error' => $m], $c); }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$in = json_decode(file_get_contents('php://input'), true) ?: [];

if ($method === 'POST') {
    $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $tok)) fail('Session expired. Refresh the page.', 403);
}

function val(string $k, $default = '') { global $in; return isset($in[$k]) ? trim((string)$in[$k]) : $default; }

try {
    $pdo = db();
    $tid = (int)($_SESSION['teacher_id'] ?? 0);

    // ---------- public actions ----------
    if ($action === 'me') {
        $has = (int)$pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn() > 0;
        $t = null;
        if ($tid) {
            $q = $pdo->prepare('SELECT id, full_name, username, role, is_active FROM teachers WHERE id = ?');
            $q->execute([$tid]);
            $t = $q->fetch() ?: null;
            if ($t && !(int)$t['is_active']) {
                $_SESSION = [];
                session_destroy();
                $t = null;
            }
        }
        $canSetWeights = $t && in_array($t['role'], ['teacher', 'admin_teacher'], true);
        $weights = $canSetWeights ? teacher_weights($pdo, (int)$t['id']) : WEIGHTS;
        $passingRates = $canSetWeights ? teacher_passing_rates($pdo, (int)$t['id']) : PASSING_RATES;
        json_out(['teacher' => $t, 'has_teacher' => $has, 'allow_signup' => ALLOW_SIGNUP || !$has,
                  'weights' => $weights, 'passing_rates' => $passingRates]);
    }
    if ($action === 'login') {
        $q = $pdo->prepare('SELECT * FROM teachers WHERE username = ?');
        $q->execute([val('username')]);
        $t = $q->fetch();
        if (!$t || !password_verify(val('password'), $t['password_hash'])) fail('Wrong username or password.', 401);
        if (!(int)$t['is_active']) fail('Your account is deactivated. Contact the administrator.', 403);
        session_regenerate_id(true);
        $_SESSION['teacher_id'] = (int)$t['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'register') {
        if (!hash_equals(SIGNUP_INVITE_CODE, val('invite_code'))) fail('Invalid teacher invitation code.', 403);
        if (val('full_name') === '' || val('username') === '' || strlen(val('password')) < 6) fail('Fill all fields (password: 6+ characters).');
        try {
            $pdo->prepare("INSERT INTO teachers (full_name, username, password_hash, role) VALUES (?, ?, ?, 'teacher')")
                ->execute([val('full_name'), val('username'), password_hash(val('password'), PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) == 1062) fail('That username is already taken.');
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['teacher_id'] = (int)$pdo->lastInsertId();
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => true]);
    }

    // ---------- everything below needs a login ----------
    if (!$tid) fail('Please log in.', 401);
    $activeCheck = $pdo->prepare('SELECT is_active FROM teachers WHERE id = ?');
    $activeCheck->execute([$tid]);
    if (!(int)$activeCheck->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        fail('Your account is deactivated. Please contact the administrator.', 401);
    }
    $isAdmin = is_admin($pdo, $tid);
    $roleStmt = $pdo->prepare('SELECT role FROM teachers WHERE id = ?');
    $roleStmt->execute([$tid]);
    $role = $roleStmt->fetchColumn();
      $isAdminTeacher = $role === 'admin_teacher';

    function own_section(PDO $pdo, int $sid, int $tid): array {
        $q = $pdo->prepare('SELECT * FROM sections WHERE id = ? AND teacher_id = ?');
        $q->execute([$sid, $tid]);
        $r = $q->fetch();
        if (!$r) fail('Section not found.', 404);
        return $r;
    }
    function own_check(PDO $pdo, string $sql, int $id, int $tid): array {
        $q = $pdo->prepare($sql);
        $q->execute([$id, $tid]);
        $r = $q->fetch();
        if (!$r) fail('Not found.', 404);
        return $r;
    }
    $sidIn = (int)($_GET['section_id'] ?? $in['section_id'] ?? 0);

    switch ($action) {
          // ----- instructor grading weights -----
          case 'weights_get':
              if (!in_array($role, ['teacher', 'admin_teacher'], true)) {
                  fail('Grading settings are not available for this account.', 403);
              }
              json_out(['weights' => teacher_weights($pdo, $tid)]);

          case 'weights_save':
              if (!in_array($role, ['teacher', 'admin_teacher'], true)) {
                  fail('Grading settings are not available for this account.', 403);
              }

              $raw = $in['weights'] ?? null;
              $keys = array_keys(WEIGHTS);

              if (!is_array($raw) || count($raw) !== count($keys)
                  || array_diff($keys, array_keys($raw))
                  || array_diff(array_keys($raw), $keys)) {
                  fail('Submit all six grading categories.');
              }

              $weights = [];
              foreach ($keys as $key) {
                  $value = $raw[$key] ?? null;
                  if (!(is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value)))
                      || (int)$value < 0 || (int)$value > 100) {
                      fail('Each grading weight must be a whole number from 0 to 100.');
                  }
                  $weights[$key] = (int)$value;
              }

              if (array_sum($weights) !== 100) {
                  fail('The six grading weights must total exactly 100%.');
              }

              $stmt = $pdo->prepare(
                  'INSERT INTO teacher_grade_weights
                   (teacher_id, attendance, quiz, boardwork, project, midterm, final)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                   attendance = VALUES(attendance),
                   quiz = VALUES(quiz),
                   boardwork = VALUES(boardwork),
                   project = VALUES(project),
                   midterm = VALUES(midterm),
                   final = VALUES(final)'
              );
              $stmt->execute(array_merge([$tid], array_values($weights)));

              json_out(['ok' => true, 'weights' => $weights]);

          // ----- instructor passing-rate settings -----
          case 'passing_rates_get':
              if (!in_array($role, ['teacher', 'admin_teacher'], true)) {
                  fail('Passing-rate settings are not available for this account.', 403);
              }
              json_out(['passing_rates' => teacher_passing_rates($pdo, $tid)]);

          case 'passing_rates_save':
              if (!in_array($role, ['teacher', 'admin_teacher'], true)) {
                  fail('Passing-rate settings are not available for this account.', 403);
              }

              $raw = $in['passing_rates'] ?? null;
              $keys = array_keys(PASSING_RATES);

              if (!is_array($raw) || count($raw) !== count($keys)
                  || array_diff($keys, array_keys($raw))
                  || array_diff(array_keys($raw), $keys)) {
                  fail('Submit all three passing rates.');
              }

              $rates = [];
              foreach ($keys as $key) {
                  $value = $raw[$key] ?? null;

                  if (!(is_int($value) || (is_string($value) && preg_match('/^\\d+$/', $value)))
                      || (int)$value < 0 || (int)$value > 100) {
                      fail('Each passing rate must be a whole number from 0 to 100.');
                  }

                  $rates[$key] = (int)$value;
              }

              $stmt = $pdo->prepare(
                  'INSERT INTO teacher_passing_rates (teacher_id, quiz, midterm, final)
                   VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                   quiz = VALUES(quiz),
                   midterm = VALUES(midterm),
                   final = VALUES(final)'
              );

              $stmt->execute(array_merge([$tid], array_values($rates)));
              json_out(['ok' => true, 'passing_rates' => $rates]);

          // ----- sections -----
        case 'sections_list':
            $q = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM students WHERE section_id = s.id) AS student_count
                                FROM sections s WHERE teacher_id = ? ORDER BY year_level, section_name, subject_code');
            $q->execute([$tid]);
            json_out(['sections' => $q->fetchAll()]);

        case 'section_save':
            $f = [val('year_level'), val('section_name'), val('subject_code'), val('subject_name'), val('school_year')];
            $dept = val('bio_department_id') === '' ? null : (int)val('bio_department_id');
            $nf = val('name_format') === 'first_last' ? 'first_last' : 'last_first';
            $secId = 0;
            if ($f[0] === '' || $f[1] === '') fail('Year level and section are required.');
            try {
                if (!empty($in['id'])) {
                    $existing = own_section($pdo, (int)$in['id'], $tid);
                    if (!$isAdmin) {
                        $dept = $existing['bio_department_id'];
                        $nf = $existing['name_format'];
                    }
                    $pdo->prepare('UPDATE sections SET year_level=?, section_name=?, subject_code=?, subject_name=?, school_year=?, bio_department_id=?, name_format=? WHERE id=?')
                        ->execute([...$f, $dept, $nf, (int)$in['id']]);
                    $secId = (int)$in['id'];
                } else {
                    if (!$isAdmin) $dept = null;
                    $pdo->prepare('INSERT INTO sections (teacher_id, year_level, section_name, subject_code, subject_name, school_year, bio_department_id, name_format) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$tid, ...$f, $dept, $nf]);
                    $secId = (int)$pdo->lastInsertId();
                }
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) == 1062) fail('That year/section/subject already exists.');
                throw $e;
            }
            $synced = null;
            if ($isAdmin && $dept && $secId) {
                try { [$synced] = bio_sync_section($pdo, $secId, $dept, $nf); }
                catch (Throwable $e) { error_log('[grading] link sync: ' . $e->getMessage()); }
            }
            json_out(['ok' => true, 'synced' => $synced]);

        case 'section_delete':
            own_section($pdo, (int)($in['id'] ?? 0), $tid);
            $pdo->prepare('DELETE FROM sections WHERE id = ?')->execute([(int)$in['id']]);
            json_out(['ok' => true]);

        // ----- students -----
        case 'students_list':
            $sec = own_section($pdo, $sidIn, $tid);
            $q = $pdo->prepare('SELECT id, student_no, last_name, first_name, fingerprint_id FROM students WHERE section_id = ? ORDER BY last_name, first_name');
            $q->execute([$sidIn]);
            $list = $q->fetchAll();
            if ($sec['bio_department_id']) {   // warn about students whose biometric department changed (nothing is deleted automatically)
                try {
                    $dq = bio_db()->prepare('SELECT u.department_id, d.department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                             WHERE u.fingerprint_id = ? AND u.del_fingerid = 0 LIMIT 1');
                    foreach ($list as &$st) {
                        if (!$st['fingerprint_id']) continue;
                        $dq->execute([$st['fingerprint_id']]);
                        $r = $dq->fetch();
                        if (!$r) $st['bio_note'] = 'Not found in biometric system';
                        elseif ((int)$r['department_id'] !== (int)$sec['bio_department_id']) $st['bio_note'] = 'Biometric department is now: ' . ($r['department_name'] ?: 'none');
                    }
                    unset($st);
                } catch (Throwable $e) { /* notes are optional */ }
            }
            json_out(['students' => $list]);

        case 'student_save':
            own_section($pdo, $sidIn, $tid);
            $fp = null;
            if ($isAdminTeacher) {
                $fp = val('fingerprint_id');
                if ($fp !== '' && (!ctype_digit($fp) || (int)$fp < 1 || (int)$fp > 1000)) fail('Fingerprint ID must be 1-1000.');
                $fp = $fp === '' ? null : (int)$fp;
            }
            if (val('student_no') === '' || val('last_name') === '' || val('first_name') === '') fail('Student no., last name and first name are required.');
            try {
                if (!empty($in['id'])) {
                    own_check($pdo, 'SELECT st.id FROM students st JOIN sections s ON s.id = st.section_id WHERE st.id = ? AND s.teacher_id = ?', (int)$in['id'], $tid);
                    if ($isAdminTeacher) {
                        $pdo->prepare('UPDATE students SET student_no=?, last_name=?, first_name=?, fingerprint_id=? WHERE id=?')
                            ->execute([val('student_no'), val('last_name'), val('first_name'), $fp, (int)$in['id']]);
                    } else {
                        $pdo->prepare('UPDATE students SET student_no=?, last_name=?, first_name=? WHERE id=?')
                            ->execute([val('student_no'), val('last_name'), val('first_name'), (int)$in['id']]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name, fingerprint_id) VALUES (?,?,?,?,?)')
                        ->execute([$sidIn, val('student_no'), val('last_name'), val('first_name'), $fp]);
                }
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) == 1062) fail('Student no. or fingerprint ID is already used in this section.');
                throw $e;
            }
            json_out(['ok' => true]);

        case 'student_delete':
            own_check($pdo, 'SELECT st.id FROM students st JOIN sections s ON s.id = st.section_id WHERE st.id = ? AND s.teacher_id = ?', (int)($in['id'] ?? 0), $tid);
            $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([(int)$in['id']]);
            json_out(['ok' => true]);

        case 'students_import':
            own_section($pdo, $sidIn, $tid);
            $added = 0; $skipped = [];

            if ($isAdminTeacher) {
                $st = $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name, fingerprint_id) VALUES (?,?,?,?,?)
                                     ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name), fingerprint_id = VALUES(fingerprint_id)');
            } else {
                $st = $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name) VALUES (?,?,?,?)
                                     ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name)');
            }

            foreach (preg_split('/\r\n|\r|\n/', val('text')) as $n => $line) {
                if (trim($line) === '') continue;
                $p = array_map('trim', preg_split('/[,\t]/', $line));

                if (count($p) < 3 || $p[0] === '' || $p[1] === '' || $p[2] === '') {
                    $skipped[] = $n + 1;
                    continue;
                }

                if ($isAdminTeacher) {
                    $fp = $p[3] ?? '';
                    if (count($p) > 4 || ($fp !== '' && (!ctype_digit($fp) || (int)$fp < 1 || (int)$fp > 1000))) {
                        $skipped[] = $n + 1;
                        continue;
                    }
                    $values = [$sidIn, $p[0], $p[1], $p[2], $fp === '' ? null : (int)$fp];
                } else {
                    if (count($p) !== 3) {
                        $skipped[] = $n + 1;
                        continue;
                    }
                    $values = [$sidIn, $p[0], $p[1], $p[2]];
                }

                try {
                    $st->execute($values);
                    $added++;
                } catch (PDOException $e) {
                    $skipped[] = $n + 1;
                }
            }

            json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped]);

        // ----- attendance -----
        case 'attendance_get':
            own_section($pdo, $sidIn, $tid);
            $q = $pdo->prepare('SELECT id, session_date FROM class_sessions WHERE section_id = ? ORDER BY session_date');
            $q->execute([$sidIn]);
            $sessions = $q->fetchAll();
            $q = $pdo->prepare('SELECT a.session_id, a.student_id, a.status, a.am_in, a.am_out, a.pm_in, a.pm_out
                                FROM attendance a JOIN class_sessions s ON s.id = a.session_id WHERE s.section_id = ?');
            $q->execute([$sidIn]);
            $weights = teacher_weights($pdo, $tid);
            json_out(['sessions' => $sessions, 'records' => $q->fetchAll(), 'rows' => compute_section($pdo, $sidIn, $weights)]);

        case 'session_add':
            own_section($pdo, $sidIn, $tid);
            $d = val('date');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Invalid date.');
            $pdo->prepare('INSERT IGNORE INTO class_sessions (section_id, session_date) VALUES (?, ?)')->execute([$sidIn, $d]);
            json_out(['ok' => true]);

        case 'session_delete':
            own_check($pdo, 'SELECT cs.id FROM class_sessions cs JOIN sections s ON s.id = cs.section_id WHERE cs.id = ? AND s.teacher_id = ?', (int)($in['id'] ?? 0), $tid);
            $pdo->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([(int)$in['id']]);
            json_out(['ok' => true]);

        case 'attendance_set':
            $sess = own_check($pdo, 'SELECT cs.id, cs.section_id FROM class_sessions cs JOIN sections s ON s.id = cs.section_id WHERE cs.id = ? AND s.teacher_id = ?', (int)($in['session_id'] ?? 0), $tid);
            $stu = own_check($pdo, 'SELECT st.id FROM students st JOIN sections s ON s.id = st.section_id WHERE st.id = ? AND s.teacher_id = ?', (int)($in['student_id'] ?? 0), $tid);
            $status = val('status');
            if ($status === 'absent') {
                $pdo->prepare('DELETE FROM attendance WHERE session_id = ? AND student_id = ?')->execute([$sess['id'], $stu['id']]);
            } elseif (in_array($status, ['present', 'excused'], true)) {
                $pdo->prepare('INSERT INTO attendance (session_id, student_id, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)')
                    ->execute([$sess['id'], $stu['id'], $status]);
            } else fail('Invalid status.');
            json_out(['ok' => true]);

        // ----- assessments & scores -----
        case 'assessments_get':
            own_section($pdo, $sidIn, $tid);
            $cat = $_GET['category'] ?? '';
            if (!in_array($cat, ['quiz', 'boardwork', 'project', 'midterm', 'final'], true)) fail('Invalid category.');
            $q = $pdo->prepare('SELECT id, category, title, max_score FROM assessments WHERE section_id = ? AND category = ? ORDER BY id');
            $q->execute([$sidIn, $cat]);
            $list = $q->fetchAll();
            if (!$list && ($cat === 'midterm' || $cat === 'final')) {   // exams always have exactly one column
                $pdo->prepare('INSERT INTO assessments (section_id, category, title, max_score) VALUES (?,?,?,100)')
                    ->execute([$sidIn, $cat, $cat === 'midterm' ? 'Midterm Exam' : 'Final Exam']);
                $q->execute([$sidIn, $cat]);
                $list = $q->fetchAll();
            }
            $q = $pdo->prepare('SELECT id, student_no, last_name, first_name FROM students WHERE section_id = ? ORDER BY last_name, first_name');
            $q->execute([$sidIn]);
            $students = $q->fetchAll();
            $q = $pdo->prepare('SELECT sc.assessment_id, sc.student_id, sc.score FROM scores sc
                                JOIN assessments a ON a.id = sc.assessment_id WHERE a.section_id = ? AND a.category = ?');
            $q->execute([$sidIn, $cat]);
            json_out(['assessments' => $list, 'students' => $students, 'scores' => $q->fetchAll(),
                      'passing' => in_array($cat, PASSING_CATEGORIES, true) ? teacher_passing_rates($pdo, $tid)[$cat] : null]);

        case 'assessment_save':
            $max = (float)val('max_score');
            if (val('title') === '' || $max <= 0) fail('Title and a max score above 0 are required.');
            if (!empty($in['id'])) {
                own_check($pdo, 'SELECT a.id FROM assessments a JOIN sections s ON s.id = a.section_id WHERE a.id = ? AND s.teacher_id = ?', (int)$in['id'], $tid);
                $pdo->prepare('UPDATE assessments SET title = ?, max_score = ? WHERE id = ?')->execute([val('title'), $max, (int)$in['id']]);
            } else {
                own_section($pdo, $sidIn, $tid);
                $cat = val('category');
                if (!in_array($cat, ['quiz', 'boardwork', 'project'], true)) fail('Invalid category.');
                $pdo->prepare('INSERT INTO assessments (section_id, category, title, max_score) VALUES (?,?,?,?)')->execute([$sidIn, $cat, val('title'), $max]);
            }
            json_out(['ok' => true]);

        case 'assessment_delete':
            $a = own_check($pdo, 'SELECT a.id, a.category FROM assessments a JOIN sections s ON s.id = a.section_id WHERE a.id = ? AND s.teacher_id = ?', (int)($in['id'] ?? 0), $tid);
            if (in_array($a['category'], ['midterm', 'final'], true)) fail('Exam columns cannot be deleted; clear the scores instead.');
            $pdo->prepare('DELETE FROM assessments WHERE id = ?')->execute([$a['id']]);
            json_out(['ok' => true]);

        case 'score_save':
            $a = own_check($pdo, 'SELECT a.id, a.max_score, a.section_id FROM assessments a JOIN sections s ON s.id = a.section_id WHERE a.id = ? AND s.teacher_id = ?', (int)($in['assessment_id'] ?? 0), $tid);
            $stu = own_check($pdo, 'SELECT st.id, st.section_id FROM students st JOIN sections s ON s.id = st.section_id WHERE st.id = ? AND s.teacher_id = ?', (int)($in['student_id'] ?? 0), $tid);
            if ($stu['section_id'] != $a['section_id']) fail('Student is not in this section.');
            $raw = val('score');
            if ($raw === '') {
                $pdo->prepare('DELETE FROM scores WHERE assessment_id = ? AND student_id = ?')->execute([$a['id'], $stu['id']]);
                json_out(['ok' => true, 'score' => null]);
            }
            if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > (float)$a['max_score']) fail('Score must be between 0 and ' . (float)$a['max_score'] . '.');
            $pdo->prepare('INSERT INTO scores (assessment_id, student_id, score) VALUES (?,?,?) ON DUPLICATE KEY UPDATE score = VALUES(score)')
                ->execute([$a['id'], $stu['id'], (float)$raw]);
            json_out(['ok' => true, 'score' => (float)$raw]);

        // ----- recent scans that could not be matched to a student (shows why) -----
        case 'unmatched_scans':
            $rows = $pdo->query("SELECT fingerprint_id, MAX(scanned_at) AS last_scan, COUNT(*) AS scans, SUBSTRING_INDEX(GROUP_CONCAT(result ORDER BY scanned_at DESC SEPARATOR '|'), '|', 1) AS result
                                 FROM attendance_scans
                                 WHERE result LIKE 'not enrolled%' AND scanned_at >= (NOW() - INTERVAL 14 DAY)
                                   AND fingerprint_id NOT IN (SELECT fingerprint_id FROM students WHERE fingerprint_id IS NOT NULL)
                                 GROUP BY fingerprint_id ORDER BY last_scan DESC LIMIT 30")->fetchAll();
            try {
                $nq = bio_db()->prepare('SELECT username FROM users WHERE fingerprint_id = ? AND del_fingerid = 0 LIMIT 1');
                foreach ($rows as &$r) { $nq->execute([$r['fingerprint_id']]); $r['name'] = $nq->fetchColumn() ?: null; }
                unset($r);
            } catch (Throwable $e) { /* names are optional */ }
            json_out(['scans' => $rows]);

        // ----- admin teacher account activation/deactivation -----
        case 'admin_teacher_status':
            if (!$isAdmin) fail('Admins only.', 403);
            $teacherId = (int)($in['id'] ?? 0);
            $status = $in['is_active'] ?? null;
            if ($teacherId < 1 || !in_array($status, [0, 1, '0', '1'], true)) fail('Invalid teacher or status.');
            $check = $pdo->prepare("SELECT id FROM teachers WHERE id = ? AND role = 'teacher'");
            $check->execute([$teacherId]);
            if (!$check->fetchColumn()) fail('Teacher account not found.', 404);
            $update = $pdo->prepare("UPDATE teachers SET is_active = ? WHERE id = ? AND role = 'teacher'");
            $update->execute([(int)$status, $teacherId]);
            json_out(['ok' => true, 'is_active' => (int)$status]);

        // ----- permanently remove a teacher account -----
        case 'admin_teacher_remove':
            if (!$isAdmin) fail('Admins only.', 403);
                        $teacherId = (int)($in['id'] ?? 0);
                        if ($teacherId < 1) fail('Invalid teacher ID.');
                        if ($teacherId === $tid) fail('You cannot remove your own account.', 403);

                        try {
                            $pdo->beginTransaction();

                            $lock = $pdo->prepare("SELECT id FROM teachers WHERE id = ? AND role = 'teacher' FOR UPDATE");
                            $lock->execute([$teacherId]);

                            if (!$lock->fetchColumn()) {
                                $pdo->rollBack();
                                fail('Teacher account not found.', 404);
                            }

                            $delSections = $pdo->prepare('DELETE FROM sections WHERE teacher_id = ?');
                            $delSections->execute([$teacherId]);

                            $delTeacher = $pdo->prepare("DELETE FROM teachers WHERE id = ? AND role = 'teacher'");
                            $delTeacher->execute([$teacherId]);

                            if ($delTeacher->rowCount() !== 1) {
                                throw new RuntimeException('Teacher deletion failed.');
                            }

                            $pdo->commit();
                            json_out(['ok' => true, 'message' => 'Teacher and grading records deleted.']);
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            throw $e;
                        }
        // ----- admin dashboard (read-only overview of every instructor) -----
        case 'admin_overview':
            if (!$isAdmin) fail('Admins only.', 403);
            $teachers = $pdo->query("SELECT id, full_name, username, created_at, is_active FROM teachers WHERE role = 'teacher' ORDER BY full_name")->fetchAll();
            $secRows = $pdo->query('SELECT s.id, s.teacher_id, s.year_level, s.section_name, s.subject_code, s.subject_name, s.school_year,
                                           (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS student_count
                                    FROM sections s ORDER BY s.year_level, s.section_name, s.subject_code')->fetchAll();
            $by = [];
            foreach ($secRows as $r) {
                if ($r['teacher_id'] !== null) {
                    $by[$r['teacher_id']][] = $r;
                }
            }
            $totSec = 0; $totStu = 0;
            foreach ($teachers as &$t) {
                $t['sections'] = $by[$t['id']] ?? [];
                $totSec += count($t['sections']);
                foreach ($t['sections'] as $s) $totStu += (int)$s['student_count'];
            }
            unset($t);
            json_out(['teachers' => $teachers, 'totals' => ['teachers' => count($teachers), 'sections' => $totSec, 'students' => $totStu]]);

        case 'admin_students':
            if (!$isAdmin) fail('Admins only.', 403);
            $q = $pdo->prepare('SELECT id, student_no, last_name, first_name, fingerprint_id FROM students WHERE section_id = ? ORDER BY last_name, first_name');
            $q->execute([$sidIn]);
            json_out(['students' => $q->fetchAll()]);

        // ----- import from the biometric attendance database -----
        case 'bio_departments':
            if (!$isAdmin) fail('Biometric access is restricted to administrators.', 403);
            try {
                $bio = bio_db();
                $rows = $bio->query("SELECT d.id, d.department_name, COUNT(u.id) AS cnt
                                     FROM departments d
                                     LEFT JOIN users u ON u.department_id = d.id AND u.del_fingerid = 0 AND u.username <> ''
                                     GROUP BY d.id, d.department_name ORDER BY d.department_name")->fetchAll();
                $q = $bio->prepare("SELECT username FROM users WHERE department_id = ? AND del_fingerid = 0 AND username <> '' ORDER BY id LIMIT 3");
                foreach ($rows as &$r) { $q->execute([$r['id']]); $r['sample'] = $q->fetchAll(PDO::FETCH_COLUMN); }
                unset($r);
                $lk = $pdo->prepare('SELECT DISTINCT bio_department_id FROM sections WHERE teacher_id = ? AND bio_department_id IS NOT NULL');
                $lk->execute([$tid]);
                $linked = array_flip($lk->fetchAll(PDO::FETCH_COLUMN));
                foreach ($rows as &$r) { $r['parsed'] = parse_department($r['department_name']); $r['linked'] = isset($linked[$r['id']]); }
                unset($r);
            } catch (PDOException $e) {
                error_log('[grading] bio: ' . $e->getMessage());
                fail('Cannot read the biometric database (' . BIO_DB_NAME . '). Check BIO_DB_* in config.php.', 500);
            }
            json_out(['departments' => $rows]);

        case 'bio_sync':   // re-sync every linked section of this instructor with the biometric department
            if (!$isAdmin) fail('Biometric access is restricted to administrators.', 403);
            try { bio_db(); } catch (PDOException $e) { fail('Cannot read the biometric database (' . BIO_DB_NAME . '). Check BIO_DB_* in config.php.', 500); }
            $q = $pdo->prepare('SELECT id, bio_department_id, name_format FROM sections WHERE teacher_id = ? AND bio_department_id IS NOT NULL');
            $q->execute([$tid]);
            $secsN = 0; $stuN = 0;
            foreach ($q->fetchAll() as $sc) { [$a] = bio_sync_section($pdo, (int)$sc['id'], (int)$sc['bio_department_id'], $sc['name_format']); $secsN++; $stuN += $a; }
            json_out(['ok' => true, 'sections' => $secsN, 'students' => $stuN]);

        case 'bio_import':
            if (!$isAdmin) fail('Biometric access is restricted to administrators.', 403);
            $items = $in['items'] ?? [];
            if (!is_array($items) || !$items) fail('Select at least one department.');
            $fmt = val('name_format') === 'first_last' ? 'first_last' : 'last_first';
            foreach ($items as $it) {
                if (trim((string)($it['year_level'] ?? '')) === '' || trim((string)($it['section_name'] ?? '')) === '')
                    fail('Year level and section are required for every selected department.');
            }
            try { $bio = bio_db(); }
            catch (PDOException $e) { fail('Cannot read the biometric database (' . BIO_DB_NAME . '). Check BIO_DB_* in config.php.', 500); }
            $findSec = $pdo->prepare('SELECT id FROM sections WHERE teacher_id = ? AND year_level = ? AND section_name = ? AND subject_code = ?');
            $insSec  = $pdo->prepare('INSERT INTO sections (teacher_id, year_level, section_name, subject_code, subject_name, school_year, bio_department_id, name_format) VALUES (?,?,?,?,?,?,?,?)');
            $getU    = $bio->prepare("SELECT username, serialnumber, fingerprint_id FROM users WHERE department_id = ? AND del_fingerid = 0 AND username <> '' ORDER BY username");
            $insSt   = $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name, fingerprint_id) VALUES (?,?,?,?,?)
                                      ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name), fingerprint_id = VALUES(fingerprint_id)');
            $secs = 0; $added = 0; $skipped = 0;
            foreach ($items as $it) {
                $y = trim((string)$it['year_level']); $s = trim((string)$it['section_name']);
                $code = trim((string)($it['subject_code'] ?? '')); $subj = trim((string)($it['subject_name'] ?? ''));
                $findSec->execute([$tid, $y, $s, $code]);
                $secId = (int)$findSec->fetchColumn();
                if (!$secId) {
                    $insSec->execute([$tid, $y, $s, $code, $subj, trim((string)($it['school_year'] ?? '')), (int)($it['department_id'] ?? 0), $fmt]);
                    $secId = (int)$pdo->lastInsertId();
                    $secs++;
                } else {   // section already exists: just link it to this biometric department
                    $pdo->prepare('UPDATE sections SET bio_department_id = ?, name_format = ? WHERE id = ?')
                        ->execute([(int)($it['department_id'] ?? 0), $fmt, $secId]);
                }
                $getU->execute([(int)($it['department_id'] ?? 0)]);
                foreach ($getU->fetchAll() as $u) {
                    [$last, $first] = split_name((string)$u['username'], $fmt);
                    $fp = (int)$u['fingerprint_id'];
                    if ($fp < 1 || $fp > 1000) $fp = null;
                    $no = bio_student_no($u['serialnumber']);
                    if ($no === '') $no = 'FP' . (int)$u['fingerprint_id'];
                    try { $insSt->execute([$secId, $no, $last, $first, $fp]); $added++; }
                    catch (PDOException $e) { $skipped++; }
                }
            }
            json_out(['ok' => true, 'sections' => $secs, 'students' => $added, 'skipped' => $skipped]);

        // ----- grade summary -----
        case 'summary':
            own_section($pdo, $sidIn, $tid);
            $weights = teacher_weights($pdo, $tid);
            $passingRates = teacher_passing_rates($pdo, $tid);
            json_out(['rows' => compute_section($pdo, $sidIn, $weights), 'weights' => $weights, 'passing' => ['rates' => $passingRates, 'categories' => PASSING_CATEGORIES]]);

        default:
            fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('[grading] ' . $e->getMessage());
    fail('Server error. Check the PHP error log.', 500);
}
