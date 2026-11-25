<?php
/**
 * HIDDEN page for creating an admin account. It is not linked from anywhere.
 * Open it by typing the address: http://localhost/grading_system/admin_setup.php
 * You need ADMIN_SETUP_KEY from config.php. After creating your admin, set
 * ADMIN_SETUP_ENABLED to false in config.php (or delete this file).
 */
require __DIR__ . '/config.php';
start_session();

if (!ADMIN_SETUP_ENABLED) { http_response_code(404); exit('Not found'); }
if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(16));

$err = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name'] ?? '');
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    if (!hash_equals($_SESSION['setup_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $err = 'Session expired. Reload the page and try again.';
    } elseif (ADMIN_SETUP_KEY === 'change-this-setup-key') {
        $err = 'First change ADMIN_SETUP_KEY in config.php to your own secret.';
    } elseif (!hash_equals(ADMIN_SETUP_KEY, (string)($_POST['key'] ?? ''))) {
        usleep(1000000);                      // slow down key guessing
        $err = 'Wrong setup key.';
    } elseif ($name === '' || $user === '' || strlen($pass) < 8) {
        $err = 'Fill every field. The password needs 8 or more characters.';
    } elseif ($pass !== (string)($_POST['password2'] ?? '')) {
        $err = 'Passwords do not match.';
    } else {
        try {
            db()->prepare("INSERT INTO teachers (full_name, username, password_hash, role) VALUES (?, ?, ?, 'admin')")
                ->execute([$name, $user, password_hash($pass, PASSWORD_DEFAULT)]);
            $done = true;
            unset($_SESSION['setup_csrf']);
        } catch (PDOException $e) {
            $err = (($e->errorInfo[1] ?? 0) == 1062) ? 'That username is already taken.' : 'Database error. Is the database imported?';
        }
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin setup</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="center">
<?php if ($done): ?>
  <div class="card auth">
    <h2>Admin account created</h2>
    <p class="muted">Now open <code>config.php</code> and set <code>ADMIN_SETUP_ENABLED</code> to <code>false</code> (or delete <code>admin_setup.php</code>), then log in from the normal login page.</p>
    <a class="btn primary block" href="index.php" style="display:block;text-align:center;text-decoration:none">Go to login</a>
  </div>
<?php else: ?>
  <form class="card auth" method="post" autocomplete="off">
    <h2>Create admin account</h2>
    <p class="muted">Hidden page. Enter the setup key from <code>config.php</code>.</p>
    <?php if ($err): ?><pre class="err-box"><?= $h($err) ?></pre><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['setup_csrf']) ?>">
    <label>Setup key<input name="key" type="password" required></label>
    <label>Full name<input name="full_name" value="<?= $h($_POST['full_name'] ?? '') ?>" required></label>
    <label>Username<input name="username" value="<?= $h($_POST['username'] ?? '') ?>" required></label>
    <label>Password (8+ characters)<input name="password" type="password" required></label>
    <label>Confirm password<input name="password2" type="password" required></label>
    <button class="btn primary block">Create admin</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
