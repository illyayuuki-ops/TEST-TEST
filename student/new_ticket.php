<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('student');

// The student picks the office here, while reporting. A QR code (?office=) or the last office used only pre-selects it.
$offices = active_offices();
$byCode = []; foreach ($offices as $o) $byCode[$o['office_code']] = $o;
$catsBy = [];   // office_code => [[id, name], ...] (visible categories only), for the dependent Category dropdown
foreach ($offices as $o) $catsBy[$o['office_code']] = array_map(fn($c) => [(int)$c['id'], $c['name']], office_categories($o['office_code']));

$pre = strtolower(trim((string)($_GET['office'] ?? ($_SESSION['office_code'] ?? ''))));
$f = ['office' => isset($byCode[$pre]) ? $pre : '', 'category' => 0, 'priority' => 'Normal', 'subject' => '', 'details' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['office']   = trim($_POST['office_code'] ?? '');
    $f['priority'] = in_array($_POST['priority'] ?? '', PRIORITIES, true) ? $_POST['priority'] : 'Normal';
    $f['subject']  = trim($_POST['subject'] ?? '');
    $f['details']  = trim($_POST['details'] ?? '');
    $office = $byCode[$f['office']] ?? null;
    [$attErr, $attOk] = attach_collect();
    $validIds = $office ? array_column($catsBy[$office['office_code']], 0) : [];
    $f['category'] = in_array((int)($_POST['category'] ?? 0), $validIds, true) ? (int)$_POST['category'] : 0;
    if (!$office) flash('Please choose the office you want to report to.', 'err');
    elseif (!$f['category']) flash('Please choose a category.', 'err');
    elseif (mb_strlen($f['subject']) < 5 || mb_strlen($f['subject']) > 150) flash('Subject must be 5–150 characters.', 'err');
    elseif (mb_strlen($f['details']) < 10) flash('Please describe the concern in a bit more detail.', 'err');
    elseif ($attErr) flash($attErr, 'err');
    else {
        $_SESSION['office_code'] = $office['office_code'];
        // Double-click / refresh guard: the same person sending the same ticket within a minute opens the one already made.
        $dup = $pdo->prepare('SELECT id FROM tickets WHERE user_id=? AND office_code=? AND subject=? AND details=? AND created_at >= (NOW() - INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1');
        $dup->execute([$u['id'], $office['office_code'], $f['subject'], $f['details']]);
        if ($old = (int)$dup->fetchColumn()) redirect('ticket.php?id=' . $old);

        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO tickets (office_code,user_id,category_id,subject,details,priority) VALUES (?,?,?,?,?,?)')
                ->execute([$office['office_code'], $u['id'], $f['category'], $f['subject'], $f['details'], $f['priority']]);
            $id = (int)$pdo->lastInsertId();
            $code = strtoupper($office['office_code']) . '-' . date('y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE tickets SET code=? WHERE id=?')->execute([$code, $id]);
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Ticket not saved: ' . $ex->getMessage());
            flash('Sorry, your ticket could not be saved. Please try again in a moment.', 'err');
            goto show_form;
        }
        $saved = attach_save($pdo, $id, null, $u['id'], $attOk);
        audit('TICKET_CREATED', "$code → {$office['office_name']}: {$f['subject']}");
        flash("Ticket $code was sent to {$office['office_name']}.");
        if (count($attOk) > $saved) flash('Your ticket was sent, but ' . (count($attOk) - $saved) . ' attachment(s) could not be saved.', 'warn');
        // Answer the browser first; the confirmation / office emails go out afterwards so a slow mail service never blocks or breaks submitting.
        redirect_then_continue('ticket.php?id=' . $id);
        try {
            notify_requester($pdo, $id, 'We received your ticket', '<p>Your ticket was sent to <b>' . e($office['office_name']) . '</b>. You will get an email when the office replies or the status changes.</p>');
            notify_office_admins($pdo, $office['office_code'], 'New ticket: ' . $code, '<p><b>' . e($u['full_name']) . '</b> filed a <b>' . e($f['priority']) . '</b> ticket:</p><p><b>' . e($f['subject']) . '</b></p>', $id);
        } catch (Throwable $ex) { error_log('Ticket emails failed: ' . $ex->getMessage()); }
        exit;
    }
}
show_form:
page_start('New ticket', 'Choose the office, then describe your concern');
?>
<div class="card" style="max-width:720px">
  <?php if (!$offices): ?><div class="alert warn">No offices are active yet. Please contact the school.</div><?php endif; ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="field"><label for="officeSel">Report to which office?</label>
      <select name="office_code" id="officeSel" required>
        <option value="">Choose an office…</option>
        <?php foreach ($offices as $o): ?><option value="<?= e($o['office_code']) ?>" <?= $f['office'] === $o['office_code'] ? 'selected' : '' ?>><?= e($o['office_name']) ?></option><?php endforeach; ?>
      </select>
      <div class="hint" id="officeDesc"></div></div>
    <div class="row2">
      <div class="field"><label>Category</label><select name="category" id="catSel" required></select></div>
      <div class="field"><label>Priority</label><select name="priority"><?php foreach (PRIORITIES as $p): ?><option <?= $f['priority'] === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label>Subject</label><input type="text" name="subject" maxlength="150" value="<?= e($f['subject']) ?>" placeholder="Short summary of your concern" required></div>
    <div class="field"><label>Details</label><textarea name="details" placeholder="What happened? Include dates, names or reference numbers that help." required><?= e($f['details']) ?></textarea></div>
    <?= attach_input_html() ?>
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i>Submit ticket</button>
    <a class="btn" href="<?= url('student/dashboard.php') ?>">Cancel</a>
  </form>
</div>
<script>
const CATS = <?= json_encode($catsBy, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const DESC = <?= json_encode(array_map(fn($o) => (string)$o['description'], $byCode), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const WANT = <?= (int)$f['category'] ?>;
const off = document.getElementById('officeSel'), cat = document.getElementById('catSel');
function fillCats() {
  const list = CATS[off.value] || [];
  cat.innerHTML = '';
  const first = document.createElement('option'); first.value = ''; first.textContent = list.length ? 'Choose a category…' : 'Choose an office first'; cat.appendChild(first);
  list.forEach(([id, name]) => { const o = document.createElement('option'); o.value = id; o.textContent = name; if (id === WANT) o.selected = true; cat.appendChild(o); });
  document.getElementById('officeDesc').textContent = DESC[off.value] || '';
}
off.addEventListener('change', () => { fillCats(); });
fillCats();
</script>
<?php page_end();
