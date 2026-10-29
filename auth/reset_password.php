<?php
require __DIR__ . '/../config/app.php';
if (isset($_SESSION['user'])) redirect(home_for($_SESSION['user']['role']));
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$qo = strtolower(trim((string)($_GET['office'] ?? $_POST['office'] ?? ''))); $qo = (valid_code($qo) && get_office($qo)) ? $qo : '';
$user = null; $err = '';
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = $pdo->prepare("SELECT id, username, role, office_code FROM users WHERE reset_token=? AND reset_expires > NOW() AND role IN ('student','alumni') AND status='active' LIMIT 1");
    $st->execute([hash('sha256', $token)]);
    $user = $st->fetch() ?: null;
}
if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
    if (strlen($new) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($new !== $new2) $err = 'The two passwords do not match.';
    else {
        // one-time use: the token is cleared; also lifts any lockout from failed attempts
        $pdo->prepare('UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL, must_change_password=0, failed_attempts=0, locked_until=NULL WHERE id=?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        audit('PASSWORD_RESET', 'Password reset using emailed link', ['id' => (int)$user['id'], 'username' => $user['username'], 'role' => $user['role']]);
        redirect('auth/login.php?pw=1' . ($qo ? '&office=' . $qo : ''));
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset password · <?= APP_NAME_A . APP_NAME_B ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head><body class="auth-body">
<div class="stage">
<div class="hero-m"><img width="50" height="50" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"><div><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div></div>
<div class="auth-card">
  <div class="auth-logo"><img width="92" height="92" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"></div>
  <div class="auth-brand"><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div>
  <h1 class="form-title">Choose a <span>new password</span></h1>
  <?php if (!$user): ?>
    <div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span>This reset link is invalid, already used, or has expired.</span></div>
    <a class="btn btn-primary btn-block" href="<?= url('auth/forgot_password.php') ?><?= $qo ? '?office=' . e($qo) : '' ?>">Request a new link</a>
  <?php else: ?>
    <p class="form-sub">Account: <b><?= e($user['username']) ?></b></p>
    <?php if ($err): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span><?= e($err) ?></span></div><?php endif; ?>
    <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="office" value="<?= e($qo) ?>">
      <div class="field"><label for="pw">New password</label><div class="input-wrap"><i class="fa-solid fa-lock ic"></i><input type="password" id="pw" name="new" placeholder="At least 8 characters" autocomplete="new-password" required autofocus></div></div>
      <div class="field"><label for="pw2">Confirm new password</label><div class="input-wrap"><i class="fa-solid fa-lock ic"></i><input type="password" id="pw2" name="new2" placeholder="Re-enter new password" autocomplete="new-password" required></div></div>
      <button class="btn btn-primary btn-block" type="submit">Save new password</button>
    </form>
  <?php endif; ?>
  <div class="auth-foot"><a href="<?= url('auth/login.php') ?><?= $qo ? '?office=' . e($qo) : '' ?>">Back to sign in</a></div>
</div>
<div class="auth-credit"><img height="30" src="<?= url('assets/img/intel-logo.png') ?>" alt="INTEL"><span>Powered by <b>INTEL</b></span></div>
</div>
</body></html>
