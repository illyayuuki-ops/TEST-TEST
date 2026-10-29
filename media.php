<?php
// Media booking: reserve a venue (room) or equipment for a date and time slot.
// ?e=venue  -> Venue booking      ?e=equipment -> Equipment booking
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
require __DIR__ . '/includes/media.php';
$pdo = db();

$kinds = ['venue' => ['Venue', 'Room / Venue', 'Venue booking', 'today'], 'equipment' => ['Equipment', 'Equipment', 'Equipment booking', 'all']];
$e = $_GET['e'] ?? 'venue';
if (!isset($kinds[$e])) $e = 'venue';
[$kind, $itemLabel, $pageTitle, $defaultView] = $kinds[$e];
$base = 'media.php?e=' . $e;

// The booking table comes from the media-booking SQL file. Say so clearly if it has not been imported yet.
try { $pdo->query('SELECT 1 FROM media_booking LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>' . e($pageTitle) . '</h1><div class="card"><p>The booking table is missing. Import <code>01_media_booking.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
$items = bookable_items($kind);
$slots = lookup_list('Time');

// A librarian whose library is not "All libraries" only sees and changes bookings of that library.
function booking_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM media_booking WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && $r['Library'] !== user_library()) return null;
    return $r;
}

$editId = (int)($_GET['id'] ?? 0);
$row = ['Booking_date' => date('Y-m-d'), 'Time_slot' => '', 'Item' => '', 'Reserved_by' => '', 'Faculty' => '', 'Subject' => '', 'Purpose' => '', 'Library' => lib_all() ? '' : user_library(), 'Submitted_at' => null];
$formError = '';

// ---------- save / delete (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete') {
        $old = booking_row((int)($_POST['id'] ?? 0));
        if ($old) { $pdo->prepare('DELETE FROM media_booking WHERE ID = ?')->execute([$old['ID']]); flash('Booking deleted.'); }
        else flash('Not done: booking not found.');
        redirect($base);
    }

    $editId = (int)($_POST['id'] ?? 0);
    $old = $editId ? booking_row($editId) : null;
    if ($editId && (!$old || $old['Kind'] !== $kind)) { flash('Not done: booking not found.'); redirect($base); }

    $row = [
        'Booking_date' => trim($_POST['date'] ?? ''), 'Item' => trim($_POST['item'] ?? ''), 'Time_slot' => trim($_POST['slot'] ?? ''),
        'Reserved_by' => trim($_POST['reserved_by'] ?? ''), 'Faculty' => trim($_POST['faculty'] ?? ''),
        'Subject' => trim($_POST['subject'] ?? ''), 'Purpose' => trim($_POST['purpose'] ?? ''),
        'Library' => lib_all() ? trim($_POST['library'] ?? '') : user_library(), 'Submitted_at' => $old['Submitted_at'] ?? null,
    ];
    // Custom time (instead of a ready-made slot)
    $cf = trim($_POST['custom_from'] ?? ''); $ct = trim($_POST['custom_to'] ?? '');
    if ($cf !== '' && $ct !== '' && preg_match('/^\d{2}:\d{2}$/', $cf) && preg_match('/^\d{2}:\d{2}$/', $ct)) {
        $row['Time_slot'] = date('g:iA', strtotime($cf)) . '-' . date('g:iA', strtotime($ct));
    }

    $d = DateTime::createFromFormat('Y-m-d', $row['Booking_date']);
    $mins = slot_minutes($row['Time_slot']);
    $itemOk = in_array($row['Item'], $items, true) || ($old && $row['Item'] === $old['Item']);
    if (!$d || $d->format('Y-m-d') !== $row['Booking_date']) $formError = 'Pick a valid reservation date.';
    elseif ($row['Time_slot'] === '' || !$mins) $formError = 'Pick a reservation time (or enter a custom From and To time).';
    elseif ($mins[1] <= $mins[0]) $formError = 'The end time must be later than the start time.';
    elseif ($row['Item'] === '' || !$itemOk) $formError = "Pick a valid $itemLabel from the list.";
    elseif ($row['Reserved_by'] === '') $formError = 'Enter who the booking is for (Reserved by).';
    elseif (lib_all() && !in_array($row['Library'], $libs, true)) $formError = 'Pick the library this booking belongs to.';
    elseif (strlen($row['Reserved_by']) > 100 || strlen($row['Faculty']) > 100 || strlen($row['Subject']) > 150 || strlen($row['Purpose']) > 150) $formError = 'One of the text fields is too long.';
    elseif ($c = booking_conflict($kind, $row['Item'], $row['Booking_date'], $mins[0], $mins[1], $editId)) {
        $formError = "Not saved: {$row['Item']} is already booked on " . fdate($c['Booking_date']) . " at {$c['Time_slot']} by {$c['Reserved_by']}"
                   . ($c['Faculty'] ? " ({$c['Faculty']})" : '') . '. Choose another time.';
    }

    if ($formError === '') {
        $vals = [$row['Item'], $row['Booking_date'], $row['Time_slot'], $mins[0], $mins[1], $row['Reserved_by'],
                 $row['Faculty'] ?: null, $row['Subject'] ?: null, $row['Purpose'] ?: null, $row['Library']];
        if ($old) {
            $pdo->prepare('UPDATE media_booking SET Item=?, Booking_date=?, Time_slot=?, Start_min=?, End_min=?, Reserved_by=?, Faculty=?, Subject=?, Purpose=?, Library=? WHERE ID=?')
                ->execute(array_merge($vals, [$old['ID']]));
            flash('Booking updated.');
        } else {
            $pdo->prepare('INSERT INTO media_booking (Kind, Item, Booking_date, Time_slot, Start_min, End_min, Reserved_by, Faculty, Subject, Purpose, Library, Submitted_at, Encoded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
                ->execute(array_merge([$kind], $vals, [$_SESSION['user'] ?? '']));
            flash("Booked {$row['Item']} on " . fdate($row['Booking_date']) . " at {$row['Time_slot']}.");
        }
        redirect($base);
    }
} elseif ($editId) {
    $found = booking_row($editId);
    if ($found && $found['Kind'] === $kind) $row = $found; else { $editId = 0; }
}

// ---------- list filters ----------
$view = $_GET['w'] ?? $defaultView; if (!in_array($view, ['today', 'all', 'range'], true)) $view = $defaultView;
$from = $_GET['from'] ?? date('Y-m-d'); $to = $_GET['to'] ?? date('Y-m-d');
$okDate = fn($v) => ($x = DateTime::createFromFormat('Y-m-d', (string)$v)) && $x->format('Y-m-d') === $v;
if (!$okDate($from)) $from = date('Y-m-d'); if (!$okDate($to)) $to = date('Y-m-d');
$q = trim($_GET['q'] ?? ''); $lf = $_GET['lib'] ?? '';
$where = ['Kind = :k']; $args = ['k' => $kind];
if ($view === 'today') $where[] = 'Booking_date = CURDATE()';
elseif ($view === 'range') { $where[] = 'Booking_date BETWEEN :f AND :t'; $args['f'] = $from; $args['t'] = $to; }
if ($q !== '') {
    $like = [];
    foreach (['Item', 'Reserved_by', 'Faculty', 'Subject', 'Purpose'] as $i => $c) { $like[] = "$c LIKE :q$i"; $args["q$i"] = "%$q%"; }
    $where[] = '(' . implode(' OR ', $like) . ')';
}
if (!lib_all()) { $where[] = 'Library = :lib'; $args['lib'] = user_library(); }
elseif (in_array($lf, $libs, true)) { $where[] = 'Library = :lib'; $args['lib'] = $lf; }
$st = $pdo->prepare('SELECT * FROM media_booking WHERE ' . implode(' AND ', $where) . ' ORDER BY Booking_date DESC, Start_min, ID LIMIT 300');
$st->execute($args); $rows = $st->fetchAll();

$slotChoices = $slots;
if ($row['Time_slot'] !== '' && !in_array($row['Time_slot'], $slotChoices, true)) array_unshift($slotChoices, $row['Time_slot']);
$itemChoices = $items;
if ($row['Item'] !== '' && !in_array($row['Item'], $itemChoices, true)) array_unshift($itemChoices, $row['Item']);
$listQs = http_build_query(['e' => $e, 'w' => $view, 'from' => $from, 'to' => $to, 'q' => $q, 'lib' => $lf]);

include __DIR__ . '/includes/header.php'; ?>
<div class="head-row"><h1><?= e($pageTitle) ?></h1></div>
<?php if ($formError): ?><div class="err" role="alert"><?= e($formError) ?></div><?php endif; ?>

<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0"><?= $editId ? 'Edit booking' : 'New booking' ?></h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int)$editId ?>">
  <label><span>Reservation date</span><input type="date" name="date" value="<?= e($row['Booking_date']) ?>" required></label>
  <label><span>Reservation time</span>
    <select name="slot"><option value="">— choose a time slot —</option>
      <?php foreach ($slotChoices as $s): ?><option <?= $s === $row['Time_slot'] ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
  <label><span>…or a custom time (leave empty to use the slot above)</span>
    <span style="display:flex;gap:8px;font-weight:400"><input type="time" name="custom_from" aria-label="From"> <input type="time" name="custom_to" aria-label="To"></span></label>
  <label><span><?= e($itemLabel) ?></span>
    <select name="item" required><option value="">— choose —</option>
      <?php foreach ($itemChoices as $it): ?><option <?= $it === $row['Item'] ? 'selected' : '' ?>><?= e($it) ?></option><?php endforeach; ?></select>
    <?php if (!$items): ?><small class="muted">Nothing to choose yet.<?= can_view_page('crud.php', $e === 'venue' ? 'rooms' : 'equipment') ? ' Add some under <a href="crud.php?e=' . ($e === 'venue' ? 'rooms' : 'equipment') . '">' . ($e === 'venue' ? 'Rooms' : 'Equipment') . '</a>.' : ' Ask an administrator to add some.' ?></small><?php endif; ?></label>
  <label><span>Reserved by</span><input type="text" name="reserved_by" value="<?= e($row['Reserved_by']) ?>" maxlength="100" required></label>
  <label><span>Faculty / Teacher</span><input type="text" name="faculty" list="dl-faculty" value="<?= e($row['Faculty']) ?>" maxlength="100"></label>
  <label><span>Subject / Course</span><input type="text" name="subject" list="dl-subject" value="<?= e($row['Subject']) ?>" maxlength="150"></label>
  <label><span>Purpose</span><input type="text" name="purpose" list="dl-purpose" value="<?= e($row['Purpose']) ?>" maxlength="150"></label>
  <?php if (lib_all()): ?>
    <label><span>Library</span><select name="library" required><option value="">— choose —</option>
      <?php foreach ($libs as $l): ?><option <?= $l === $row['Library'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <?php if ($editId && $row['Submitted_at']): ?><p class="muted" style="margin:0">Date/time submitted: <?= e(date('M j, Y g:i A', strtotime($row['Submitted_at']))) ?></p><?php endif; ?>
  <div class="actions"><button class="btn primary"><?= $editId ? 'Save changes' : 'Add booking' ?></button>
    <?php if ($editId): ?><a class="btn" href="<?= e($base) ?>">Cancel</a><?php endif; ?></div>
  <datalist id="dl-faculty"><?php foreach (lookup_list('Professors') as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl-subject"><?php foreach (lookup_list('Subjects') as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl-purpose"><?php foreach (lookup_list('Purpose') as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist>
</form>

<form class="toolbar no-print" method="get">
  <input type="hidden" name="e" value="<?= e($e) ?>">
  <select name="w" aria-label="Show">
    <option value="today" <?= $view === 'today' ? 'selected' : '' ?>>Today</option>
    <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>All dates</option>
    <option value="range" <?= $view === 'range' ? 'selected' : '' ?>>Date range</option></select>
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search <?= e(strtolower($itemLabel)) ?>, name, teacher, subject, purpose…">
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option>
    <?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">List</button>
  <a class="btn ghost" href="<?= e($base) ?>">Clear</a>
  <button type="button" class="btn ghost" data-print>Print</button>
</form>
<p class="muted"><?= number_format(count($rows)) ?> booking<?= count($rows) === 1 ? '' : 's' ?>
  <?= $view === 'today' ? '· today, ' . e(fdate(date('Y-m-d'))) : ($view === 'range' ? '· ' . e(fdate($from)) . ' to ' . e(fdate($to)) : '· all dates') ?><?= count($rows) === 300 ? ' (showing the latest 300)' : '' ?></p>

<div class="table-wrap"><table class="data" data-sortable><thead><tr>
  <th>Date</th><th>Time</th><th><?= e($itemLabel) ?></th><th>Reserved by</th><th>Faculty / Teacher</th><th>Subject / Course</th><th>Purpose</th>
  <?php if (lib_all()): ?><th>Library</th><?php endif; ?><th class="no-print"></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e(fdate($r['Booking_date'])) ?></td><td><?= e($r['Time_slot']) ?></td><td><?= e($r['Item']) ?></td><td><?= e($r['Reserved_by']) ?></td>
  <td><?= e($r['Faculty']) ?></td><td><?= e($r['Subject']) ?></td><td><?= e($r['Purpose']) ?></td>
  <?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
  <td class="no-print row-actions"><a href="<?= e($base . '&id=' . (int)$r['ID']) ?>">Edit</a>
    <form method="post" data-confirm="Delete this booking?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Delete</button></form></td>
</tr><?php endforeach; if (!$rows): ?><tr><td colspan="<?= lib_all() ? 9 : 8 ?>" class="muted">No bookings found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
