<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('student');

$me = $pdo->prepare('SELECT * FROM users WHERE id=?'); $me->execute([$u['id']]); $me = $me->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'username') {
        $nu = trim($_POST['username'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $nu)) flash('Username: 3–30 letters, numbers, dot or underscore.', 'err');
        elseif ($nu !== $me['username']) {
            $d = $pdo->prepare('SELECT 1 FROM users WHERE username=? AND id<>?'); $d->execute([$nu, $u['id']]);
            if ($d->fetch()) flash('That username is already taken.', 'err');
            else {
                $pdo->prepare('UPDATE users SET username=? WHERE id=?')->execute([$nu, $u['id']]);
                $_SESSION['user']['username'] = $nu;
                audit('PROFILE_USERNAME', $me['username'] . ' → ' . $nu);
                flash('Username updated. Use it the next time you sign in.');
            }
        }
    } elseif ($a === 'avatar') {
        [$file, $err] = avatar_store($_FILES['photo'] ?? null, (int)$u['id']);
        if ($err) flash($err, 'err');
        else {
            try {
                $pdo->prepare('UPDATE users SET avatar_path=? WHERE id=?')->execute([$file, $u['id']]);
                avatar_delete($me['avatar_path'] ?? null);
                audit('PROFILE_PHOTO', 'Profile photo changed');
                flash('Profile photo updated.');
            } catch (Throwable $e) {
                avatar_delete($file);
                error_log('Profile photo not saved (run database/upgrade_profile_pic.sql?): ' . $e->getMessage());
                flash('Could not save the photo. Tell the system admin.', 'err');
            }
        }
    } elseif ($a === 'avatar_remove' && !empty($me['avatar_path'])) {
        $pdo->prepare('UPDATE users SET avatar_path=NULL WHERE id=?')->execute([$u['id']]);
        avatar_delete($me['avatar_path']);
        audit('PROFILE_PHOTO', 'Profile photo removed');
        flash('Profile photo removed.');
    }
    redirect('student/profile.php');
}

$c = $pdo->prepare('SELECT status, COUNT(*) n FROM tickets WHERE user_id=? GROUP BY status'); $c->execute([$u['id']]);
$counts = array_fill_keys(STATUSES, 0); foreach ($c->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
$total = array_sum($counts);
$active = 0; foreach (OPEN_STATUSES as $s) $active += $counts[$s];
$done = $counts['Resolved'] + $counts['Closed'];
$photo = $me['avatar_path'] ?? null;

page_start('My profile', 'Your photo and account details', '<a class="btn" href="' . url('auth/change_password.php') . '"><i class="fa-solid fa-key"></i>Change password</a>');
$verified = ($me['verification'] ?? 'verified') === 'verified';
?>
<div class="pf">
  <div class="card pf-hero">
    <div class="pf-cover"></div>
    <div class="pf-body">
      <?= avatar_html($me['full_name'], $photo, 96) ?>
      <div class="pf-id">
        <div class="pf-name"><?= e($me['full_name']) ?></div>
        <div class="pf-meta">
          <span class="badge role-<?= e($me['role']) ?>">Student</span>
          <span><i class="fa-solid fa-id-card"></i><?= e($me['school_id'] ?: '—') ?></span>
          <span><i class="fa-solid fa-envelope"></i><?= e($me['email']) ?></span>
        </div>
      </div>
      <div class="pf-photo">
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="avatar">
          <label class="btn btn-primary btn-sm" style="cursor:pointer"><i class="fa-solid fa-camera"></i><?= $photo ? 'Change photo' : 'Upload photo' ?>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required onchange="this.form.submit()"></label>
        </form>
        <?php if ($photo): ?><form method="post" onsubmit="return confirm('Remove your profile photo?')"><?= csrf_field() ?><input type="hidden" name="action" value="avatar_remove">
          <button class="btn btn-sm" type="submit">Remove</button></form><?php endif; ?>
        <div class="pf-hint">JPG, PNG or WEBP, up to 3 MB. Cropped to a square.</div>
      </div>
    </div>
  </div>

  <div class="stats pf-stats">
    <div class="stat" style="--c:var(--cyan)"><div class="k">Tickets filed</div><div class="v"><?= $total ?></div></div>
    <div class="stat" style="--c:var(--amber)"><div class="k">Active</div><div class="v"><?= $active ?></div></div>
    <div class="stat" style="--c:var(--green)"><div class="k">Resolved / closed</div><div class="v"><?= $done ?></div></div>
  </div>

  <div class="pf-grid">
    <div class="card">
      <h3>Account details</h3>
      <div class="pf-row"><span class="k">Student ID</span><span class="mono"><?= e($me['school_id'] ?: '—') ?></span></div>
      <div class="pf-row"><span class="k">Email</span><span class="mono"><?= e($me['email']) ?></span></div>
      <div class="pf-row"><span class="k">Account</span><?= $verified ? '<span class="badge st-resolved">Verified</span>' : '<span class="badge st-inprogress">' . e(str_replace('_', ' ', $me['verification'])) . '</span>' ?></div>
      <div class="pf-row"><span class="k">Member since</span><span class="mono"><?= date('M j, Y', strtotime($me['created_at'])) ?></span></div>
    </div>
    <div class="card">
      <h3>Username</h3>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="username">
        <div class="field"><label for="un">Sign-in username</label>
          <input type="text" id="un" name="username" value="<?= e($me['username']) ?>" maxlength="30" required autocomplete="off">
          <div class="hint">3–30 letters, numbers, dot or underscore. You sign in with this or your email.</div></div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i>Save username</button>
      </form>
    </div>
  </div>

  <div class="pf-actions" style="margin-top:20px">
    <a class="btn" href="<?= url('student/dashboard.php') ?>"><i class="fa-solid fa-ticket"></i>View my tickets</a>
  </div>
</div>
<?php page_end();
