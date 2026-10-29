<?php
// Files: academic research, analytic, archival accessioning, audio recording and the other kinds of file from the old "Files" menu.
// Everyone can look; the admin and librarians add, edit and delete. "Academic research" records are the ones counted in Inventory > Researches.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pageTitle = 'Files';
$pdo = db();
const FILE_STATUSES = ['Available', 'Lost', 'Missing', 'Withdrawn'];
$types = array_values(array_unique(array_merge(['Academic research', 'Analytic', 'Archival accessioning', 'Audio recording', 'Audio-visual', 'Book', 'Budget', 'Case and jurisprudence'], lookup_list('Documents'))));
$ready = table_exists('file_record');
$ed = can_edit_records();
$me = $_SESSION['user'] ?? '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$ed) deny();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $f = [];
        foreach (['Type', 'Accession', 'Title', 'Author', 'Ye_ar', 'Department', 'Adviser', 'Call_Number', 'Library', 'Location', 'Status', 'Notes'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
        $err = '';
        if (!in_array($f['Type'], $types, true)) $err = 'Choose the kind of file.';
        elseif ($f['Accession'] === '' || strlen($f['Accession']) > 60) $err = 'Enter an accession number (up to 60 characters).';
        elseif ($f['Title'] === '') $err = 'Enter the title.';
        elseif (!in_array($f['Status'], FILE_STATUSES, true)) $err = 'Choose a status.';
        elseif (!in_array($f['Library'], libraries(), true)) $err = 'Choose a library.';
        elseif (!lib_all() && $f['Library'] !== user_library()) $err = 'You can only record files for ' . user_library() . '.';
        elseif ($f['Ye_ar'] !== '' && !preg_match('/^\d{4}$/', $f['Ye_ar'])) $err = 'Year must be 4 digits, for example 2024.';
        if ($err === '') {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM file_record WHERE Accession = ? AND ID <> ?'); $dup->execute([$f['Accession'], $id]);
            if ($dup->fetchColumn()) $err = 'That accession number is already used by another file.';
        }
        if ($err !== '') { flash('Not saved: ' . $err); $_SESSION['file_draft'] = $f; redirect('files.php?' . ($id ? 'a=edit&id=' . $id : 'a=add&type=' . urlencode($f['Type']))); }
        unset($_SESSION['file_draft']);
        foreach ($f as $k => $v) if ($v === '' && !in_array($k, ['Type', 'Accession', 'Library', 'Status'], true)) $f[$k] = null;
        if ($id) {
            $pdo->prepare('UPDATE file_record SET Type=?, Accession=?, Title=?, Author=?, Ye_ar=?, Department=?, Adviser=?, Call_Number=?, Library=?, Location=?, Status=?, Notes=? WHERE ID=?')
                ->execute([...array_values($f), $id]);
            flash('Saved changes to ' . $f['Accession'] . '.');
        } else {
            $pdo->prepare('INSERT INTO file_record (Type, Accession, Title, Author, Ye_ar, Department, Adviser, Call_Number, Library, Location, Status, Notes, Created_at, Encoded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                ->execute([...array_values($f), $me]);
            flash('Added ' . $f['Accession'] . '.');
        }
        redirect('files.php?type=' . urlencode($f['Type']));
    }
    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM file_record WHERE ID = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('File record deleted.'); redirect('files.php?' . http_build_query(['type' => $_POST['type'] ?? '']));
    }
}

$a = $_GET['a'] ?? ''; $type = trim($_GET['type'] ?? ''); $q = trim($_GET['q'] ?? ''); $page = max(1, (int)($_GET['p'] ?? 1));
include __DIR__ . '/includes/header.php';
if (!$ready): ?>
  <h1>Files</h1><div class="card"><p>The Files table is missing. Import <code>10_files_utilities.sql</code> in phpMyAdmin, then reload this page.</p></div>
<?php include __DIR__ . '/includes/footer.php'; exit; endif;

if ($ed && ($a === 'add' || $a === 'edit')):
    $rec = ['Type' => $type, 'Accession' => '', 'Title' => '', 'Author' => '', 'Ye_ar' => '', 'Department' => '', 'Adviser' => '', 'Call_Number' => '', 'Library' => lib_all() ? 'College' : user_library(), 'Location' => '', 'Status' => 'Available', 'Notes' => ''];
    $id = 0;
    if ($a === 'edit') { $st = $pdo->prepare('SELECT * FROM file_record WHERE ID = ?'); $st->execute([(int)($_GET['id'] ?? 0)]); if ($r = $st->fetch()) { $rec = $r; $id = (int)$r['ID']; } }
    if (!empty($_SESSION['file_draft'])) { $rec = array_merge($rec, $_SESSION['file_draft']); unset($_SESSION['file_draft']); }
    $depts = $pdo->query("SELECT Department FROM Department WHERE Department IS NOT NULL AND Department <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN); ?>
  <h1><?= $id ? 'Edit file record' : 'New file record' ?></h1>
  <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $id ?>">
    <label><span>Kind of file</span><select name="Type" required><?= options($types, $rec['Type']) ?></select></label>
    <label><span>Accession no.</span><input name="Accession" value="<?= e($rec['Accession']) ?>" maxlength="60" required></label>
    <label><span>Title</span><textarea name="Title" rows="2" required><?= e($rec['Title']) ?></textarea></label>
    <label><span>Author / researcher(s)</span><input name="Author" value="<?= e($rec['Author']) ?>" maxlength="150"></label>
    <label><span>Year</span><input name="Ye_ar" value="<?= e($rec['Ye_ar']) ?>" maxlength="4" inputmode="numeric" placeholder="2024"></label>
    <label><span>Department / program</span><input name="Department" value="<?= e($rec['Department']) ?>" list="dl-dept" maxlength="80"><datalist id="dl-dept"><?php foreach ($depts as $d) echo '<option value="' . e($d) . '">'; ?></datalist></label>
    <label><span>Adviser</span><input name="Adviser" value="<?= e($rec['Adviser']) ?>" maxlength="100"></label>
    <label><span>Call number</span><input name="Call_Number" value="<?= e($rec['Call_Number']) ?>" maxlength="100"></label>
    <label><span>Library</span><select name="Library"><?= options(lib_all() ? libraries() : [user_library()], $rec['Library'], false) ?></select></label>
    <label><span>Shelf / location</span><input name="Location" value="<?= e($rec['Location']) ?>" maxlength="80"></label>
    <label><span>Status</span><select name="Status"><?= options(FILE_STATUSES, $rec['Status'], false) ?></select></label>
    <label><span>Notes</span><textarea name="Notes" rows="2" maxlength="500"><?= e($rec['Notes']) ?></textarea></label>
    <div class="actions"><button class="btn primary">Save</button><a class="btn" href="files.php?type=<?= e(urlencode($rec['Type'])) ?>">Cancel</a></div>
  </form>
<?php else:
    $where = ['1=1']; $args = [];
    if ($type !== '') { $where[] = 'Type = :t'; $args['t'] = $type; }
    if ($q !== '') { $where[] = '(Accession LIKE :q OR Title LIKE :q OR Author LIKE :q OR Adviser LIKE :q OR Call_Number LIKE :q OR Department LIKE :q)'; $args['q'] = "%$q%"; }
    if (!lib_all()) { $where[] = 'Library = :lib'; $args['lib'] = user_library(); }
    $w = implode(' AND ', $where);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM file_record WHERE $w"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $per = (int)($CONFIG['per_page'] ?? 25); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $st = $pdo->prepare("SELECT * FROM file_record WHERE $w ORDER BY Created_at DESC, ID DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args); $rows = $st->fetchAll();
    $counts = $pdo->query('SELECT Type, COUNT(*) n FROM file_record GROUP BY Type')->fetchAll(PDO::FETCH_KEY_PAIR);
    $qs = fn(array $x = []) => 'files.php?' . http_build_query(array_filter(['type' => $type, 'q' => $q] + $x, fn($v) => $v !== '' && $v !== null)); ?>
  <div class="head-row"><h1>Files</h1><span class="no-print"><?php if ($ed): ?><a class="btn primary" href="files.php?a=add&type=<?= e(urlencode($type)) ?>">New file record</a> <?php endif; ?><button class="btn ghost" data-print>Print</button></span></div>
  <form class="toolbar no-print" method="get">
    <select name="type" aria-label="Kind of file" style="min-width:260px" onchange="this.form.submit()"><option value="">All kinds of file (<?= array_sum($counts) ?>)</option>
      <?php foreach ($types as $t): ?><option value="<?= e($t) ?>" <?= $t === $type ? 'selected' : '' ?>><?= e($t) ?><?= !empty($counts[$t]) ? ' (' . (int)$counts[$t] . ')' : '' ?></option><?php endforeach; ?></select>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, author, adviser, accession…"><button class="btn primary">Search</button>
    <?php if ($q !== '' || $type !== ''): ?><a class="btn ghost" href="files.php">Clear</a><?php endif; ?></form>
  <p class="muted"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?><?= $type !== '' ? ' · ' . e($type) : '' ?>. Records of kind “Academic research” are the ones counted in Inventory &gt; Researches.</p>
  <div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Kind</th><th>Title</th><th>Author</th><th>Year</th><th>Department</th><th>Library</th><th>Status</th><?php if ($ed): ?><th class="no-print"></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= e($r['Accession']) ?></td><td><?= e($r['Type']) ?></td><td><?= e(shorten($r['Title'], 90)) ?><?= $r['Adviser'] ? '<br><small class="muted">Adviser: ' . e($r['Adviser']) . '</small>' : '' ?></td>
    <td><?= e(shorten($r['Author'], 40)) ?></td><td><?= e($r['Ye_ar']) ?></td><td><?= e($r['Department']) ?></td><td><?= e($r['Library']) ?></td>
    <td><span class="pill <?= $r['Status'] === 'Available' ? 'inv-on-shelf' : ($r['Status'] === 'Lost' ? 'inv-lost' : 'inv-missing') ?>"><?= e($r['Status']) ?></span></td>
    <?php if ($ed): ?><td class="no-print row-actions"><a href="files.php?a=edit&id=<?= (int)$r['ID'] ?>">Edit</a>
      <form method="post" data-confirm="Delete this file record?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><input type="hidden" name="type" value="<?= e($type) ?>"><button class="link danger">Delete</button></form></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="9" class="muted">No file records yet.' . ($ed ? ' Use “New file record” to add one.' : '') . '</td></tr>'; ?></tbody></table></div>
  <?php if ($pages > 1): ?><div class="pager no-print"><?php if ($page > 1): ?><a href="<?= e($qs(['p' => $page - 1])) ?>">‹ Previous</a><?php endif; ?> <span>Page <?= $page ?> of <?= $pages ?></span> <?php if ($page < $pages): ?><a href="<?= e($qs(['p' => $page + 1])) ?>">Next ›</a><?php endif; ?></div><?php endif; ?>
<?php endif;
include __DIR__ . '/includes/footer.php';
