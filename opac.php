<?php
// OPAC: the public online catalog. No login needed. Shows catalog information and availability only (never who borrowed something).
// Students can ask for a hold with their ID number and last name; library staff approve it under Circulation > Online requests.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pdo = db(); $cfg = circ_cfg();
$haveReq = table_exists('online_request');
$libs = libraries();
$self = 'opac.php';

// ---------- availability of one copy ----------
function opac_state(array $r, array $cfg): array {   // [label, css class, can_request]
    $acc = (string)$r['Accession']; $loc = (string)($r['Location'] ?? ''); $stat = strtolower(trim((string)($r['Status'] ?? '')));
    if (in_array($stat, ['lost', 'lost/paid', 'missing', 'withdrawn', 'discarded'], true)) return ['Not available', 'out', false];
    if ($stat === 'i-loan' || $stat === 'inter-library loan') return ['On inter-library loan', 'out', false];
    $lp = db()->prepare("SELECT 1 FROM Borrower WHERE Accession_number = ? AND Status = 'Lost/Paid' LIMIT 1"); $lp->execute([$acc]);
    if ($lp->fetchColumn()) return ['Not available', 'out', false];
    if ($loan = open_loan($acc)) return $loan['Status'] === 'Lost' ? ['Not available', 'out', false] : ['On loan, due ' . fdate($loan['Due_date']), 'loan', false];
    if ($res = active_reservation($acc)) return ['On hold until ' . fdate($res['DateExpired']), 'loan', false];
    foreach ($cfg['no_loan_keywords'] as $k) if ($k !== '' && stripos($loc, $k) !== false) return ['Library use only', 'ref', false];
    if (table_exists('online_request')) {
        $q = db()->prepare("SELECT 1 FROM online_request WHERE Accession = ? AND Status = 'Pending' LIMIT 1"); $q->execute([$acc]);
        if ($q->fetchColumn()) return ['Requested (waiting for the library)', 'loan', false];
    }
    return ['Available', 'ok', true];
}

// ---------- POST: request a hold / check my requests ----------
$msg = flash(); $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $haveReq) {
    csrf_check();
    $idnum = trim((string)($_POST['idnum'] ?? '')); $last = trim((string)($_POST['lastname'] ?? ''));
    $client = $idnum !== '' ? client_by_id($idnum) : null;
    $okClient = $client && strlen($last) >= 2 && stripos((string)$client['Name'], $last) !== false;   // ID number and last name must belong to the same borrower
    if (($_POST['do'] ?? '') === 'request') {
        $acc = trim((string)($_POST['acc'] ?? '')); $back = $self . '?d=' . (int)($_POST['d'] ?? 0) . '&k=' . rawurlencode((string)($_POST['k'] ?? 'b'));
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $rate = $pdo->prepare("SELECT COUNT(*) FROM online_request WHERE Ip = ? AND Requested_at > NOW() - INTERVAL 1 HOUR"); $rate->execute([$ip]);
        if (!$okClient) { flash('We could not match that ID number and last name. Please check and try again.'); }
        elseif ((int)$rate->fetchColumn() >= 8) { flash('Too many requests from this device. Please try again later or ask the librarian.'); }
        else {
            $item = find_item($acc);
            $full = $item ? $pdo->prepare('SELECT * FROM ' . ($item['Kind'] === 'Book' ? 'AcList' : 'AccSM') . ' WHERE Accession = ? LIMIT 1') : null;
            if ($full) { $full->execute([$acc]); $full = $full->fetch(); }
            $pending = $pdo->prepare("SELECT COUNT(*) FROM online_request WHERE ID_Number = ? AND Status = 'Pending'"); $pending->execute([$idnum]);
            $held = $pdo->prepare("SELECT COUNT(*) FROM Reservation WHERE IDnumber = ? AND Kind = 'Hold' AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= CURDATE()"); $held->execute([$idnum]);
            $max = (int)$cfg['max_loans'];
            if (!$item || !$full) flash('That item was not found.');
            elseif (!opac_state($full, $cfg)[2]) flash('Sorry, that copy is not available for a hold right now.');
            elseif ((int)$pending->fetchColumn() + (int)$held->fetchColumn() >= $max) flash("You already have $max requests or holds. Please claim or wait for them first.");
            else {
                $pdo->prepare("INSERT INTO online_request (ID_Number, Name, Accession, Title, Requested_at, Status, Ip) VALUES (?,?,?,?,NOW(),'Pending',?)")
                    ->execute([$idnum, $client['Name'], $item['Accession'], substr((string)$item['Title'], 0, 255), $ip]);
                flash('Request sent. The library will confirm it; if approved, the item is kept for you for ' . ce_hold_days() . ' day(s). Show your ID at the library to claim it.');
            }
        }
        redirect($back);
    }
    if (($_POST['do'] ?? '') === 'mine') {
        if (!$okClient) { $err = 'We could not match that ID number and last name.'; }
        else {
            $st = $pdo->prepare('SELECT * FROM online_request WHERE ID_Number = ? ORDER BY Requested_at DESC LIMIT 10'); $st->execute([$idnum]); $mine = $st->fetchAll();
        }
    }
}

// ---------- search ----------
$q = trim((string)($_GET['q'] ?? '')); $by = $_GET['by'] ?? 'any'; $in = $_GET['in'] ?? 'books'; $lf = trim((string)($_GET['lib'] ?? ''));
if (!in_array($by, ['any', 'title', 'author', 'call', 'isbn'], true)) $by = 'any';
if (!in_array($in, ['books', 'av', 'serials'], true)) $in = 'books';
$cols = ['any' => ['Title', 'Author', 'Publisher', 'Call_Number', 'ISBN'], 'title' => ['Title'], 'author' => ['Author'], 'call' => ['Call_Number'], 'isbn' => ['ISBN']][$by];
$results = []; $arrivals = []; $detail = null; $copies = [];
$libCond = function (string $col, array &$args) use ($lf, $libs) { if ($lf !== '' && in_array($lf, $libs, true)) { $args['lib'] = $lf . '%'; return " AND $col LIKE :lib"; } return ''; };
$d = (int)($_GET['d'] ?? 0); $k = ($_GET['k'] ?? 'b') === 'a' ? 'a' : 'b';
if ($d > 0) {   // one title with all its copies
    $tbl = $k === 'a' ? 'AccSM' : 'AcList';
    $st = $pdo->prepare("SELECT * FROM $tbl WHERE ID = ?"); $st->execute([$d]); $detail = $st->fetch();
    if ($detail) {
        if ($k === 'a') $copies = [$detail];
        else { $st = $pdo->prepare('SELECT * FROM AcList WHERE LEFT(Title, 200) = LEFT(?, 200) AND Author <=> ? AND Call_Number <=> ? ORDER BY Accession'); $st->execute([$detail['Title'], $detail['Author'], $detail['Call_Number']]); $copies = $st->fetchAll(); }
    }
} elseif ($q !== '' && $in === 'books') {
    $args = []; $w = []; foreach ($cols as $i => $c) { $w[] = "$c LIKE :q$i"; $args["q$i"] = "%$q%"; }
    $sql = "SELECT MIN(ID) AS id, MIN(Title) AS t, Author, MIN(Publisher) AS pub, Call_Number, MIN(Ye_ar) AS yr, COUNT(*) AS copies,
            SUM(CASE WHEN EXISTS (SELECT 1 FROM Borrower b WHERE b.Accession_number = AcList.Accession AND b.Date_returned IS NULL) THEN 0 ELSE 1 END) AS free
            FROM AcList WHERE (" . implode(' OR ', $w) . ")" . $libCond('Location', $args) . " GROUP BY LEFT(Title, 200), Author, Call_Number ORDER BY t LIMIT 150";
    $st = $pdo->prepare($sql); $st->execute($args); $results = $st->fetchAll();
} elseif ($q !== '' && $in === 'av') {
    $args = []; $w = []; foreach (array_diff($cols, ['ISBN']) as $i => $c) { $w[] = "$c LIKE :q$i"; $args["q$i"] = "%$q%"; }
    $st = $pdo->prepare("SELECT ID AS id, Title AS t, Author, Publisher AS pub, Call_Number, Ye_ar AS yr, Type FROM AccSM WHERE (" . implode(' OR ', $w) . ")" . $libCond('Location', $args) . " ORDER BY Title LIMIT 150");
    $st->execute($args); $results = $st->fetchAll();
} elseif ($q !== '' && $in === 'serials') {
    $args = ['q' => "%$q%", 'q2' => "%$q%", 'q3' => "%$q%"];
    $st = $pdo->prepare("SELECT * FROM Serial_TitleTemp WHERE (Serial_Title LIKE :q OR ISSN LIKE :q2 OR Subject LIKE :q3)" . $libCond('Library', $args) . " ORDER BY Serial_Title LIMIT 150");
    $st->execute($args); $results = $st->fetchAll();
} else {   // nothing searched yet: show the newest books
    $arrivals = $pdo->query("SELECT MAX(ID) AS id, MIN(Title) AS t, Author, Call_Number, MIN(Ye_ar) AS yr FROM AcList GROUP BY LEFT(Title, 200), Author, Call_Number ORDER BY id DESC LIMIT 12")->fetchAll();
}
$url = fn(array $p) => $self . '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Online catalog (OPAC) · La Consolacion College Tanauan</title>
<link rel="icon" type="image/png" href="assets/img/seal.png">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
<style>
 .opac{max-width:1000px;margin:0 auto;padding:18px 16px 40px}
 .opac-head{display:flex;align-items:center;gap:14px;margin-bottom:14px}.opac-head img{width:54px;height:54px}.opac-head h1{margin:0;font-size:1.5rem}
 .opac-head small{display:block;color:#5b6477}.opac-search{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.opac-search input[type=search]{flex:1 1 260px}
 .st{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.82rem;font-weight:700}.st.ok{background:#e6f6ec;color:#0f6a31}.st.loan{background:#fdf1cc;color:#7a5a00}.st.out{background:#fbe3e1;color:#9d2820}.st.ref{background:#dcecf7;color:#14507a}
 .opac .msg{padding:10px 14px;border-radius:10px;background:#e6f6ec;color:#0f6a31;margin:10px 0}.opac .errbox{background:#fbe3e1;color:#9d2820}
 .opac-foot{margin-top:26px;color:#5b6477;font-size:.9rem}.opac a.back{display:inline-block;margin:6px 0 12px}
</style></head><body>
<div class="opac">
 <div class="opac-head"><img src="assets/img/seal.png" alt="La Consolacion College Tanauan seal"><div><h1>Online catalog</h1><small>St. Augustine Learning Resource Center · La Consolacion College Tanauan</small></div></div>
 <?php if ($msg): ?><div class="msg" role="status"><?= e($msg) ?></div><?php endif; ?>
 <?php if ($err): ?><div class="msg errbox" role="alert"><?= e($err) ?></div><?php endif; ?>
 <form class="opac-search" method="get" action="<?= e($self) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by title, author, subject word, call number or ISBN…" aria-label="Search the catalog" autofocus>
  <select name="by" aria-label="Search in field"><?php foreach (['any' => 'Anywhere', 'title' => 'Title', 'author' => 'Author', 'call' => 'Call number', 'isbn' => 'ISBN'] as $v => $l): ?><option value="<?= e($v) ?>" <?= $by === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="in" aria-label="Material"><?php foreach (['books' => 'Books', 'av' => 'Audio-visual', 'serials' => 'Serials'] as $v => $l): ?><option value="<?= e($v) ?>" <?= $in === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="lib" aria-label="Library"><option value="">All libraries</option><?php foreach ($libs as $l): ?><option <?= $l === $lf ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <button class="btn primary">Search</button></form>

<?php if ($d > 0): ?>
  <a class="back" href="javascript:history.back()">‹ Back to results</a>
  <?php if (!$detail): ?><p class="muted">That record was not found.</p><?php else: ?>
  <section class="card"><h2 style="margin-top:0"><?= e($detail['Title']) ?></h2>
   <dl class="kv" style="display:grid;grid-template-columns:140px 1fr;gap:4px 12px;margin:0">
    <?php foreach (['Author' => 'Author', 'Publisher' => 'Publisher', 'Ye_ar' => 'Year', 'Call_Number' => 'Call number', 'ISBN' => 'ISBN', 'Type' => 'Type'] as $c => $l): if (!empty($detail[$c])): ?><dt class="muted"><?= e($l) ?></dt><dd style="margin:0"><?= e($detail[$c]) ?></dd><?php endif; endforeach; ?>
   </dl></section>
  <section class="card"><h2 style="margin-top:0">Copies (<?= count($copies) ?>)</h2><div class="table-wrap"><table class="data"><thead><tr><th>Accession</th><th>Location</th><th>Status</th></tr></thead><tbody>
   <?php $avail = []; foreach ($copies as $c): [$label, $cls, $can] = opac_state($c, $cfg); if ($can) $avail[] = $c['Accession']; ?>
    <tr><td><?= e($c['Accession']) ?></td><td><?= e($c['Location']) ?></td><td><span class="st <?= e($cls) ?>"><?= e($label) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
  <?php if ($haveReq && $avail): ?>
  <section class="card"><h2 style="margin-top:0">Ask the library to keep a copy for you</h2>
   <p class="muted" style="margin-top:0">Enter your ID number and last name. The library will confirm your request, then keep the book for <?= (int)ce_hold_days() ?> day(s) so you can claim it.</p>
   <form method="post" class="form" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="request"><input type="hidden" name="d" value="<?= (int)$d ?>"><input type="hidden" name="k" value="<?= e($k) ?>">
    <label><span>Copy</span><select name="acc"><?php foreach ($avail as $a): ?><option><?= e($a) ?></option><?php endforeach; ?></select></label>
    <label><span>ID number</span><input name="idnum" required maxlength="30"></label>
    <label><span>Last name</span><input name="lastname" required maxlength="40"></label>
    <div class="actions"><button class="btn primary">Send request</button></div></form></section>
  <?php endif; endif; ?>

<?php elseif ($q !== ''): ?>
  <p class="muted"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for "<?= e($q) ?>"<?= count($results) >= 150 ? ' (showing the first 150, please narrow your search)' : '' ?></p>
  <?php if ($in === 'serials'): ?>
   <div class="table-wrap"><table class="data"><thead><tr><th>Title</th><th>Frequency</th><th>ISSN</th><th>Library</th></tr></thead><tbody>
   <?php foreach ($results as $r): ?><tr><td><?= e($r['Serial_Title']) ?></td><td><?= e($r['Frequency']) ?></td><td><?= e($r['ISSN']) ?></td><td><?= e($r['Library']) ?></td></tr><?php endforeach; if (!$results): ?><tr><td colspan="4" class="muted">Nothing found.</td></tr><?php endif; ?></tbody></table></div>
  <?php else: ?>
   <div class="table-wrap"><table class="data"><thead><tr><th>Title</th><th>Author</th><th>Call number</th><th>Year</th><th>Copies</th></tr></thead><tbody>
   <?php foreach ($results as $r): ?><tr><td><a href="<?= e($url(['d' => $r['id'], 'k' => $in === 'av' ? 'a' : 'b'])) ?>"><?= e(shorten($r['t'], 110)) ?></a></td><td><?= e($r['Author']) ?></td><td><?= e($r['Call_Number']) ?></td><td><?= e($r['yr']) ?></td>
    <td><?php if (isset($r['copies'])): ?><?= (int)$r['copies'] ?> <span class="muted">(<?= (int)$r['free'] ?> in)</span><?php else: ?><?= e($r['Type'] ?? '') ?><?php endif; ?></td></tr>
   <?php endforeach; if (!$results): ?><tr><td colspan="5" class="muted">Nothing found. Try fewer or different words.</td></tr><?php endif; ?></tbody></table></div>
  <?php endif; ?>

<?php else: ?>
  <section class="card"><h2 style="margin-top:0">New arrivals</h2><ul style="margin:0;padding-left:18px;line-height:1.8">
   <?php foreach ($arrivals as $r): ?><li><a href="<?= e($url(['d' => $r['id'], 'k' => 'b'])) ?>"><?= e(shorten($r['t'], 90)) ?></a><?= $r['Author'] ? ' <span class="muted">· ' . e($r['Author']) . '</span>' : '' ?></li><?php endforeach; if (!$arrivals): ?><li class="muted">No records yet.</li><?php endif; ?></ul></section>
  <?php if ($haveReq): ?>
  <section class="card"><h2 style="margin-top:0">My requests</h2>
   <form method="post" class="form" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="mine">
    <label><span>ID number</span><input name="idnum" required maxlength="30"></label><label><span>Last name</span><input name="lastname" required maxlength="40"></label>
    <div class="actions"><button class="btn">Check my requests</button></div></form>
   <?php if (isset($mine)): ?><div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Item</th><th>Asked on</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($mine as $r): ?><tr><td><?= e(shorten($r['Title'], 80)) ?> <small class="muted">(<?= e($r['Accession']) ?>)</small></td><td><?= e(fdate($r['Requested_at'])) ?></td><td><?= e($r['Status'] === 'Pending' ? 'Waiting for the library' : ($r['Status'] === 'Approved' ? 'Approved. Please claim it at the library.' : 'Not approved' . ($r['Note'] ? ': ' . $r['Note'] : ''))) ?></td></tr>
    <?php endforeach; if (!$mine): ?><tr><td colspan="3" class="muted">No requests yet.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?></section>
  <?php endif; ?>
<?php endif; ?>
 <p class="opac-foot">© La Consolacion College Tanauan · St. Augustine Learning Resource Center<?= !empty($_SESSION['auth']) ? ' · <a href="index.php">Back to the library system</a>' : ' · <a href="login.php">Library staff login</a>' ?></p>
</div><script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?>"></script></body></html>
