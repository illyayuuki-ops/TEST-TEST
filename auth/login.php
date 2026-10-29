<?php
require __DIR__ . '/../config/app.php';
// Already signed in: a student who scans an office QR jumps straight to a new ticket for that office; everyone else goes home
$preview = isset($_GET['preview']) && in_array($_SESSION['user']['role'] ?? '', ['admin'], true);   // staff checking a QR code: show the page instead of bouncing to their dashboard
if (isset($_SESSION['user']) && !$preview) {
    $qo0 = strtolower(trim((string)($_GET['office'] ?? '')));
    if (is_filer($_SESSION['user']) && valid_code($qo0)) {
        $qoff0 = get_office($qo0);
        if ($qoff0 && $qoff0['status'] === 'active') { $_SESSION['office_code'] = $qo0; redirect('student/new_ticket.php'); }
    }
    redirect(home_for($_SESSION['user']['role']));
}

$error = ''; $showResend = false;
$f = ['office' => '', 'ident' => ''];   // office is only ever set by an office QR code; otherwise students pick it inside their profile
// Arrived by scanning an office QR code (?office=code): pre-select that office, but only if it exists and is active
$qo = strtolower(trim((string)($_GET['office'] ?? ''))); $fromQr = false;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && valid_code($qo)) { $qoff = get_office($qo); if ($qoff && $qoff['status'] === 'active') { $f['office'] = $qo; $fromQr = true; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f['office'] = trim($_POST['office_code'] ?? '');
    $f['ident']  = trim($_POST['ident'] ?? '');
    $fromQr      = ($_POST['qr'] ?? '') === '1';
    $pass        = (string)($_POST['password'] ?? '');

    if ($f['ident'] === '' || $pass === '') {
        $error = 'Enter your username (or email) and password.';
    } elseif (strpos($f['ident'], '@') !== false && !student_email_ok($f['ident'])) {
        // Typed an email: it must be studentnumber@school-domain. Anything else stops here (staff sign in with their username).
        $error = STUDENT_EMAIL_MSG;
    } else {
        $st = $pdo->prepare('SELECT * FROM users WHERE username=? OR email=? LIMIT 1');
        $st->execute([$f['ident'], $f['ident']]);
        $user = $st->fetch();
        $actor = ['username' => $f['ident'], 'role' => 'student', 'office_code' => $f['office'] ?: null];

        if (!$user) {
            $error = 'Invalid username or password.';
            audit('LOGIN_FAILED', 'Unknown account "' . $f['ident'] . '"', $actor);
        } elseif (!in_array($user['role'], ['student', 'alumni'], true) && !($user['role'] === 'admin' && $user['office_code'] === STAFF_OFFICE)) {
            // Only students and the Student Affairs admin can sign in. Same message as a wrong password.
            $error = 'Invalid username or password.';
            audit('LOGIN_FAILED', 'Account not allowed to sign in', ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'office_code' => $user['office_code']]);
        } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
            $error = "Account locked after too many attempts. Try again in $mins minute(s).";
        } elseif (!password_verify($pass, $user['password'])) {
            $n = $user['failed_attempts'] + 1;
            if ($n >= MAX_ATTEMPTS) {
                $pdo->prepare('UPDATE users SET failed_attempts=0, locked_until=DATE_ADD(NOW(), INTERVAL ' . LOCK_MINUTES . ' MINUTE) WHERE id=?')->execute([$user['id']]);
                $error = 'Too many failed attempts. Account locked for ' . LOCK_MINUTES . ' minutes.';
                audit('ACCOUNT_LOCKED', 'Locked after ' . MAX_ATTEMPTS . ' failed attempts', ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'office_code' => $user['office_code']]);
            } else {
                $pdo->prepare('UPDATE users SET failed_attempts=? WHERE id=?')->execute([$n, $user['id']]);
                $error = 'Invalid username or password. ' . (MAX_ATTEMPTS - $n) . ' attempt(s) left.';
                audit('LOGIN_FAILED', 'Wrong password', ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'office_code' => $user['office_code']]);
            }
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is deactivated. Contact your office admin.';
        } elseif ($user['role'] !== 'admin' && ($user['verification'] ?? 'verified') === 'email_pending') {
            $error = 'Confirm your school email first. Open the link we sent to ' . $user['email'] . '.';
            $showResend = true;
        } else {
            // credentials are right and the account is a verified student/alumni
            {
                $pdo->prepare('UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?')->execute([$user['id']]);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'],
                                     'role' => $user['role'], 'office_code' => $user['office_code']];
                if ($user['role'] === 'admin') {            // detected automatically: the admin goes to the admin side
                    unset($_SESSION['office_code']);
                    audit('LOGIN', 'Staff signed in');
                    redirect(home_for('admin'));
                }
                // Office is chosen in the student profile. Only an office QR code pre-selects one.
                $qOff = ($fromQr && valid_code($f['office'])) ? get_office($f['office']) : null;
                if ($qOff && $qOff['status'] === 'active') $_SESSION['office_code'] = $qOff['office_code']; else unset($_SESSION['office_code']);
                audit('LOGIN', 'Signed in');
                redirect($fromQr ? 'student/new_ticket.php' : home_for($user['role']));   // QR visitors land on the ticket form
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · <?= APP_NAME_A . APP_NAME_B ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head><body class="auth-body">
<div class="stage">
<div class="hero-m"><img width="50" height="50" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"><div><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div></div>
<div class="auth-card">
  <div class="auth-logo"><img width="92" height="92" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"></div>
  <div class="auth-brand"><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div>
  <h1 class="form-title">Welcome <span>back</span></h1>
  <p class="form-sub">Sign in with your username or email. We'll take you to the right place automatically.</p>

  <?php if (($_GET['pending'] ?? '') === 'email'): ?><div class="alert ok"><i class="fa-solid fa-envelope-circle-check"></i><span>Account created. We sent a confirmation link to your school email. Open it to activate your account, then sign in.<?= isset($_GET['mailfail']) ? ' (We could not send the email right now. Use "Resend confirmation" below or contact the system admin.)' : '' ?></span></div><?php endif; ?>
  <?php if (isset($_GET['verified'])): ?><div class="alert ok"><i class="fa-solid fa-circle-check"></i><span>Email confirmed. You can sign in now.</span></div><?php endif; ?>
  <?php if (($_GET['verify'] ?? '') === 'bad'): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>That confirmation link is invalid or has expired. Request a new one below.</span></div><?php endif; ?>
  <?php if (($_GET['verify'] ?? '') === 'invalid'): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>Confirmation failed: this account's school email or student ID is invalid or they do not match. The email must be studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?> and the student ID must be the same number. Please <a href="<?= e(url('auth/register.php')) ?>">register again</a> with the correct details.</span></div><?php endif; ?>
  <?php if (isset($_GET['registered'])): ?><div class="alert ok"><i class="fa-solid fa-circle-check"></i>Account created. Sign in below.</div><?php endif; ?>
  <?php if (isset($_GET['pw'])): ?><div class="alert ok"><i class="fa-solid fa-circle-check"></i>Password updated. Sign in again.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($error) ?><?= $showResend ? ' <a href="' . e(url('auth/resend_verification.php') . ($f['office'] ? '?office=' . $f['office'] : '')) . '">Resend confirmation</a>' : '' ?></span></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
        <input type="hidden" name="qr" value="<?= $fromQr ? '1' : '0' ?>">

    <input type="hidden" name="office_code" value="<?= e($f['office']) ?>">

    <div class="field">
      <label for="ident">Username or email</label>
      <div class="input-wrap"><i class="fa-solid fa-user ic"></i>
        <input type="text" id="ident" name="ident" value="<?= e($f['ident']) ?>" placeholder="Username or studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>" required autofocus></div>
      <div class="hint">Using your email? It must look like studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>.</div>
    </div>
    <div class="field">
      <label for="pw">Password</label>
      <div class="input-wrap"><i class="fa-solid fa-lock ic"></i>
        <input type="password" id="pw" name="password" placeholder="Enter your password" autocomplete="current-password" required></div>
    </div>
    <div style="text-align:right;margin:-4px 0 12px"><a id="fpA" href="<?= url('auth/forgot_password.php') ?><?= $f['office'] ? '?office=' . e($f['office']) : '' ?>" style="font-size:13px">Forgot password?</a></div>
    <button class="btn btn-primary btn-block" type="submit">Sign in <i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <div class="auth-foot">New student? <a id="regA" href="<?= url('auth/register.php') ?><?= $f['office'] ? '?office=' . e($f['office']) : '' ?>">Create an account</a> · <a id="resA" href="<?= url('auth/resend_verification.php') ?><?= $f['office'] ? '?office=' . e($f['office']) : '' ?>">Resend confirmation</a></div>
</div>
<div class="auth-credit"><img height="30" src="<?= url('assets/img/intel-logo.png') ?>" alt="INTEL"><span>Powered by <b>INTEL</b></span></div>
</div>
<script>
(function(){
  var id=document.getElementById('ident'), EM=<?= json_encode(STUDENT_EMAIL_MSG) ?>, re=/^[0-9]+(?:-[0-9]+)*@<?= preg_quote(SCHOOL_EMAIL_DOMAIN) ?>$/i;
  function check(){ var v=id.value.trim(); id.setCustomValidity(v.indexOf('@')>-1 && !re.test(v) ? EM : ''); }
  id.addEventListener('input',check); id.form.addEventListener('submit',check); check();
})();
</script>
<script src="<?= url('assets/js/pwtoggle.js') ?>"></script>
</body></html>
