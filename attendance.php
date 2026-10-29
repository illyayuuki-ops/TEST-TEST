<?php
// Attendance monitoring: scan a patron ID, then see hourly counts, a graph and averages.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db();
$pageTitle = 'Attendance';
$base = 'attendance.php';
const ATT_REPEAT_MIN = 30;          // without time in / out: a repeat scan of the same ID within this many minutes is not counted
const ATT_GUARD_MIN = 2;            // with time in / out: a second scan within this many minutes is taken as an accidental double scan
const ATT_FIRST_HOUR = 6, ATT_LAST_HOUR = 21;   // the old screen shows 6:00 AM to 9:00 PM

try { $pdo->query('SELECT 1 FROM attendance_log LIMIT 1'); }
catch (PDOException $ex) {
    include __DIR__ . '/includes/header.php';
    echo '<h1>Attendance</h1><div class="card"><p>The attendance table is missing. Import <code>03_attendance.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$libs = libraries();
// Manual Add / Deduct corrections to the hourly counts come from 11_gap_fixes.sql; the page works without it.
$hasAdj = false; try { $pdo->query('SELECT 1 FROM attendance_adj LIMIT 1'); $hasAdj = true; } catch (PDOException $ex) {}
$okDate = fn($v) => ($x = DateTime::createFromFormat('Y-m-d', (string)$v)) && $x->format('Y-m-d') === $v;
$scanLib = lib_all() ? ($_SESSION['att_lib'] ?? '') : user_library();
$formError = '';
$inout = att_has_inout();
att_autoclose();   // anyone still in after closing time is timed out automatically

// ---------- scan / delete (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'scan') {
        $idn = trim($_POST['idnum'] ?? '');
        if (lib_all()) { $scanLib = trim($_POST['library'] ?? ''); if (in_array($scanLib, $libs, true)) $_SESSION['att_lib'] = $scanLib; }
        if ($idn === '' || strlen($idn) > 30) $formError = 'Scan or type a valid ID number.';
        elseif (!in_array($scanLib, $libs, true)) $formError = 'Pick the library you are counting for.';
        else {
            $now = date('Y-m-d H:i:s');
            if ($inout) {   // the same ID already in the library today: this scan is the time out
                $op = $pdo->prepare('SELECT * FROM attendance_log WHERE ID_Number = ? AND Library = ? AND Time_out IS NULL AND Scanned_at >= ? ORDER BY Scanned_at DESC LIMIT 1');
                $op->execute([$idn, $scanLib, date('Y-m-d') . ' 00:00:00']); $o = $op->fetch();
                if ($o) {
                    if (strtotime($o['Scanned_at']) > strtotime('-' . ATT_GUARD_MIN . ' minutes')) { flash(($o['Name'] ?: $idn) . ' timed in at ' . date('g:i A', strtotime($o['Scanned_at'])) . '. Scan again in a couple of minutes to time out.'); redirect($base); }
                    $pdo->prepare('UPDATE attendance_log SET Time_out = ? WHERE ID = ?')->execute([$now, $o['ID']]);
                    flash(($o['Name'] ?: $idn) . ' timed OUT at ' . date('g:i A', strtotime($now)) . ' (stayed ' . att_stay($o['Scanned_at'], $now) . ').');
                    redirect($base);
                }
            } else {
            $dup = $pdo->prepare('SELECT Scanned_at, Name FROM attendance_log WHERE ID_Number = ? AND Library = ? AND Scanned_at > ? ORDER BY Scanned_at DESC LIMIT 1');
            $dup->execute([$idn, $scanLib, date('Y-m-d H:i:s', strtotime('-' . ATT_REPEAT_MIN . ' minutes'))]);
            $d = $dup->fetch();
            if ($d) { flash('Already counted at ' . date('g:i A', strtotime($d['Scanned_at'])) . ' (' . ($d['Name'] ?: $idn) . '). Repeat scans within ' . ATT_REPEAT_MIN . ' minutes are ignored.'); redirect($base); }
            }
            $c = client_by_id($idn);
            $pdo->prepare('INSERT INTO attendance_log (ID_Number, Name, User_class, Registered, Library, Scanned_at, Encoded_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$idn, $c['Name'] ?? null, $c['User_class'] ?? null, $c ? 1 : 0, $scanLib, $now, $_SESSION['user'] ?? '']);
            flash(($c ? $c['Name'] . ($inout ? ' timed IN' : ' counted') : "ID $idn " . ($inout ? 'timed IN' : 'counted') . " as a visitor (not registered)") . ' at ' . date('g:i A', strtotime($now)) . '.');
            redirect($base);
        }
    } elseif (($do === 'adjust' || $do === 'reset_adj') && can_edit_records() && $hasAdj) {
        $keep = http_build_query(array_intersect_key($_POST, array_flip(['from', 'to', 'lib'])));
        if ($do === 'adjust') {
            $d = (string)($_POST['adj_date'] ?? ''); $h = (int)($_POST['adj_hour'] ?? -1); $n = (int)($_POST['adj_n'] ?? 0);
            $kind = ($_POST['adj_kind'] ?? '') === 'deduct' ? -1 : 1;
            $al = lib_all() ? trim($_POST['adj_lib'] ?? '') : user_library();
            if (!$okDate($d)) flash('Not done: choose a valid date.');
            elseif ($h < ATT_FIRST_HOUR || $h > ATT_LAST_HOUR) flash('Not done: choose an hour from 6:00 AM to 9:00 PM.');
            elseif ($n < 1 || $n > 10000) flash('Not done: enter a number from 1 to 10,000.');
            elseif (!in_array($al, $libs, true)) flash('Not done: choose a library.');
            else {
                $pdo->prepare('INSERT INTO attendance_adj (Adj_date, Hour_slot, Delta, Library, Created_at, Created_by) VALUES (?,?,?,?,NOW(),?)')->execute([$d, $h, $kind * $n, $al, $_SESSION['user'] ?? '']);
                flash(($kind > 0 ? 'Added ' : 'Deducted ') . $n . ' ' . ($kind > 0 ? 'to' : 'from') . ' ' . date('g:00 A', strtotime(sprintf('%02d:00', $h))) . ' on ' . fdate($d) . '.');
            }
        } else {   // Reset: remove the manual corrections inside the chosen dates (the scans themselves are never touched)
            $rf = (string)($_POST['from'] ?? ''); $rt = (string)($_POST['to'] ?? '');
            if ($okDate($rf) && $okDate($rt)) {
                $sql = 'DELETE FROM attendance_adj WHERE Adj_date BETWEEN ? AND ?'; $a2 = [min($rf, $rt), max($rf, $rt)];
                if (!lib_all()) { $sql .= ' AND Library = ?'; $a2[] = user_library(); }
                elseif (in_array($_POST['lib'] ?? '', $libs, true)) { $sql .= ' AND Library = ?'; $a2[] = $_POST['lib']; }
                $st = $pdo->prepare($sql); $st->execute($a2); flash('Manual corrections reset (' . $st->rowCount() . ' removed). Scans were not touched.');
            }
        }
        redirect($base . '?' . $keep);
    } elseif ($do === 'delete' && can_edit_records()) {
        $st = $pdo->prepare('SELECT * FROM attendance_log WHERE ID = ?'); $st->execute([(int)($_POST['id'] ?? 0)]); $r = $st->fetch();
        if ($r && (lib_all() || $r['Library'] === user_library())) { $pdo->prepare('DELETE FROM attendance_log WHERE ID = ?')->execute([$r['ID']]); flash('Scan removed.'); }
        else flash('Not done: scan not found.');
        redirect($base . '?' . http_build_query(array_intersect_key($_POST, array_flip(['from', 'to', 'lib']))));
    }
}

// ---------- statistics filters ----------
$from = $_GET['from'] ?? date('Y-m-d'); $to = $_GET['to'] ?? date('Y-m-d');
if (!$okDate($from)) $from = date('Y-m-d'); if (!$okDate($to)) $to = date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$lf = $_GET['lib'] ?? '';
$where = 'Scanned_at >= :f AND Scanned_at < :t'; $args = ['f' => "$from 00:00:00", 't' => date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'];
if (!lib_all()) { $where .= ' AND Library = :lib'; $args['lib'] = user_library(); }
elseif (in_array($lf, $libs, true)) { $where .= ' AND Library = :lib'; $args['lib'] = $lf; }

$st = $pdo->prepare("SELECT HOUR(Scanned_at) AS h, COUNT(*) AS n FROM attendance_log WHERE $where GROUP BY h"); $st->execute($args);
$byHour = array_fill(0, 24, 0); foreach ($st->fetchAll() as $r) $byHour[(int)$r['h']] = (int)$r['n'];
$adjTotal = 0;
if ($hasAdj) {
    $aw = 'Adj_date BETWEEN :af AND :at'; $aa = ['af' => $from, 'at' => $to];
    if (!lib_all()) { $aw .= ' AND Library = :alib'; $aa['alib'] = user_library(); }
    elseif (in_array($lf, $libs, true)) { $aw .= ' AND Library = :alib'; $aa['alib'] = $lf; }
    $st = $pdo->prepare("SELECT Hour_slot AS h, SUM(Delta) AS n FROM attendance_adj WHERE $aw GROUP BY Hour_slot"); $st->execute($aa);
    foreach ($st->fetchAll() as $r) { $adjTotal += (int)$r['n']; $byHour[(int)$r['h']] = max(0, $byHour[(int)$r['h']] + (int)$r['n']); }
}
$total = array_sum($byHour);
$inRange = 0; for ($h = ATT_FIRST_HOUR; $h <= ATT_LAST_HOUR; $h++) $inRange += $byHour[$h];
$other = $total - $inRange;
$st = $pdo->prepare("SELECT COUNT(DISTINCT DATE(Scanned_at)) AS days, SUM(Registered = 0) AS visitors FROM attendance_log WHERE $where"); $st->execute($args);
$x = $st->fetch(); $days = (int)$x['days']; $visitors = (int)$x['visitors'];
$calDays = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
$peakH = $total ? array_search(max($byHour), $byHour, true) : null;
$max = max(1, max($byHour));
$hourLabel = fn($h) => date('g:00 A', strtotime(sprintf('%02d:00', $h)));
$today = date('Y-m-d');
$todayArgs = ['f' => "$today 00:00:00", 't' => date('Y-m-d', strtotime('+1 day')) . ' 00:00:00'];
$tw = 'Scanned_at >= :f AND Scanned_at < :t'; if (!lib_all()) { $tw .= ' AND Library = :lib'; $todayArgs['lib'] = user_library(); }
$st = $pdo->prepare("SELECT COUNT(*) FROM attendance_log WHERE $tw"); $st->execute($todayArgs); $todayCount = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT * FROM attendance_log WHERE $tw ORDER BY Scanned_at DESC LIMIT 15"); $st->execute($todayArgs); $recent = $st->fetchAll();
$inNow = 0;
if ($inout) { $st = $pdo->prepare("SELECT COUNT(*) FROM attendance_log WHERE $tw AND Time_out IS NULL"); $st->execute($todayArgs); $inNow = (int)$st->fetchColumn(); }

// The old program's one saved total (UserStat), shown on its own.
$prev = null;
try { $prev = $pdo->query('SELECT * FROM UserStat WHERE Total > 0 ORDER BY ID DESC LIMIT 1')->fetch() ?: null; } catch (PDOException $ex) {}

include __DIR__ . '/includes/header.php'; ?>
<h1>Attendance monitoring</h1>
<?php if ($formError): ?><div class="err" role="alert"><?= e($formError) ?></div><?php endif; ?>

<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Scan patron ID</h2>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="scan">
  <p class="att-clock" aria-live="off"><b id="att-time">--:--:--</b> <span id="att-date" class="muted"></span></p>
  <label><span>ID number (scan or type, then press Enter)</span><input type="text" name="idnum" maxlength="30" required autofocus></label>
  <?php if (lib_all()): ?>
    <label><span>Library</span><select name="library" required><option value="">— choose —</option>
      <?php foreach ($libs as $l): ?><option <?= $l === $scanLib ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <?php else: ?><p class="muted" style="margin:0">Library: <?= e(user_library()) ?></p><?php endif; ?>
  <p class="muted" style="margin:0" data-live="today"><strong><?= number_format($todayCount) ?></strong> in today so far<?= $inout ? ' · <strong>' . number_format($inNow) . '</strong> in the library now' : '' ?>.</p>
  <p class="muted" style="margin:0"><?= $inout ? 'The time is recorded automatically. The first scan of an ID is the time in; the next scan is the time out. Anyone still in at ' . e(date('g:i A', strtotime(att_closing_time()))) . ' is timed out automatically.' : 'Repeat scans of the same ID within ' . ATT_REPEAT_MIN . ' minutes are ignored. Import 14_attendance_timeinout.sql to record time in and time out.' ?></p>
  <div class="actions"><button class="btn primary"><?= $inout ? 'Time in / out' : 'Count' ?></button></div>
</form>

<div data-live="recent"><?php if ($recent): ?>
<section class="card no-print"><h2>Latest scans today</h2>
<div class="table-wrap"><table class="data"><thead><tr><th><?= $inout ? 'Time in' : 'Time' ?></th><?php if ($inout): ?><th>Time out</th><th>Stayed</th><?php endif; ?><th>ID</th><th>Name</th><th>Class</th><?php if (lib_all()): ?><th>Library</th><?php endif; ?><th></th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= e(date('g:i A', strtotime($r['Scanned_at']))) ?></td>
  <?php if ($inout): ?><td><?= $r['Time_out'] ? e(date('g:i A', strtotime($r['Time_out']))) . (!empty($r['Auto_closed']) ? ' <small class="muted">(auto)</small>' : '') : '<span class="pill inv-on-shelf">In library</span>' ?></td><td><?= e(att_stay($r['Scanned_at'], $r['Time_out'])) ?></td><?php endif; ?>
  <td><?= e($r['ID_Number']) ?></td>
  <td><?= $r['Registered'] ? e($r['Name']) : '<span class="muted">Visitor (not registered)</span>' ?></td><td><?= e($r['User_class']) ?></td>
  <?php if (lib_all()): ?><td><?= e($r['Library']) ?></td><?php endif; ?>
  <td class="row-actions"><?php if (can_edit_records()): ?><form method="post" data-confirm="Remove this scan?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['ID'] ?>"><button class="link danger">Remove</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?></div>

<h2>Statistics</h2>
<form class="toolbar no-print" method="get">
  <input type="date" name="from" value="<?= e($from) ?>" aria-label="From"> <input type="date" name="to" value="<?= e($to) ?>" aria-label="To">
  <?php if (lib_all()): ?><select name="lib" aria-label="Library"><option value="">All libraries</option>
    <?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn">Show</button> <a class="btn ghost" href="<?= e($base) ?>">Today</a>
  <button type="button" class="btn ghost" data-print>Print</button></form>
<?php if ($hasAdj && can_edit_records()): ?>
<form method="post" class="card form no-print" autocomplete="off">
  <h2 style="margin:0">Add / Deduct a count</h2>
  <p class="muted" style="margin:0">For visitors who were not scanned, or scans that should not count. Corrections show in the hourly numbers below<?= $adjTotal ? ' (' . ($adjTotal > 0 ? '+' : '') . (int)$adjTotal . ' in this period)' : '' ?>.</p>
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="adjust">
  <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="lib" value="<?= e($lf) ?>">
  <label><span>Date</span><input type="date" name="adj_date" value="<?= e($to) ?>" required></label>
  <label><span>Hour</span><select name="adj_hour"><?php for ($h = ATT_FIRST_HOUR; $h <= ATT_LAST_HOUR; $h++): ?><option value="<?= $h ?>" <?= (int)date('G') === $h ? 'selected' : '' ?>><?= e($hourLabel($h)) ?></option><?php endfor; ?></select></label>
  <label><span>How many</span><input type="number" name="adj_n" min="1" max="10000" value="1" required></label>
  <?php if (lib_all()): ?><label><span>Library</span><select name="adj_lib" required><option value="">— choose —</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <div class="actions"><button class="btn primary" name="adj_kind" value="add">Add</button> <button class="btn" name="adj_kind" value="deduct">Deduct</button></div>
</form>
<?php endif; ?>
<p class="muted"><?= $from === $to ? e(fdate($from)) : e(fdate($from)) . ' to ' . e(fdate($to)) ?><?= lib_all() ? ' · ' . e($lf ?: 'All libraries') : ' · ' . e(user_library()) ?></p>

<div data-live="stats">
<div class="cards">
  <div class="card stat"><span class="num"><?= number_format($total) ?></span><span>Total visits</span></div>
  <div class="card stat"><span class="num"><?= $days ? number_format($total / $days, 1) : '0' ?></span><span>Average per day (<?= $days ?> day<?= $days === 1 ? '' : 's' ?> with scans)</span></div>
  <div class="card stat"><span class="num"><?= $peakH === null ? '—' : e($hourLabel($peakH)) ?></span><span>Busiest hour</span></div>
  <div class="card stat"><span class="num"><?= number_format($visitors) ?></span><span>Visitors (not registered)</span></div>
</div>
<?php if ($calDays > 1 && $days < $calDays): ?><p class="muted">The range covers <?= $calDays ?> days; the average counts only the <?= $days ?> with scans.</p><?php endif; ?>

<section class="card"><h2>Visits per hour</h2>
<?php for ($h = ATT_FIRST_HOUR; $h <= ATT_LAST_HOUR; $h++): ?>
  <div class="bar-row"><span class="bar-label"><?= e($hourLabel($h)) ?></span><span class="bar"><i style="width:<?= round($byHour[$h] / $max * 100) ?>%"></i></span><span class="bar-n"><?= number_format($byHour[$h]) ?></span></div>
<?php endfor; ?>
<?php if ($other): ?><div class="bar-row"><span class="bar-label">Other hours</span><span class="bar"><i style="width:<?= round($other / $max * 100) ?>%"></i></span><span class="bar-n"><?= number_format($other) ?></span></div><?php endif; ?>
<p style="margin:12px 0 0"><strong>Total: <?= number_format($total) ?></strong></p></section>
</div>

<?php if ($prev): ?>
<section class="card"><h2>Previous records (old program)</h2>
  <p class="muted">One saved total from the old system. It is not mixed into the counts above.</p>
  <div class="table-wrap"><table class="data"><thead><tr><th>Total</th><?php foreach (['sixam' => '6 AM', 'Seven' => '7', 'Eight' => '8', 'Nine' => '9', 'Ten' => '10', 'Eleven' => '11', 'Twelve' => '12 PM', 'One' => '1', 'Two' => '2', 'Three' => '3', 'Four' => '4', 'Five' => '5', 'Six' => '6', 'Sevenpm' => '7', 'Eightpm' => '8', 'Ninepm' => '9'] as $k => $l): ?><th><?= e($l) ?></th><?php endforeach; ?></tr></thead>
  <tbody><tr><td><strong><?= number_format((int)$prev['Total']) ?></strong></td><?php foreach (['sixam','Seven','Eight','Nine','Ten','Eleven','Twelve','One','Two','Three','Four','Five','Six','Sevenpm','Eightpm','Ninepm'] as $k): ?><td><?= (int)$prev[$k] ?></td><?php endforeach; ?></tr></tbody></table></div></section>
<?php endif; ?>
<script>
// Live screen: the clock ticks every second (server time), and the counts, graph and latest scans refresh by themselves.
(function () {
  var off = <?= (int)(time() * 1000) ?> - Date.now();
  var t = document.getElementById('att-time'), d = document.getElementById('att-date');
  function tick() { var n = new Date(Date.now() + off); if (t) t.textContent = n.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', second: '2-digit'}); if (d) d.textContent = n.toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'}); }
  tick(); setInterval(tick, 1000);
  function refresh() {
    if (document.hidden) return;
    fetch(location.href, {credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      document.querySelectorAll('[data-live]').forEach(function (el) {
        var fresh = doc.querySelector('[data-live="' + el.dataset.live + '"]');
        if (fresh) el.innerHTML = fresh.innerHTML;
      });
    }).catch(function () {});
  }
  setInterval(refresh, 10000);
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
