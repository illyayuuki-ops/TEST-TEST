<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code']; $office = get_office($oc);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'add') {
        $name = trim($_POST['full_name'] ?? ''); $un = trim($_POST['username'] ?? ''); $em = trim($_POST['email'] ?? ''); $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($name) < 3 || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $un) || !filter_var($em, FILTER_VALIDATE_EMAIL)) flash('Check the name, username (3–30 letters/numbers/._) and email.', 'err');
        elseif (strlen($pw) < 8) flash('Temporary password must be at least 8 characters.', 'err');
        else {
            $c = $pdo->prepare('SELECT 1 FROM users WHERE username=? OR email=?'); $c->execute([$un, $em]);
            if ($c->fetch()) flash('That username or email is already taken.', 'err');
            else {
                $pdo->prepare("INSERT INTO users (username,email,full_name,password,role,office_code,must_change_password) VALUES (?,?,?,?, 'admin', ?, 1)")
                    ->execute([$un, $em, $name, password_hash($pw, PASSWORD_DEFAULT), $oc]);
                audit('ADMIN_ADDED', "Added admin $un to $oc"); flash("Admin $un added. They must change the temporary password on first sign-in.");
            }
        }
    } elseif (in_array($a, ['toggle', 'reset'], true)) {
        $tid = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin' AND office_code=?"); $s->execute([$tid, $oc]); $t = $s->fetch();   // same-office admins only
        if (!$t) flash('Admin not found.', 'err');
        elseif ($a === 'toggle') {
            if ($tid === $u['id']) flash('You cannot deactivate your own account.', 'err');
            else {
                $new = $t['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$new, $tid]);
                audit('ADMIN_' . strtoupper($new), "{$t['username']} set to $new"); flash("{$t['username']} is now $new.");
            }
        } else {
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 8) flash('Temporary password must be at least 8 characters.', 'err');
            else {
                $pdo->prepare('UPDATE users SET password=?, must_change_password=1, failed_attempts=0, locked_until=NULL WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $tid]);
                audit('ADMIN_PW_RESET', "Reset password of {$t['username']}"); flash("Password reset for {$t['username']}. They must change it on next sign-in.");
            }
        }
    }
    redirect('admin/team.php');
}
$team = $pdo->prepare("SELECT * FROM users WHERE role='admin' AND office_code=? ORDER BY created_at"); $team->execute([$oc]); $team = $team->fetchAll();
page_start('Office team', e($office['office_name']) . ' administrators', '<button class="btn btn-primary" onclick="openModal(\'addM\')"><i class="fa-solid fa-user-plus"></i>Add admin</button>');
?>
<div class="card"><div class="table-wrap"><table>
  <thead><tr><th>Admin</th><th>Email</th><th>Status</th><th>Added</th><th></th></tr></thead><tbody>
  <?php foreach ($team as $m): ?><tr>
    <td><b><?= e($m['full_name']) ?></b><?= $m['id'] === $u['id'] ? ' <span class="badge st-open">you</span>' : '' ?><div class="sub"><?= e($m['username']) ?></div></td>
    <td class="mono"><?= e($m['email']) ?></td>
    <td><?= $m['status'] === 'active' ? '<span class="badge st-resolved">Active</span>' : '<span class="badge st-closed">Inactive</span>' ?><?= $m['must_change_password'] ? ' <span class="badge st-inprogress">temp pw</span>' : '' ?></td>
    <td class="mono"><?= date('M j, Y', strtotime($m['created_at'])) ?></td>
    <td style="text-align:right;white-space:nowrap">
      <button class="btn btn-sm" onclick="document.getElementById('rid').value=<?= $m['id'] ?>;document.getElementById('rname').textContent='<?= e($m['username']) ?>';openModal('resetM')"><i class="fa-solid fa-key"></i>Reset pw</button>
      <?php if ($m['id'] !== $u['id']): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $m['id'] ?>">
        <button class="btn btn-sm <?= $m['status'] === 'active' ? 'btn-danger' : '' ?>"><?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<div class="modal-bg" id="addM"><div class="modal"><h3>Add office admin</h3><p class="form-sub">Joins <?= e($office['office_name']) ?> only.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label>Full name</label><input type="text" name="full_name" required></div>
    <div class="row2"><div class="field"><label>Username</label><input type="text" name="username" required></div>
      <div class="field"><label>Email</label><input type="email" name="email" required></div></div>
    <div class="field"><label>Temporary password</label><input type="text" name="password" required minlength="8"><div class="hint">They will be forced to change it at first sign-in.</div></div>
    <div class="modal-acts"><button type="button" class="btn" onclick="closeModal('addM')">Cancel</button><button class="btn btn-primary">Add admin</button></div></form></div></div>
<div class="modal-bg" id="resetM"><div class="modal"><h3>Reset password</h3><p class="form-sub">For <b id="rname"></b>. This also clears any lockout.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" id="rid">
    <div class="field"><label>New temporary password</label><input type="text" name="password" required minlength="8"></div>
    <div class="modal-acts"><button type="button" class="btn" onclick="closeModal('resetM')">Cancel</button><button class="btn btn-primary">Reset</button></div></form></div></div>
<?php page_end();
