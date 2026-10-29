<?php
require __DIR__ . '/../config/app.php';
$preview = isset($_GET['preview']) && in_array($_SESSION['user']['role'] ?? '', ['admin'], true);   // staff checking a QR code
if (isset($_SESSION['user']) && !$preview) redirect(home_for($_SESSION['user']['role']));

$qo = strtolower(trim((string)($_GET['office'] ?? ''))); $qo = (valid_code($qo) && get_office($qo)) ? $qo : '';   // carried over from an office QR code
$err = []; $f = ['full_name' => '', 'username' => '', 'email' => '', 'school_id' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['full_name', 'username', 'email', 'school_id'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    $f['email'] = strtolower($f['email']);
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');

    if (mb_strlen($f['full_name']) < 3) $err[] = 'Enter your full name.';
    if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $f['username'])) $err[] = 'Username: 3–30 letters, numbers, dot or underscore.';
    $emailOk = student_email_ok($f['email']);
    if (!$emailOk) $err[] = STUDENT_EMAIL_MSG;
    $idOk = student_id_ok($f['school_id']);
    if (!$idOk) $err[] = STUDENT_ID_MSG;
    elseif ($emailOk && student_num_from_email($f['email']) !== $f['school_id']) $err[] = STUDENT_MATCH_MSG;
    if (strlen($pw) < 8) $err[] = 'Password must be at least 8 characters.';
    if ($pw !== $pw2) $err[] = 'Passwords do not match.';

    // Existing account with the same username/email: only an UNVERIFIED student sign-up may be replaced (typo'd email, lost link)
    $replace = [];
    if (!$err) {
        $c = $pdo->prepare('SELECT id, role, verification FROM users WHERE username=? OR email=?');
        $c->execute([$f['username'], $f['email']]);
        foreach ($c->fetchAll() as $row) {
            if ($row['verification'] === 'email_pending' && $row['role'] === 'student') $replace[] = (int)$row['id'];
            else { $err[] = 'That username or email is already registered.'; break; }
        }
    }
    if (!$err) {
        try {
            if ($replace) $pdo->prepare('DELETE FROM users WHERE id IN (' . implode(',', $replace) . ')')->execute();
            $pdo->prepare('INSERT INTO users (username,email,full_name,school_id,password,role,verification) VALUES (?,?,?,?,?,?,?)')
                ->execute([$f['username'], $f['email'], $f['full_name'], $f['school_id'], password_hash($pw, PASSWORD_DEFAULT), 'student', 'email_pending']);
            $newId = (int)$pdo->lastInsertId();
            audit('REGISTER', 'New student account (awaiting email confirmation)',
                  ['id' => $newId, 'username' => $f['username'], 'role' => 'student']);
            $sent = send_verification_email($pdo, $newId, $f['email'], $f['full_name'], $qo);
            redirect('auth/login.php?pending=email' . ($sent ? '' : '&mailfail=1') . ($qo ? '&office=' . $qo : ''));
        } catch (PDOException | RuntimeException $ex) {
            // Show the real reason instead of a blank 500 page (usually an out-of-date users table: run database/upgrade_verification.sql)
            error_log('Register failed: ' . $ex->getMessage());
            $err[] = 'Could not create the account (' . $ex->getMessage() . '). Tell the system admin.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create account · <?= APP_NAME_A . APP_NAME_B ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head><body class="auth-body">
<div class="stage">
<div class="hero-m"><img width="50" height="50" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"><div><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div></div>
<div class="auth-card wide">
  <div class="auth-logo"><img width="92" height="92" src="<?= url('assets/img/lcct-seal.png') ?>" alt="La Consolacion College Tanauan seal"></div>
  <div class="auth-brand"><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>La Consolacion College Tanauan</small></div>
  <h1 class="form-title">Create your <span>account</span></h1>
  <p class="form-sub">For students. You'll choose which office to report to when you create a ticket.</p>
  <div class="alert warn" id="verifyNote"><i class="fa-solid fa-shield-halved"></i><span>Students must use their school email (@<?= e(SCHOOL_EMAIL_DOMAIN) ?>) and confirm it with the link we email you.</span></div>
  <?php if ($err): ?><div class="alert err"><i class="fa-solid fa-circle-exclamation"></i><span><?= implode('<br>', array_map('e', $err)) ?></span></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="field"><label>Full name</label><input type="text" name="full_name" value="<?= e($f['full_name']) ?>" required></div>
    <div class="row2">
      <div class="field"><label>Username</label><input type="text" name="username" value="<?= e($f['username']) ?>" required></div>
      <div class="field"><label>Student ID</label><input type="text" name="school_id" id="idIn" value="<?= e($f['school_id']) ?>" placeholder="e.g. 24-12803" inputmode="numeric" pattern="[0-9]+(-[0-9]+)*" title="Numbers only, the same number as in your school email" required></div>
    </div>
    <div class="field"><label id="emailLbl">School email</label><input type="email" name="email" id="emailIn" value="<?= e($f['email']) ?>" placeholder="studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>" pattern="[0-9]+(-[0-9]+)*@<?= e(str_replace('.', '\\.', SCHOOL_EMAIL_DOMAIN)) ?>" title="Format: studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?>" required>
      <div class="hint">Format: studentnumber@<?= e(SCHOOL_EMAIL_DOMAIN) ?> (example: 24-12803@<?= e(SCHOOL_EMAIL_DOMAIN) ?>). Your Student ID must be that same number. We will send a confirmation link to it.</div></div>
    <div class="row2">
      <div class="field"><label>Password</label><input type="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required></div>
      <div class="field"><label>Confirm</label><input type="password" name="password2" placeholder="Re-enter password" autocomplete="new-password" required></div>
    </div>
    <div class="hint" style="margin:-4px 0 16px">At least 8 characters.</div>
    <button class="btn btn-primary btn-block" type="submit">Create account</button>
  </form>
  <div class="auth-foot">Already registered? <a href="<?= url('auth/login.php') ?><?= $qo ? '?office=' . e($qo) : '' ?>">Sign in</a></div>
</div>
<div class="auth-credit"><img height="30" src="<?= url('assets/img/intel-logo.png') ?>" alt="INTEL"><span>Powered by <b>INTEL</b></span></div>
</div>
<script>
(function(){
  var f=document.querySelector('form'), em=document.getElementById('emailIn'), id=document.getElementById('idIn');
  var EM=<?= json_encode(STUDENT_EMAIL_MSG) ?>, ID=<?= json_encode(STUDENT_ID_MSG) ?>, MT=<?= json_encode(STUDENT_MATCH_MSG) ?>;
  var emRe=/^[0-9]+(?:-[0-9]+)*@<?= str_replace('.', '\\.', e(SCHOOL_EMAIL_DOMAIN)) ?>$/i, idRe=/^[0-9]+(?:-[0-9]+)*$/;
  function check(){
    var ev=em.value.trim(), iv=id.value.trim(), eOk=emRe.test(ev), iOk=idRe.test(iv);
    em.setCustomValidity(eOk||ev===''?'':EM);
    id.setCustomValidity(iv===''?'':(!iOk?ID:(eOk&&ev.split('@')[0]!==iv?MT:'')));
  }
  em.addEventListener('input',check); id.addEventListener('input',check); f.addEventListener('submit',check);
  check();
})();
</script>
<script src="<?= url('assets/js/pwtoggle.js') ?>"></script>
</body></html>
