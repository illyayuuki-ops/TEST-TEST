<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code']; $office = get_office($oc);      // every query below is locked to this office

/** Fetch one category that belongs to this admin's office (never another office's). */
function my_category(int $id, string $oc): ?array {
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM categories WHERE id=? AND office_code=?'); $s->execute([$id, $oc]);
    return $s->fetch() ?: null;
}
function clean_name(string $n): string { return trim(preg_replace('/\s+/', ' ', $n)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $c = my_category((int)($_POST['id'] ?? 0), $oc);
    $name = clean_name($_POST['name'] ?? '');

    if ($a === 'add') {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) flash('Category name must be 2–60 characters.', 'err');
        else {
            $dup = $pdo->prepare('SELECT 1 FROM categories WHERE office_code=? AND name=?'); $dup->execute([$oc, $name]);
            if ($dup->fetch()) flash("\"$name\" already exists in your office.", 'err');
            else {
                $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM categories WHERE office_code=? '); $next->execute([$oc]);
                $pdo->prepare('INSERT INTO categories (office_code,name,sort_order) VALUES (?,?,?)')->execute([$oc, $name, (int)$next->fetchColumn()]);
                audit('CATEGORY_ADDED', "Added category \"$name\" to $oc"); flash("Category \"$name\" added.");
            }
        }
    } elseif (!$c) {
        flash('Category not found.', 'err');
    } elseif ($a === 'rename') {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) flash('Category name must be 2–60 characters.', 'err');
        else {
            $dup = $pdo->prepare('SELECT 1 FROM categories WHERE office_code=? AND name=? AND id<>?'); $dup->execute([$oc, $name, $c['id']]);
            if ($dup->fetch()) flash("\"$name\" already exists in your office.", 'err');
            else {
                $pdo->prepare('UPDATE categories SET name=? WHERE id=?')->execute([$name, $c['id']]);   // tickets point at the id, so old tickets show the new name
                audit('CATEGORY_RENAMED', "\"{$c['name']}\" → \"$name\" in $oc"); flash("Renamed to \"$name\".");
            }
        }
    } elseif ($a === 'toggle') {
        {
            $h = $c['is_hidden'] ? 0 : 1;
            $pdo->prepare('UPDATE categories SET is_hidden=? WHERE id=?')->execute([$h, $c['id']]);
            audit($h ? 'CATEGORY_HIDDEN' : 'CATEGORY_SHOWN', "\"{$c['name']}\" in $oc");
            flash("\"{$c['name']}\" is now " . ($h ? 'hidden from students.' : 'visible to students.'));
        }
    } elseif ($a === 'move') {
        $list = array_values(office_categories($oc, false));
        $ids = array_column($list, 'id'); $i = array_search((int)$c['id'], array_map('intval', $ids), true);
        $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($list[$j])) {
            $pdo->beginTransaction();
            $set = $pdo->prepare('UPDATE categories SET sort_order=? WHERE id=?');
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            foreach ($list as $k => $r) $set->execute([($k + 1) * 10, $r['id']]);   // rewrite 10,20,30… so order is always clean
            $pdo->commit();
        }
    } elseif ($a === 'delete') {
        $isFallback = $c['name'] === LOCKED_CATEGORY;
        $own = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE category_id=? AND office_code=?'); $own->execute([$c['id'], $oc]);
        $nTickets = (int)$own->fetchColumn();
        $other = $pdo->prepare('SELECT 1 FROM categories WHERE office_code=? AND name=? AND id<>? LIMIT 1'); $other->execute([$oc, LOCKED_CATEGORY, $c['id']]);
        if ($isFallback && $nTickets > 0 && !$other->fetch()) {
            flash("\"{$c['name']}\" still has $nTickets ticket(s) and is the only fallback category. Rename it instead, or delete it once it has no tickets.", 'err');
        } else {
            $pdo->beginTransaction();
            try {
                $moved = 0;
                if ($nTickets > 0) {
                    $fallback = fallback_category_id($oc, (int)$c['id']);   // "Other" (created again if this office has none)
                    $mv = $pdo->prepare('UPDATE tickets SET category_id=? WHERE category_id=? AND office_code=?'); $mv->execute([$fallback, $c['id'], $oc]);
                    $moved = $mv->rowCount();
                }
                $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$c['id']]);
                $pdo->commit();
                audit('CATEGORY_DELETED', "Deleted \"{$c['name']}\" from $oc; $moved ticket(s) moved to " . LOCKED_CATEGORY);
                flash("\"{$c['name']}\" deleted." . ($moved ? " $moved ticket(s) were moved to " . LOCKED_CATEGORY . '.' : ''));
            } catch (Throwable $ex) { $pdo->rollBack(); flash('Could not delete the category.', 'err'); }
        }
    }
    redirect('admin/categories.php');
}

$cats = office_categories($oc, false);
$cnt = $pdo->prepare('SELECT category_id, COUNT(*) n FROM tickets WHERE office_code=? GROUP BY category_id'); $cnt->execute([$oc]);
$used = array_column($cnt->fetchAll(), 'n', 'category_id');
$firstMovable = $cats ? $cats[0]['id'] : null; $lastMovable = $cats ? end($cats)['id'] : null;

page_start('Categories', e($office['office_name']) . ' · what students can pick when they file a ticket',
  '<button class="btn btn-primary" onclick="openModal(\'addM\')"><i class="fa-solid fa-plus"></i>Add category</button>');
?>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th>Visible to students</th><th>Tickets</th><th></th></tr></thead><tbody>
    <?php foreach ($cats as $r): $n = (int)($used[$r['id']] ?? 0); ?><tr>
      <td><b><?= e($r['name']) ?></b></td>
      <td><?= $r['is_hidden'] ? '<span class="badge st-closed">Hidden</span>' : '<span class="badge st-resolved">Visible</span>' ?></td>
      <td class="mono"><?= $n ?></td>
      <td style="text-align:right;white-space:nowrap">
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="dir" value="up">
            <button class="btn btn-sm" title="Move up" <?= $r['id'] == $firstMovable ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up"></i></button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="dir" value="down">
            <button class="btn btn-sm" title="Move down" <?= $r['id'] == $lastMovable ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-down"></i></button></form>
          <button class="btn btn-sm" onclick="document.getElementById('rnId').value=<?= $r['id'] ?>;document.getElementById('rnName').value=<?= e(json_encode($r['name'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>;openModal('renameM')"><i class="fa-solid fa-pen"></i>Rename</button>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn btn-sm"><?= $r['is_hidden'] ? 'Show' : 'Hide' ?></button></form>
          <button class="btn btn-sm btn-danger" onclick="document.getElementById('dlId').value=<?= $r['id'] ?>;document.getElementById('dlName').textContent=<?= e(json_encode($r['name'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>;document.getElementById('dlN').textContent='<?= $n ?>';openModal('delM')"><i class="fa-solid fa-trash"></i>Delete</button>
      </td></tr>
    <?php endforeach; ?></tbody></table></div>
  <div class="hint" style="padding:12px 4px 2px">Hidden categories disappear from the student form but stay on old tickets. Deleting a category moves its tickets to “<?= e(LOCKED_CATEGORY) ?>”.</div>
</div>

<div class="modal-bg" id="addM"><div class="modal"><h3>Add category</h3><p class="form-sub">Students filing to <?= e($office['office_name']) ?> will see it in the category list.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label>Category name</label><input type="text" name="name" maxlength="60" placeholder="e.g. Clearance Request" required></div>
    <div class="modal-acts"><button type="button" class="btn" onclick="closeModal('addM')">Cancel</button><button class="btn btn-primary">Add</button></div></form></div></div>

<div class="modal-bg" id="renameM"><div class="modal"><h3>Rename category</h3><p class="form-sub">Tickets already filed under it will show the new name.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" id="rnId">
    <div class="field"><label>New name</label><input type="text" name="name" id="rnName" maxlength="60" required></div>
    <div class="modal-acts"><button type="button" class="btn" onclick="closeModal('renameM')">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>

<div class="modal-bg" id="delM"><div class="modal"><h3>Delete category</h3>
  <p class="form-sub">Delete <b id="dlName"></b>? <span id="dlN"></span> ticket(s) filed under it will be moved to “<?= e(LOCKED_CATEGORY) ?>”. This can't be undone.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="dlId">
    <div class="modal-acts"><button type="button" class="btn" onclick="closeModal('delM')">Cancel</button><button class="btn btn-danger">Delete</button></div></form></div></div>
<?php page_end();
