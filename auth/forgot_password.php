<?php
require __DIR__ . '/../config/app.php';
if (isset($_SESSION['user'])) redirect(home_for($_SESSION['user']['role']));
$msg = ''; $email = ''; $err = '';
$qo = strtolower(trim((string)($_GET['office'] ?? $_POST['office'] ?? ''))); $qo = (valid_code($qo) && get_office($qo)) ? $qo : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    if (!student_email_ok($email)) $err = STUDENT_EMAIL_MSG;
    else {
    // Same answer whether or not the address exists, so this page can't be used to discover accounts.
    // Only verified, active student/alumni accounts. Cooldown: a new link is only sent if the last one is more than 2 minutes old.
    $st = $pdo->prepare("SELECT id, email, full_name, username, role FROM users WHERE email=? AND role IN ('student','alumni') AND status='active' AND verification='verified'
                         AND (reset_expires IS NULL OR reset_expires < DATE_ADD(NOW(), INTERVAL " . ((int)RESET_MINUTES - 2) . " MINUTE)) LIMIT 1");
    $st->execute([$email]);
    if ($u = $st->fetch()) {
        send_reset_email($pdo, (int)$u['id'], $u['email'], $u['full_name'], $qo);
        audit('PASSWORD_RESET_REQUESTED', 'Password reset link emailed', ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']]);
    }
    $msg = 'If that email belongs to a student or alumni account, we have sent a link to reset the password. It works for ' . (int)RESET_MINUTES . ' minutes. Check your inbox and spam folder.';
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot password · <?= APP_NAME_A . APP_NAME_B ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head><body class="auth-body">
<div class="stage">
<div class="hero-m"><img width="50" height="50" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"><div><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div></div>
<div class="auth-card">
  <div class="auth-logo"><img width="92" height="92" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"></div>
  <div class="auth-brand"><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div>
  <h1 class="form-title">Forgot <span>password?</span></h1>
  <p class="form-sub">Enter the email you registered with and we'll send you a link to choose a new password.</p>
  <?php if ($err): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert ok"><i class="fa-solid fa-circle-check"></i><span><?= e($msg) ?></span></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="office" value="<?= e($qo) ?>">
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($email) ?>" placeholder="studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>" pattern="[0-9]+(-[0-9]+)*@<?= preg_quote(SCHOOL_EMAIL_DOMAIN) ?>" title="Format: studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>" required autofocus></div>
    <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
  </form>
  <div class="auth-foot"><a href="<?= url('auth/login.php') ?><?= $qo ? '?office=' . e($qo) : '' ?>">Back to sign in</a><br><span style="font-size:12px;opacity:.8">Office staff: ask another admin in your office (Team → Reset pw) or the system admin.</span></div>
</div>
<div class="auth-credit"><img height="30" src="<?= url('assets/img/intel-logo.png') ?>" alt="INTEL"><span>Powered by <b>INTEL</b></span></div>
</div>
</body></html>
