<?php
// One page that handles list / search / add / edit / delete for every "entity" in bootstrap.php
require __DIR__ . '/includes/bootstrap.php';
$all = entities();
$e   = $_GET['e'] ?? 'books';
if (!isset($all[$e])) { http_response_code(404); exit('Unknown section'); }
if ($e === 'acquisitions' && $_SERVER['REQUEST_METHOD'] === 'GET') redirect('acquisitions.php' . (isset($_GET['a'], $_GET['id']) ? '?a=' . rawurlencode($_GET['a']) . '&id=' . (int)$_GET['id'] : (($_GET['a'] ?? '') === 'add' ? '?a=add' : '')));
$E = $all[$e]; $table = $E['table']; $pk = $E['pk']; $fields = $E['fields'];
$action = $_GET['a'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo = db();

// ---------- save / delete (POST) ----------
if (($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'add' || $action === 'edit') && !can_edit_records()) deny();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'delete') {
        $archived = false;
        if (in_array($e, ['books', 'nonbook'], true) && table_exists('catalog_archive')) {
            // Keep a copy of the record in the Archives (with the reason) before it is removed from the catalog.
            $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = ?"); $st->execute([(int)$_POST['id']]);
            if ($old = $st->fetch()) {
                $reasons = ['Lost', 'Missing', 'Withdrawn', 'Discarded', 'Damaged', 'Other'];
                $reason = in_array($_POST['reason'] ?? '', $reasons, true) ? $_POST['reason'] : 'Withdrawn';
                $pdo->prepare('INSERT INTO catalog_archive (Kind, Accession, Title, Author, Publisher, Call_Number, ISBN, Ye_ar, Type, Location, Status, Reason, Deleted_at, Deleted_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')
                    ->execute([$e === 'books' ? 'Books' : 'Non-book', $old['Accession'] ?? null, $old['Title'] ?? null, $old['Author'] ?? null, $old['Publisher'] ?? null, $old['Call_Number'] ?? null,
                               $old['ISBN'] ?? null, $old['Ye_ar'] ?? null, $old['Type'] ?? null, $old['Location'] ?? null, $old['Status'] ?? null, $reason, $_SESSION['name'] ?? ($_SESSION['user'] ?? '')]);
                $archived = true;
            }
        }
        $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?")->execute([(int)$_POST['id']]);
        flash($archived ? 'Record deleted and kept in the Archives.' : 'Record deleted.');
        redirect("crud.php?e=$e");
    }
    $vals = [];
    foreach ($fields as $col => [$label, $type]) {
        $v = trim($_POST[$col] ?? '');
        if ($type === 'select:levels' && $v !== '' && !in_array($v, libraries(), true)) $v = '';   // only the listed levels
        $vals[$col] = ($v === '') ? null : $v;           // empty box -> NULL (same as the old Access data)
    }
    if ($id > 0) {
        $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($vals)));
        $vals['__id'] = $id;
        $sql = "UPDATE `$table` SET $set WHERE `$pk` = :__id";
        $pdo->prepare($sql)->execute($vals);
        flash('Changes saved.');
    } else {
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($vals)));
        $ph   = implode(', ', array_map(fn($c) => ":$c", array_keys($vals)));
        $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($vals);
        flash('Record added.');
    }
    redirect("crud.php?e=$e");
}

$pageTitle = $E['title'];

// ---------- add / edit form ----------
if ($action === 'add' || $action === 'edit') {
    $row = array_fill_keys(array_keys($fields), '');
    if ($action === 'edit') {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = ?"); $st->execute([$id]);
        $row = $st->fetch() ?: null;
        if (!$row) { http_response_code(404); exit('Record not found'); }
    }
    include __DIR__ . '/includes/header.php'; ?>
    <h1><?= $action === 'add' ? 'Add' : 'Edit' ?> · <?= e($E['title']) ?></h1>
    <form method="post" class="card form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php foreach ($fields as $col => [$label, $type]):
            $val = $row[$col] ?? ''; if ($type === 'date' && $val) $val = substr($val, 0, 10); ?>
        <label><span><?= e($label) ?></span>
          <?php if ($type === 'textarea'): ?><textarea name="<?= e($col) ?>" rows="3"><?= e($val) ?></textarea>
          <?php elseif ($type === 'select:levels'): ?><select name="<?= e($col) ?>"><option value=""></option><?php foreach (libraries() as $lv): ?><option value="<?= e($lv) ?>" <?= $lv === $val ? 'selected' : '' ?>><?= e($lv) ?> (<?= e(library_names()[$lv]) ?>)</option><?php endforeach; ?></select>
          <?php else: ?><input type="<?= $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text') ?>"
                 <?= $type === 'number' ? 'step="any"' : '' ?> name="<?= e($col) ?>" value="<?= e($val) ?>"><?php endif; ?>
        </label>
      <?php endforeach; ?>
      <div class="actions"><button class="btn primary">Save</button>
        <a class="btn" href="crud.php?e=<?= e($e) ?>">Cancel</a></div>
    </form>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

// ---------- list with search / filter / paging ----------
$q = trim($_GET['q'] ?? ''); $f = $_GET['f'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1)); $per = $CONFIG['per_page'];
$where = []; $args = [];
if ($q !== '') {
    $like = [];
    foreach ($E['search'] as $i => $c) { $like[] = "`$c` LIKE :q$i"; $args["q$i"] = "%$q%"; }
    $where[] = '(' . implode(' OR ', $like) . ')';
}
if ($E['filter'] && $f !== '') { $where[] = "`{$E['filter']}` = :f"; $args['f'] = $f; }
$g = trim($_GET['g'] ?? '');
if (!empty($E['filter2']) && $g !== '') { $where[] = "`{$E['filter2']}` = :g"; $args['g'] = $g; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM `$table` $w"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$cols = implode(', ', array_map(fn($c) => "`$c`", array_merge([$pk], $E['list'])));
$st = $pdo->prepare("SELECT $cols FROM `$table` $w ORDER BY `$pk` DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args); $rows = $st->fetchAll();
$opts = $E['filter'] ? $pdo->query("SELECT DISTINCT `{$E['filter']}` AS v FROM `$table` WHERE `{$E['filter']}` IS NOT NULL AND `{$E['filter']}` <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) : [];
$qs = fn($p) => 'crud.php?' . http_build_query(['e' => $e, 'q' => $q, 'f' => $f, 'g' => $g, 'p' => $p]);

include __DIR__ . '/includes/header.php'; ?>
<div class="head-row"><h1><?= e($E['title']) ?></h1>
  <?php if (can_edit_records()): ?><a class="btn primary no-print" href="crud.php?e=<?= e($e) ?>&a=add">+ Add</a><?php endif; ?></div>
<form class="toolbar no-print" method="get">
  <input type="hidden" name="e" value="<?= e($e) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= e(strtolower(implode(', ', array_map(fn($c) => $fields[$c][0] ?? $c, array_slice($E['search'], 0, 3))))) ?>…">
  <?php if ($opts): ?><select name="f"><option value="">All <?= e(strtolower($E['filter'])) ?></option>
    <?php foreach ($opts as $o): ?><option <?= $o === $f ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select><?php endif; ?>
  <?php if (!empty($E['filter2'])): ?><select name="g" aria-label="Grade level"><option value="">All grade levels</option><?php foreach (libraries() as $lv): ?><option <?= $lv === $g ? 'selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">Search</button>
  <?php if ($q !== '' || $f !== '' || $g !== ''): ?><a class="btn ghost" href="crud.php?e=<?= e($e) ?>">Clear</a><?php endif; ?>
  <button type="button" class="btn ghost" data-print>Print</button>
</form>
<p class="muted"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> · page <?= $page ?> of <?= $pages ?></p>
<div class="table-wrap"><table class="data" data-sortable>
  <thead><tr><?php foreach ($E['list'] as $c): ?><th><?= e($fields[$c][0] ?? $c) ?></th><?php endforeach; ?><th class="no-print"></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?><tr>
    <?php foreach ($E['list'] as $c): $v = $r[$c]; if (($fields[$c][1] ?? '') === 'date' && $v) $v = substr($v, 0, 10); ?>
      <td><?= e($v) ?></td><?php endforeach; ?>
    <td class="no-print row-actions"><?php if (can_edit_records()): ?>
      <a href="crud.php?e=<?= e($e) ?>&a=edit&id=<?= (int)$r[$pk] ?>">Edit</a>
      <form method="post" data-confirm="Delete this record?<?= in_array($e, ['books', 'nonbook'], true) ? ' It will be kept in the Archives.' : '' ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete">
        <?php if (in_array($e, ['books', 'nonbook'], true)): ?><select name="reason" aria-label="Reason for removing" style="width:auto"><?php foreach (['Withdrawn', 'Lost', 'Missing', 'Discarded', 'Damaged', 'Other'] as $rs): ?><option><?= e($rs) ?></option><?php endforeach; ?></select><?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$r[$pk] ?>"><button class="link danger">Delete</button></form><?php endif; ?>
    </td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="<?= count($E['list']) + 1 ?>" class="muted">No records found.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php if ($pages > 1): ?><nav class="pager no-print">
  <?php if ($page > 1): ?><a href="<?= e($qs($page - 1)) ?>">‹ Prev</a><?php endif; ?>
  <span>Page <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= e($qs($page + 1)) ?>">Next ›</a><?php endif; ?>
</nav><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
