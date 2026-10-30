<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('student', 'admin');
// Guidance-specific experience; other offices retain their existing account policy.
if (g_ready() && (g_staff($u) !== null || (is_filer($u) && ($_GET['from'] ?? '') === 'guidance'))) {
    define('GD_PASSWORD_PAGE', true);
    require __DIR__.'/../guidance/password-page.php';
    exit;
}


$st = $pdo->prepare('SELECT password, must_change_password FROM users WHERE id=?');
$st->execute([$u['id']]);
$row = $st->fetch();
$forced = (bool)$row['must_change_password'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $new2 = (string)($_POST['new2'] ?? '');
    if (!password_verify($cur, $row['password'])) flash('Current password is incorrect.', 'err');
    elseif (strlen($new) < 8) flash('New password must be at least 8 characters.', 'err');
    elseif ($new !== $new2) flash('New passwords do not match.', 'err');
    elseif ($new === $cur) flash('New password must be different from the current one.', 'err');
    else {
        $pdo->prepare('UPDATE users SET password=?, must_change_password=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        audit('PASSWORD_CHANGED', 'Changed own password');
        if (g_ready()) {gq('DELETE FROM gd_sessions WHERE user_id=?',[$u['id']]);$_SESSION=[];session_regenerate_id(true);redirect('auth/login.php');}
        if ($forced) { flash('Password updated. You can now use the system.'); redirect(home_for($u['role'])); }
        flash('Password updated.');
    }
    redirect('auth/change_password.php');
}
page_start('Change password', $forced ? 'You must set a new password before continuing' : 'Keep your account secure');
?>
<?php if ($forced): ?><div class="alert warn"><i class="fa-solid fa-triangle-exclamation"></i>This account uses a default password. Choose a new one to continue.</div><?php endif; ?>
<div class="card" style="max-width:480px">
  <form method="post"><?= csrf_field() ?>
    <div class="field"><label>Current password</label><input type="password" name="current" placeholder="Enter your current password" autocomplete="current-password" required></div>
    <div class="field"><label>New password</label><input type="password" name="new" placeholder="At least 8 characters" autocomplete="new-password" required></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="new2" placeholder="Re-enter new password" autocomplete="new-password" required></div>
    <button class="btn btn-primary" type="submit">Update password</button>
  </form>
</div>
<?php page_end();
