<?php
// Utilities (administrator only): user accounts, the users list, and library settings (excluded days, headings, holidays, fines).
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pageTitle = 'Utilities';
$pdo = db();
$tab = $_GET['tab'] ?? 'admin'; if (!in_array($tab, ['admin', 'users', 'settings'], true)) $tab = 'admin';
$roles = ['admin' => 'Administrator', 'librarian' => 'Librarian', 'staff' => 'Staff'];
$libraries = libraries(); array_unshift($libraries, 'All libraries');
$has = fn($col) => (bool)$pdo->query("SHOW COLUMNS FROM Users LIKE " . $pdo->quote($col))->fetch();
$hasLib = $has('Library'); $hasLast = $has('Last_login');   // Last_login comes from 10_files_utilities.sql
$self = strtolower($_SESSION['user'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'save_user') {
        $orig = strtolower(trim($_POST['orig'] ?? ''));
        $u = strtolower(trim($_POST['username'] ?? '')); $name = trim($_POST['full_name'] ?? ''); $role = $_POST['role'] ?? ''; $lib = trim($_POST['library'] ?? 'All libraries'); $pw = (string)($_POST['password'] ?? '');
        $err = '';
        if (!preg_match('/^[a-z0-9._\/-]{3,40}$/', $u)) $err = 'User name must be 3-40 letters, numbers or . _ - /';
        elseif (!isset($roles[$role])) $err = 'Choose an access right.';
        elseif ($orig === '' && strlen($pw) < 6) $err = 'Password must be at least 6 characters.';
        elseif ($pw !== '' && strlen($pw) < 6) $err = 'Password must be at least 6 characters.';
        elseif ($orig === $self && $role !== 'admin') $err = 'You cannot lower your own access right.';
        if ($err === '') {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM Users WHERE Username = ? AND Username <> ?'); $dup->execute([$u, $orig]);
            if ($dup->fetchColumn()) $err = 'That user name is already taken.';
        }
        if ($err !== '') { flash('Not saved: ' . $err); redirect('utilities.php?tab=admin' . ($orig ? '&edit=' . urlencode($orig) : '')); }
        $name = $name !== '' ? $name : ucfirst($u);
        if ($orig === '') {
            $cols = 'Username, Full_name, Role, Password_hash' . ($hasLib ? ', Library' : ''); $ph = '?,?,?,?' . ($hasLib ? ',?' : '');
            $args = [$u, $name, $role, password_hash($pw, PASSWORD_DEFAULT)]; if ($hasLib) $args[] = $lib;
            $pdo->prepare("INSERT INTO Users ($cols) VALUES ($ph)")->execute($args); flash('User ' . $u . ' created.');
        } else {
            $set = 'Username = ?, Full_name = ?, Role = ?' . ($hasLib ? ', Library = ?' : '') . ($pw !== '' ? ', Password_hash = ?' : '');
            $args = [$u, $name, $role]; if ($hasLib) $args[] = $lib; if ($pw !== '') $args[] = password_hash($pw, PASSWORD_DEFAULT); $args[] = $orig;
            $pdo->prepare("UPDATE Users SET $set WHERE Username = ?")->execute($args);
            if ($orig === $self) { $_SESSION['user'] = $u; $_SESSION['name'] = $name; $_SESSION['library'] = $lib; }
            flash('User ' . $u . ' updated.');
        }
        redirect('utilities.php?tab=users');
    }
    if ($do === 'delete_user') {
        $u = strtolower(trim($_POST['username'] ?? ''));
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM Users WHERE Role = 'admin'")->fetchColumn();
        $isAdmin = $pdo->prepare("SELECT Role FROM Users WHERE Username = ?"); $isAdmin->execute([$u]); $role = $isAdmin->fetchColumn();
        if ($u === $self) flash('Not deleted: you cannot delete the account you are using.');
        elseif ($role === 'admin' && $admins <= 1) flash('Not deleted: there must be at least one administrator.');
        else { $pdo->prepare('DELETE FROM Users WHERE Username = ?')->execute([$u]); flash('User ' . $u . ' deleted.'); }
        redirect('utilities.php?tab=users');
    }
    if ($do === 'save_settings') {
        $flag = fn($k) => !empty($_POST[$k]) ? 1 : 0;
        $row = $pdo->query('SELECT ID FROM OverdueCharge LIMIT 1')->fetchColumn();
        $heading = trim($_POST['heading'] ?? '');
        if ($row === false) { $pdo->exec('INSERT INTO OverdueCharge (Monday, Friday, Saturday, Sunday) VALUES (0,0,0,1)'); $row = $pdo->query('SELECT ID FROM OverdueCharge LIMIT 1')->fetchColumn(); }
        $pdo->prepare('UPDATE OverdueCharge SET Monday=?, Friday=?, Saturday=?, Sunday=?, LocalHeading=? WHERE ID=?')->execute([$flag('mon'), $flag('fri'), $flag('sat'), $flag('sun'), $heading !== '' ? $heading : null, $row]);
        flash('Settings saved.'); redirect('utilities.php?tab=settings');
    }
    if ($do === 'save_closing') {
        $t = trim($_POST['closing'] ?? '');
        if (!table_exists('app_setting')) flash('Import 14_attendance_timeinout.sql first.');
        elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) flash('Not saved: enter a time like 17:00.');
        else { app_setting_set('attendance_closing_time', $t); flash('Closing time saved. Anyone still in the library at ' . date('g:i A', strtotime($t)) . ' is timed out automatically.'); }
        redirect('utilities.php?tab=settings');
    }
    if ($do === 'save_fines') {
        $row = $pdo->query('SELECT ID FROM OverdueCharge LIMIT 1')->fetchColumn();
        if ($row !== false) {
            $set = []; $args = [];
            foreach (['Student', 'Faculty', 'Grade_school', 'High_school', 'Supervisor', 'Admin', 'Staff', 'Personnel', 'OvedueCharge', 'Overnight'] as $c) {
                if (isset($_POST[$c]) && $_POST[$c] !== '' && is_numeric($_POST[$c]) && $_POST[$c] >= 0) { $set[] = "`$c` = ?"; $args[] = $_POST[$c]; }
            }
            if ($set) { $args[] = $row; $pdo->prepare('UPDATE OverdueCharge SET ' . implode(', ', $set) . ' WHERE ID = ?')->execute($args); flash('Fines saved.'); }
        }
        redirect('utilities.php?tab=settings');
    }
    if ($do === 'add_holiday') {
        $d = valid_date($_POST['holiday'] ?? '');
        if ($d === '') flash('Not added: choose a date.');
        else {
            $ex = $pdo->prepare('SELECT COUNT(*) FROM Holiday WHERE DATE(Holiday) = ?'); $ex->execute([$d]);
            if ($ex->fetchColumn()) flash('That date is already listed.'); else { $pdo->prepare('INSERT INTO Holiday (Holiday) VALUES (?)')->execute([$d]); flash('Holiday added.'); }
        }
        redirect('utilities.php?tab=settings');
    }
    if ($do === 'edit_holiday') {
        $d = valid_date($_POST['holiday'] ?? ''); $hid = (int)($_POST['id'] ?? 0);
        if ($d === '') flash('Not changed: choose a date.');
        else {
            $ex = $pdo->prepare('SELECT COUNT(*) FROM Holiday WHERE DATE(Holiday) = ? AND ID <> ?'); $ex->execute([$d, $hid]);
            if ($ex->fetchColumn()) flash('That date is already listed.');
            else { $pdo->prepare('UPDATE Holiday SET Holiday = ? WHERE ID = ?')->execute([$d, $hid]); flash('Holiday changed.'); }
        }
        redirect('utilities.php?tab=settings');
    }
    if ($do === 'del_holiday') { $pdo->prepare('DELETE FROM Holiday WHERE ID = ?')->execute([(int)$_POST['id']]); flash('Holiday removed.'); redirect('utilities.php?tab=settings'); }
    if ($do === 'empty_holidays') { $pdo->exec('DELETE FROM Holiday'); flash('Holiday list emptied.'); redirect('utilities.php?tab=settings'); }
}

$users = $pdo->query('SELECT * FROM Users ORDER BY Username')->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) foreach ($users as $u) if ($u['Username'] === strtolower($_GET['edit'])) $edit = $u;
$oc = $pdo->query('SELECT * FROM OverdueCharge LIMIT 1')->fetch() ?: [];
$holidays = $pdo->query('SELECT ID, Holiday FROM Holiday ORDER BY Holiday DESC LIMIT 400')->fetchAll();
include __DIR__ . '/includes/header.php'; ?>
<h1>Utilities</h1>
<div class="tabs no-print"><?php foreach (['admin' => 'Administration', 'users' => 'Users list', 'settings' => 'Settings'] as $k => $l): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="utilities.php?tab=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></div>

<?php if ($tab === 'admin'): ?>
<form method="post" class="card form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="save_user"><input type="hidden" name="orig" value="<?= e($edit['Username'] ?? '') ?>">
  <h2><?= $edit ? 'Edit user ' . e($edit['Username']) : 'New user' ?></h2>
  <label><span>User name</span><input name="username" value="<?= e($edit['Username'] ?? '') ?>" required autocomplete="off"></label>
  <label><span>Full name</span><input name="full_name" value="<?= e($edit['Full_name'] ?? '') ?>" autocomplete="off"></label>
  <label><span>Password<?= $edit ? ' (leave empty to keep it)' : '' ?></span><input type="password" name="password" <?= $edit ? '' : 'required' ?> autocomplete="new-password" minlength="6"></label>
  <label><span>Access right</span><select name="role" required><option value=""></option><?php foreach ($roles as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['Role'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php if ($hasLib): ?><label><span>Library</span><select name="library"><?= options($libraries, $edit['Library'] ?? 'All libraries', false) ?></select></label><?php endif; ?>
  <p class="muted" style="margin:0"><b>Administrator</b>: everything, including this page. <b>Librarian</b>: catalog, borrowers, circulation, inventory, serials, bookings and reports. <b>Staff</b>: circulation and daily desk work; the catalog is view-only. A user tied to one level only sees that level's records. Levels: <?= e(implode(', ', array_map(fn($k, $v) => "$k = $v", array_keys(library_names()), library_names()))) ?>.</p>
  <div class="actions"><button class="btn primary"><?= $edit ? 'Save changes' : 'New user' ?></button><?php if ($edit): ?><a class="btn" href="utilities.php?tab=admin">Cancel</a><?php endif; ?><a class="btn ghost" href="utilities.php?tab=users">Close</a></div>
</form>

<?php elseif ($tab === 'users'): ?>
<div class="table-wrap"><table class="data"><thead><tr><th>User name</th><th>Full name</th><th>Current user</th><th>Access right</th><th>Library</th><th>Last login</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): ?><tr><td><?= e($u['Username']) ?></td><td><?= e($u['Full_name']) ?></td><td><?= $u['Username'] === $self ? '<span class="pill inv-on-shelf">you</span>' : '' ?></td>
    <td><?= e($roles[$u['Role']] ?? $u['Role']) ?></td><td><?= e($u['Library'] ?? 'All libraries') ?></td><td><?= $hasLast && !empty($u['Last_login']) ? e(fdatetime($u['Last_login'])) : '<span class="muted">never</span>' ?></td>
    <td class="row-actions"><a href="utilities.php?tab=admin&edit=<?= e(urlencode($u['Username'])) ?>">Edit</a>
      <?php if ($u['Username'] !== $self): ?><form method="post" data-confirm="Delete user <?= e($u['Username']) ?>?"><?= csrf_field() ?><input type="hidden" name="do" value="delete_user"><input type="hidden" name="username" value="<?= e($u['Username']) ?>"><button class="link danger">Delete</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<p class="muted">New accounts get the password you set. People cannot change their own password yet, so use Edit here to set a new one.</p>

<?php else: ?>
<div class="grid2">
  <div>
    <form method="post" class="card form" style="max-width:none"><?= csrf_field() ?><input type="hidden" name="do" value="save_settings">
      <h2>Check any day to exclude from computation</h2>
      <p class="muted" style="margin:0">Excluded days do not count toward due dates or overdue fines, and a due date never falls on one. Holidays below are excluded too.</p>
      <div class="opt-row"><?php foreach (['mon' => ['Monday', 'Monday'], 'fri' => ['Friday', 'Friday'], 'sat' => ['Saturday', 'Saturday'], 'sun' => ['Sunday', 'Sunday']] as $n => [$l, $col]): ?>
        <label><input type="checkbox" name="<?= $n ?>" value="1" <?= !empty($oc[$col]) ? 'checked' : '' ?>> <?= $l ?></label><?php endforeach; ?></div>
      <label><span>Report heading</span><input name="heading" value="<?= e($oc['LocalHeading'] ?? '') ?>" placeholder="St. Augustine Learning Resource Center"></label>
      <div class="actions"><button class="btn primary">Save</button></div></form>

    <form method="post" class="card form" style="max-width:none"><?= csrf_field() ?><input type="hidden" name="do" value="save_fines">
      <h2>Fines</h2><p class="muted" style="margin:0">Fine per day overdue, by kind of borrower. Locker, internet and room penalties are set in Transaction monitoring &gt; Items &amp; rates.</p>
      <div class="form" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));max-width:none">
        <?php foreach (['Student' => 'Student', 'Faculty' => 'Faculty', 'Grade_school' => 'Lower school (LS)', 'High_school' => 'High school (HS)', 'Supervisor' => 'Supervisor', 'Admin' => 'Admin', 'Staff' => 'Staff', 'Personnel' => 'Personnel', 'OvedueCharge' => 'Other / unknown', 'Overnight' => 'Overnight items'] as $c => $l): ?>
          <label><span><?= e($l) ?></span><input type="number" step="any" min="0" name="<?= e($c) ?>" value="<?= e($oc[$c] ?? '') ?>"></label><?php endforeach; ?></div>
      <div class="actions"><button class="btn primary">Save fines</button></div></form>
    <form method="post" class="card form" style="max-width:none"><?= csrf_field() ?><input type="hidden" name="do" value="save_closing">
      <h2>Attendance closing time</h2><p class="muted" style="margin:0">Anyone who scanned in and never scanned out is timed out automatically at this time.</p>
      <label><span>Closing time</span><input type="time" name="closing" value="<?= e(att_closing_time()) ?>" required></label>
      <div class="actions"><button class="btn primary">Save</button></div></form>
    <div class="card"><h2>Picture path</h2><p class="muted">Borrower photos are saved by the website itself (circulation screen &gt; Photo), so there is no folder to choose.</p></div>
    <div class="card"><h2>Group / policy</h2><p class="muted">Loan periods, items per borrower and the other circulation rules are shown on the Setup page and are set in config.php.</p><a class="btn" href="setup.php">Policy / services</a></div>
  </div>

  <div class="card"><h2>Enter date of holiday / suspension</h2>
    <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="do" value="add_holiday"><input type="date" name="holiday" required><button class="btn primary">Add</button></form>
    <div class="table-wrap" style="max-height:420px;overflow:auto"><table class="data"><thead><tr><th>Holiday / suspension</th><th></th></tr></thead><tbody>
      <?php foreach ($holidays as $h): ?><tr><td><?= e(date('D, M j, Y', strtotime($h['Holiday']))) ?></td><td class="row-actions"><form method="post" style="display:inline-flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="do" value="edit_holiday"><input type="hidden" name="id" value="<?= (int)$h['ID'] ?>"><input type="date" name="holiday" value="<?= e(substr($h['Holiday'], 0, 10)) ?>" aria-label="New date" required><button class="link">Edit</button></form> <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_holiday"><input type="hidden" name="id" value="<?= (int)$h['ID'] ?>"><button class="link danger">Delete</button></form></td></tr>
      <?php endforeach; if (!$holidays) echo '<tr><td colspan="2" class="muted">No holidays yet.</td></tr>'; ?></tbody></table></div>
    <?php if ($holidays): ?><form method="post" data-confirm="Delete every holiday in the list?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="do" value="empty_holidays"><button class="btn ghost">Empty the list</button></form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
