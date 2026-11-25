<?php
// ---------- Settings (edit these) ----------
const DB_HOST = '127.0.0.1';
const DB_NAME = 'grading_system';
const DB_USER = 'app_user';
const DB_PASS = '864f20a8c3c56b8e2feb625ef4181f02a150cd44e4dd8b280247e55d9208394b';                       // XAMPP default is empty
const DEVICE_TOKEN = '673de0079c6befb20769357ecf21a7acdc155528dee5f4aeabab321cfe9c046d';  // must match the token your biometric system sends to scan.php
const TIMEZONE = 'Asia/Manila';
// Hidden admin account creation: open /grading_system/admin_setup.php, enter this key, create the admin.
// Change the key first, and set ADMIN_SETUP_ENABLED to false once your admin account exists.
const ADMIN_SETUP_ENABLED = false;
const ADMIN_SETUP_KEY = '9114f4a15932c621762de543f157f00a0c8be40ff09b3c14dd1e4ebbaaf2540b';
const ALLOW_SIGNUP = false;
const SIGNUP_INVITE_CODE = '6ae95e0ce139712fc10341d22241f5d9a0c774853b11303065d957a331691812';   // true = anyone who can open the site may create an instructor account; false = only the first account

// Biometric attendance database (used by "Import from biometric system")
const BIO_DB_HOST = '127.0.0.1';
const BIO_DB_NAME = 'srpvhsdb';
const BIO_DB_USER = 'app_user';
const BIO_DB_PASS = '864f20a8c3c56b8e2feb625ef4181f02a150cd44e4dd8b280247e55d9208394b';

// Passing mark for quizzes, midterm and final exam: a score passes when it is at least
// PASSING_RATE percent of that item's own max score (e.g. 65% of 30 = 19.5 points).
const PASSING_RATE = 65; // Compatibility default
const PASSING_RATES = ['quiz' => 65, 'midterm' => 65, 'final' => 65];
const PASSING_CATEGORIES = ['quiz', 'midterm', 'final'];

// Grade weights (must total 100)
const WEIGHTS = [
    'attendance' => 10,
    'quiz'       => 10,
    'boardwork'  => 10,
    'project'    => 20,
    'midterm'    => 25,
    'final'      => 25,
];

date_default_timezone_set(TIMEZONE);

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    ensure_schema($pdo);
    return $pdo;
}

// Adds the columns needed for biometric auto-import to an existing install (runs once per request)
function ensure_schema(PDO $pdo): void {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM sections')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('bio_department_id', $cols, true))
            $pdo->exec('ALTER TABLE sections ADD COLUMN bio_department_id INT UNSIGNED NULL AFTER school_year');
        if (!in_array('name_format', $cols, true))
            $pdo->exec("ALTER TABLE sections ADD COLUMN name_format VARCHAR(12) NOT NULL DEFAULT 'last_first' AFTER bio_department_id");
        $rc = $pdo->query("SHOW COLUMNS FROM attendance_scans WHERE Field = 'result'")->fetch();
        if ($rc && stripos((string)$rc['Type'], 'varchar(40)') !== false)
            $pdo->exec("ALTER TABLE attendance_scans MODIFY result VARCHAR(200) NOT NULL DEFAULT ''");
        $tcols = $pdo->query('SHOW COLUMNS FROM teachers')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role', $tcols, true))
            $pdo->exec("ALTER TABLE teachers ADD COLUMN role ENUM('teacher','admin','admin_teacher') NOT NULL DEFAULT 'teacher' AFTER password_hash");
        $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_grade_weights (
            teacher_id INT UNSIGNED NOT NULL PRIMARY KEY,
            attendance SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            quiz SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            boardwork SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            project SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            midterm SMALLINT UNSIGNED NOT NULL DEFAULT 25,
            final SMALLINT UNSIGNED NOT NULL DEFAULT 25,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_teacher_grade_weights_teacher
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_passing_rates (
            teacher_id INT UNSIGNED NOT NULL PRIMARY KEY,
            quiz SMALLINT UNSIGNED NOT NULL DEFAULT 65,
            midterm SMALLINT UNSIGNED NOT NULL DEFAULT 65,
            final SMALLINT UNSIGNED NOT NULL DEFAULT 65,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_teacher_passing_rates_teacher
                FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* tables not created yet */ }
}

function teacher_weights(PDO $pdo, int $tid): array {
    $q = $pdo->prepare('SELECT attendance, quiz, boardwork, project, midterm, final
                        FROM teacher_grade_weights WHERE teacher_id = ?');
    $q->execute([$tid]);
    $row = $q->fetch();
    if (!$row) return WEIGHTS;

    $weights = [];
    foreach (WEIGHTS as $key => $default) {
        $weights[$key] = (int)$row[$key];
    }
    return $weights;
}

function teacher_passing_rates(PDO $pdo, int $tid): array {
    $q = $pdo->prepare('SELECT quiz, midterm, final
                        FROM teacher_passing_rates WHERE teacher_id = ?');
    $q->execute([$tid]);
    $row = $q->fetch();

    if (!$row) return PASSING_RATES;

    $rates = [];
    foreach (PASSING_RATES as $key => $default) {
        $rates[$key] = (int)$row[$key];
    }

    return $rates;
}

function is_admin(PDO $pdo, int $tid): bool {
    $q = $pdo->prepare('SELECT role FROM teachers WHERE id = ?');
    $q->execute([$tid]);
    return in_array($q->fetchColumn(), ['admin', 'admin_teacher'], true);
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Grade equivalent (1.00 - 5.00). Edit the table to match your school's scale.
function grade_equivalent(float $p): string {
    $scale = [[97, '1.00'], [94, '1.25'], [91, '1.50'], [88, '1.75'], [85, '2.00'],
              [82, '2.25'], [79, '2.50'], [76, '2.75'], [75, '3.00']];
    foreach ($scale as [$min, $g]) {
        if ($p >= $min) return $g;
    }
    return '5.00';
}

/**
 * Computes attendance + every category percentage + weighted overall grade
 * for every student in a section.
 *  - Attendance % = (present + excused) / total class days
 *  - Category %   = total points earned / total points possible (graded items only)
 *  - Overall      = sum(category % x weight); categories with no scores yet count as 0
 */
function compute_section(PDO $pdo, int $sid, array $weights): array {
    $q = $pdo->prepare('SELECT id, student_no, last_name, first_name, fingerprint_id
                        FROM students WHERE section_id = ? ORDER BY last_name, first_name');
    $q->execute([$sid]);
    $students = $q->fetchAll();

    $q = $pdo->prepare('SELECT COUNT(*) FROM class_sessions WHERE section_id = ?');
    $q->execute([$sid]);
    $sessions = (int)$q->fetchColumn();

    $att = [];
    $q = $pdo->prepare('SELECT a.student_id, a.status, COUNT(*) c
                        FROM attendance a JOIN class_sessions s ON s.id = a.session_id
                        WHERE s.section_id = ? GROUP BY a.student_id, a.status');
    $q->execute([$sid]);
    foreach ($q->fetchAll() as $r) $att[$r['student_id']][$r['status']] = (int)$r['c'];

    $cat = [];
    $q = $pdo->prepare('SELECT sc.student_id, ass.category, SUM(sc.score) e, SUM(ass.max_score) p
                        FROM scores sc JOIN assessments ass ON ass.id = sc.assessment_id
                        WHERE ass.section_id = ? GROUP BY sc.student_id, ass.category');
    $q->execute([$sid]);
    foreach ($q->fetchAll() as $r) $cat[$r['student_id']][$r['category']] = $r;

    $out = [];
    foreach ($students as $s) {
        $id = $s['id'];
        $p = $att[$id]['present'] ?? 0;
        $e = $att[$id]['excused'] ?? 0;
        $pct = ['attendance' => $sessions > 0 ? round(($p + $e) / $sessions * 100, 2) : null];
        foreach (['quiz', 'boardwork', 'project', 'midterm', 'final'] as $c) {
            $x = $cat[$id][$c] ?? null;
            $pct[$c] = ($x && (float)$x['p'] > 0) ? round((float)$x['e'] / (float)$x['p'] * 100, 2) : null;
        }
        $overall = 0.0;
        $missing = 0;
        foreach ($weights as $k => $w) {
            if ($pct[$k] === null) $missing++;
            else $overall += $pct[$k] * $w / 100;
        }
        $overall = round($overall, 2);
        $out[] = $s + [
            'sessions'    => $sessions,
            'att_present' => $p,
            'att_excused' => $e,
            'att_absent'  => max(0, $sessions - $p - $e),
            'pct'         => $pct,
            'overall'     => $overall,
            'grade'       => grade_equivalent($overall),
            'missing'     => $missing,
        ];
    }
    return $out;
}

// ---------- Biometric database helpers ----------
function bio_db(): PDO {
    static $bio = null;
    if ($bio) return $bio;
    $bio = new PDO('mysql:host=' . BIO_DB_HOST . ';dbname=' . BIO_DB_NAME . ';charset=utf8mb4', BIO_DB_USER, BIO_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $bio;
}

// "Dela Cruz, Juan" always means Last, First. Without a comma, $fmt decides:
//   last_first = first word is the surname; first_last = last word is the surname.
function split_name(string $n, string $fmt): array {
    $n = trim(preg_replace('/\s+/', ' ', $n));
    if (strpos($n, ',') !== false) {
        [$l, $f] = array_map('trim', explode(',', $n, 2));
        return [$l, $f];
    }
    if ($fmt === 'last_first') {
        $p = explode(' ', $n, 2);
        return [$p[0], $p[1] ?? ''];
    }
    $p = explode(' ', $n);
    $l = array_pop($p);
    return [$l, implode(' ', $p)];
}

// serialnumber is a DOUBLE in the biometric table; print it without 2.0E+6 style notation
function bio_student_no($v): string {
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    return floor($f) == $f ? sprintf('%.0f', $f) : (string)$v;
}

// Copies every registered student of a biometric department into a grading section.
// Returns [added_or_updated, skipped].
function bio_sync_section(PDO $pdo, int $secId, int $dept, string $fmt): array {
    $q = bio_db()->prepare("SELECT username, serialnumber, fingerprint_id FROM users
                            WHERE department_id = ? AND del_fingerid = 0 AND username <> '' ORDER BY username");
    $q->execute([$dept]);
    $ins = $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name, fingerprint_id) VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name), fingerprint_id = VALUES(fingerprint_id)');
    $added = 0; $skipped = 0;
    foreach ($q->fetchAll() as $u) {
        [$last, $first] = split_name((string)$u['username'], $fmt);
        $fp = (int)$u['fingerprint_id'];
        $no = bio_student_no($u['serialnumber']);
        if ($no === '') $no = 'FP' . $fp;
        try { $ins->execute([$secId, $no, $last, $first, ($fp >= 1 && $fp <= 1000) ? $fp : null]); $added++; }
        catch (PDOException $e) { $skipped++; }
    }
    return [$added, $skipped];
}

// Reads year level / section / subject out of a biometric department name. Two formats work:
//   1) "BSIT 1 | C | OS101 | Operating Systems"   year | section | subject code | subject name (last two optional)
//   2) "3rd Year SIA101-F"                         year, subject code, then -section
//   3) "BSIT 1C", "BSIT 1 - C" or "BSIT 1 C"       year level followed by the section letter(s)
function parse_department(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $o = ['year_level' => '', 'section_name' => '', 'subject_code' => '', 'subject_name' => ''];
    if (strpos($name, '|') !== false) {
        $p = array_map('trim', explode('|', $name));
        return ['year_level' => $p[0] ?? '', 'section_name' => $p[1] ?? '', 'subject_code' => $p[2] ?? '', 'subject_name' => $p[3] ?? ''];
    }
    // "3rd Year SIA101-F": year, then subject code (letters + digits), then the section after a dash
    if (preg_match('/^(.+?)\s+([A-Za-z]{2,}\s?\d{2,}[A-Za-z]?)\s*[-_ ]\s*([A-Za-z0-9]+)$/', $name, $m)) {
        return ['year_level' => trim($m[1]), 'section_name' => $m[3], 'subject_code' => preg_replace('/\s+/', '', $m[2]), 'subject_name' => ''];
    }
    if (preg_match('/^(.*\d)\s*[-_ ]?\s*([A-Za-z]+)$/', $name, $m)) { $o['year_level'] = trim($m[1]); $o['section_name'] = $m[2]; return $o; }
    if (preg_match('/^(.*\S)\s+(\S+)$/', $name, $m)) { $o['year_level'] = $m[1]; $o['section_name'] = $m[2]; return $o; }
    $o['year_level'] = $name;
    return $o;
}

// "BSIT 1 - C", "bsit1c" and "BSIT 1C" all become "bsit1c" so names can be compared loosely.
function norm_key(string $s): string { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $s)); }

// Called on every scan. If the fingerprint is not in any grading section yet, find the student in the
// biometric database and add them to every grading section linked to their biometric department.
// A section that is not linked yet is linked automatically when its "year level + section" matches
// the biometric department name (for example "BSIT 1" + "C" matches the department "BSIT 1C").
// Returns ['added' => number of sections joined, 'reason' => why nothing was added ('' when fine)].
function auto_enroll(PDO $pdo, int $fp): array {
    try {
        $q = bio_db()->prepare("SELECT u.username, u.serialnumber, u.department_id, d.department_name
                                FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                WHERE u.fingerprint_id = ? AND u.del_fingerid = 0 LIMIT 1");
        $q->execute([$fp]);
        $u = $q->fetch();
        if (!$u) return ['added' => 0, 'reason' => 'fingerprint not in biometric users'];
        if (trim((string)$u['username']) === '') return ['added' => 0, 'reason' => 'biometric student has no name yet'];
        $dept = (int)$u['department_id'];
        if (!$dept) return ['added' => 0, 'reason' => 'biometric student has no department'];

        $s = $pdo->prepare('SELECT id, name_format FROM sections WHERE bio_department_id = ?');
        $s->execute([$dept]);
        $secs = $s->fetchAll();

        if (!$secs && trim((string)$u['department_name']) !== '') {          // try to link unlinked sections by name
            $pd = parse_department((string)$u['department_name']);
            $link = $pdo->prepare('UPDATE sections SET bio_department_id = ? WHERE id = ?');
            foreach ($pdo->query('SELECT id, year_level, section_name, subject_code, name_format FROM sections WHERE bio_department_id IS NULL')->fetchAll() as $c) {
                if (norm_key($c['year_level']) === norm_key($pd['year_level']) && norm_key($c['section_name']) === norm_key($pd['section_name'])
                    && ($pd['subject_code'] === '' || norm_key($c['subject_code']) === norm_key($pd['subject_code']))) {
                    $link->execute([$dept, $c['id']]);
                    $secs[] = ['id' => $c['id'], 'name_format' => $c['name_format']];
                }
            }
        }
        if (!$secs) return ['added' => 0, 'reason' => 'department "' . $u['department_name'] . '" not linked to a section'];

        $has = $pdo->prepare('SELECT 1 FROM students WHERE section_id = ? AND fingerprint_id = ?');
        $ins = $pdo->prepare('INSERT INTO students (section_id, student_no, last_name, first_name, fingerprint_id) VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name), fingerprint_id = VALUES(fingerprint_id)');
        $n = 0;
        foreach ($secs as $sec) {
            $has->execute([$sec['id'], $fp]);
            if ($has->fetchColumn()) continue;
            [$last, $first] = split_name((string)$u['username'], $sec['name_format']);
            $no = bio_student_no($u['serialnumber']);
            if ($no === '') $no = 'FP' . $fp;
            try { $ins->execute([$sec['id'], $no, $last, $first, $fp]); $n++; } catch (PDOException $e) { /* ignore */ }
        }
        return ['added' => $n, 'reason' => ''];
    } catch (Throwable $e) {
        error_log('[grading] auto_enroll: ' . $e->getMessage());
        return ['added' => 0, 'reason' => 'error reading biometric database'];
    }
}
