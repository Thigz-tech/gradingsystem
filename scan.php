<?php
/**
 * Receives a fingerprint scan from your biometric attendance system and records attendance.
 *
 * POST (or GET) parameters:
 *   token           required  - must equal DEVICE_TOKEN in config.php
 *   fingerprint_id  required  - 1..1000
 *   slot            optional  - "am" or "pm" (default: before 12:00 = am, otherwise pm)
 *   direction       optional  - "in" or "out" (default: first scan of the slot = in, later scan = out)
 *   time            optional  - HH:MM:SS (default: server time now)
 */
require __DIR__ . '/config.php';

$token = $_POST['token'] ?? $_GET['token'] ?? '';
if (!hash_equals(DEVICE_TOKEN, (string)$token)) json_out(['ok' => false, 'error' => 'Invalid token'], 403);

$fp = (int)($_POST['fingerprint_id'] ?? $_GET['fingerprint_id'] ?? 0);
if ($fp < 1 || $fp > 1000) json_out(['ok' => false, 'error' => 'fingerprint_id must be 1-1000'], 422);

$time = $_POST['time'] ?? $_GET['time'] ?? '';
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) $time = date('H:i:s');
if (strlen($time) === 5) $time .= ':00';

$slot = strtolower($_POST['slot'] ?? $_GET['slot'] ?? '');
if (!in_array($slot, ['am', 'pm'], true)) $slot = ((int)substr($time, 0, 2) < 12) ? 'am' : 'pm';

$dir = strtolower($_POST['direction'] ?? $_GET['direction'] ?? '');
$date = date('Y-m-d');
$in = $slot . '_in';
$out = $slot . '_out';

$pdo = db();

// Newly enrolled student? Add them to the grading section(s) linked to their biometric department.
$enroll = auto_enroll($pdo, $fp);

$q = $pdo->prepare('SELECT id, section_id, first_name, last_name FROM students WHERE fingerprint_id = ?');
$q->execute([$fp]);
$students = $q->fetchAll();

$log = $pdo->prepare('INSERT INTO attendance_scans (fingerprint_id, scanned_at, result) VALUES (?, ?, ?)');

if (!$students) {
    $log->execute([$fp, "$date $time", 'not enrolled: ' . ($enroll['reason'] ?: 'no matching student')]);
    json_out(['ok' => false, 'error' => 'Fingerprint ID not enrolled in any section', 'reason' => $enroll['reason']], 404);
}

$results = [];
foreach ($students as $s) {
    $pdo->prepare('INSERT IGNORE INTO class_sessions (section_id, session_date) VALUES (?, ?)')
        ->execute([$s['section_id'], $date]);
    $q = $pdo->prepare('SELECT id FROM class_sessions WHERE section_id = ? AND session_date = ?');
    $q->execute([$s['section_id'], $date]);
    $sessionId = (int)$q->fetchColumn();

    $q = $pdo->prepare('SELECT * FROM attendance WHERE session_id = ? AND student_id = ?');
    $q->execute([$sessionId, $s['id']]);
    $row = $q->fetch();

    $action = 'in';
    if (!$row) {
        $col = ($dir === 'out') ? $out : $in;
        $pdo->prepare("INSERT INTO attendance (session_id, student_id, status, $col) VALUES (?, ?, 'present', ?)")
            ->execute([$sessionId, $s['id'], $time]);
        $action = ($dir === 'out') ? 'out' : 'in';
    } elseif ($dir === 'in' || $dir === 'out') {
        $col = $dir === 'in' ? $in : $out;
        $pdo->prepare("UPDATE attendance SET status = 'present', $col = ? WHERE id = ?")->execute([$time, $row['id']]);
        $action = $dir;
    } elseif (empty($row[$in])) {
        $pdo->prepare("UPDATE attendance SET status = 'present', $in = ? WHERE id = ?")->execute([$time, $row['id']]);
    } elseif (strtotime($time) - strtotime($row[$in]) >= 60) {
        $pdo->prepare("UPDATE attendance SET status = 'present', $out = ? WHERE id = ?")->execute([$time, $row['id']]);
        $action = 'out';
    } else {
        $action = 'duplicate';   // scanned again within 60 seconds of time-in
    }
    $results[] = ['student' => $s['last_name'] . ', ' . $s['first_name'], 'section_id' => (int)$s['section_id'], 'action' => $action];
}

$log->execute([$fp, "$date $time", 'ok']);
json_out(['ok' => true, 'slot' => $slot, 'date' => $date, 'time' => $time, 'records' => $results]);
