<?php
// Login with a username + password. Users are in the database table `Users` (see users.sql).
require __DIR__ . '/includes/bootstrap.php';
$error = '';
if (!empty($_SESSION['auth'])) redirect('index.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = strtolower(trim((string)($_POST['username'] ?? '')));
    $user = null;
    try {
        $st = db()->prepare('SELECT * FROM Users WHERE Username = ?');
        $st->execute([$u]); $user = $st->fetch() ?: null;
    } catch (PDOException $ex) { $error = 'The Users table is missing. Import users.sql in phpMyAdmin first.'; }
    // always run password_verify so a wrong username takes as long as a wrong password
    $ok = password_verify((string)($_POST['password'] ?? ''), $user['Password_hash'] ?? '$2y$10$usesomesillystringforsaltOnlyAbcdefghijklmnopqrstuvwxyzabc');
    if ($error === '' && $user && $ok) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true; $_SESSION['user'] = $u; $_SESSION['role'] = $user['Role']; $_SESSION['name'] = $user['Full_name'];
        $_SESSION['library'] = trim((string)($user['Library'] ?? '')) ?: 'All libraries';   // empty = all libraries
        try { db()->prepare('UPDATE Users SET Last_login = NOW() WHERE Username = ?')->execute([$u]); } catch (PDOException $ex) { /* column added by 10_files_utilities.sql */ }
        redirect('index.php');
    }
    if ($error === '') { usleep(800000); $error = 'Wrong username or password.'; }   // slow down password guessing
}
header('Cache-Control: no-store, max-age=0');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · La Consolacion College Tanauan</title><link rel="icon" type="image/png" href="assets/img/seal.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,650;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>"></head>
<body class="login-page"><div class="stage">
<div class="hero-text"><img class="hero-seal" src="assets/img/seal.png" alt="La Consolacion College Tanauan seal">
<h2>La Consolacion<br>College Tanauan</h2><h3 class="subhead">St. Augustine Learning Resource Center</h3>
<p>Library management · please log in</p></div>
<div class="login-box"><h1>Log in</h1><p class="muted lead">Use the username and password given to you by the library.</p>
<?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="text" name="username" placeholder="Username (USN)" autocomplete="username" autofocus required><input type="password" name="password" placeholder="Password" autocomplete="current-password" required><button class="btn primary">Log in</button></form>
<p class="motto">Unity · Charity · Truth</p></div>
<div class="credit-pill"><img src="assets/img/intel.png" alt="INTEL"><span>Made by <b>INTEL</b></span></div>
</div></body></html>
