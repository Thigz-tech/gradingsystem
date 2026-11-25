<?php
/**
 * Serves a student's photo from the biometric attendance database (users.photo).
 * Only a logged-in instructor can load it, and only for students in their own sections.
 * Usage: photo.php?student_id=<grading system student id>
 */
require __DIR__ . '/config.php';
start_session();
$tid = (int)($_SESSION['teacher_id'] ?? 0);
session_write_close();                       // don't block other image requests on the session lock
if (!$tid) { http_response_code(401); exit; }

$sid = (int)($_GET['student_id'] ?? 0);

try {
    if (is_admin(db(), $tid)) {              // admins may view every student's photo
        $q = db()->prepare('SELECT fingerprint_id FROM students WHERE id = ?');
        $q->execute([$sid]);
    } else {
        $q = db()->prepare('SELECT st.fingerprint_id FROM students st
                            JOIN sections s ON s.id = st.section_id
                            WHERE st.id = ? AND s.teacher_id = ?');
        $q->execute([$sid, $tid]);
    }
    $fp = (int)$q->fetchColumn();
    if (!$fp) { http_response_code(404); exit; }

    $q = bio_db()->prepare('SELECT photo, photo_type FROM users WHERE fingerprint_id = ? AND del_fingerid = 0 LIMIT 1');
    $q->execute([$fp]);
    $r = $q->fetch();
    $img = $r['photo'] ?? null;
    if ($img === null || $img === '') { http_response_code(404); exit; }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $detect = function (string $data) use ($allowed): string {
        if (!function_exists('finfo_buffer')) return '';
        $m = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        return in_array($m, $allowed, true) ? $m : '';
    };

    $mime = $detect($img);
    if ($mime === '') {                      // maybe the photo was stored as base64 text / a data: URI
        $txt = preg_replace('#^data:image/[a-z+.-]+;base64,#i', '', trim($img));
        if (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $txt)) {
            $dec = base64_decode($txt, true);
            if ($dec !== false && $detect($dec) !== '') { $img = $dec; $mime = $detect($dec); }
        }
    }
    if ($mime === '') {                      // last resort: trust photo_type
        $t = strtolower(trim((string)($r['photo_type'] ?? '')));
        if ($t !== '' && strpos($t, '/') === false) $t = 'image/' . $t;
        if ($t === 'image/jpg') $t = 'image/jpeg';
        if (in_array($t, $allowed, true)) $mime = $t;
    }
    if ($mime === '') { http_response_code(404); exit; }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($img));
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');
    echo $img;
} catch (Throwable $e) {
    error_log('[grading] photo: ' . $e->getMessage());
    http_response_code(500);
}
