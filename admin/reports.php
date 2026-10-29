<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code']; $office = get_office($oc);      // every query below is locked to this office

auto_escalate($pdo, $oc);       // overdue tickets move to Escalated on their own
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) !== false;
$to   = $valid($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
$from = $valid($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
if ($from > $to) [$from, $to] = [$to, $from];
$range = [$oc, $from . ' 00:00:00', $to . ' 23:59:59'];

/* Quick assign from the "Needs attention" list (same office only) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    $tid = (int)($_POST['id'] ?? 0); $to_ = (int)($_POST['assigned_to'] ?? 0);
    $tk = $pdo->prepare('SELECT id, code, assigned_to FROM tickets WHERE id=? AND office_code=?'); $tk->execute([$tid, $oc]); $tk = $tk->fetch();
    $mb = $pdo->prepare("SELECT full_name FROM users WHERE id=? AND role='admin' AND office_code=? AND status='active'"); $mb->execute([$to_, $oc]); $mb = $mb->fetchColumn();
    if (!$tk || !$mb) flash('Pick someone from this office.', 'err');
    else {
        $pdo->prepare('UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?')->execute([$to_, $tid]);
        audit('TICKET_ASSIGNED', "{$tk['code']} → $mb (from reports)"); flash("{$tk['code']} assigned to $mb.");
    }
    redirect('admin/reports.php?' . http_build_query(['from' => $from, 'to' => $to]));
}
$fmt = function ($m) { if ($m === null) return '—'; $m = (float)$m; return $m < 90 ? round($m) . ' min' : ($m < 2880 ? round($m / 60, 1) . ' h' : round($m / 1440, 1) . ' days'); };

/* headline numbers for tickets created in the range */
$s = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(status IN ('Open','Reopened')),0) op, COALESCE(SUM(status='In Progress'),0) ip, COALESCE(SUM(status='Escalated'),0) esc, COALESCE(SUM(status='Waiting for User'),0) wt, COALESCE(SUM(status='Cancelled'),0) cn, COALESCE(SUM(status='Resolved'),0) rs, COALESCE(SUM(status='Closed'),0) cl,
   COALESCE(SUM(priority='Urgent'),0) urgent, COALESCE(SUM(assigned_to IS NULL AND status IN " . status_in(WORKING_STATUSES) . "),0) unassigned,
   AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) avg_res
   FROM tickets WHERE office_code=? AND created_at BETWEEN ? AND ?");
$s->execute($range); $k = $s->fetch();
$fr = $pdo->prepare("SELECT AVG(m) FROM (SELECT TIMESTAMPDIFF(MINUTE, t.created_at, MIN(r.created_at)) m FROM tickets t JOIN replies r ON r.ticket_id=t.id AND r.is_internal=0 JOIN users a ON a.id=r.user_id AND a.role='admin'
   WHERE t.office_code=? AND t.created_at BETWEEN ? AND ? GROUP BY t.id) x"); $fr->execute($range); $avgFirst = $fr->fetchColumn();
$odNow = $pdo->prepare('SELECT COUNT(*) FROM tickets t WHERE t.office_code=? AND ' . sla_overdue_sql('t')); $odNow->execute([$oc]); $overdue = (int)$odNow->fetchColumn();
$backlog = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE office_code=? AND status IN ' . status_in(OPEN_STATUSES)); $backlog->execute([$oc]); $backlog = (int)$backlog->fetchColumn();
$countable = (int)$k['total'] - (int)$k['cn'];      // cancelled tickets don't count against the office
$rate = $countable > 0 ? round(($k['rs'] + $k['cl']) / $countable * 100) : null;

$cat = $pdo->prepare("SELECT COALESCE(c.id,0) cid, COALESCE(c.name,'Uncategorized') category, COUNT(*) n, COALESCE(SUM(t.status IN " . status_in(OPEN_STATUSES) . "),0) active FROM tickets t LEFT JOIN categories c ON c.id=t.category_id
   WHERE t.office_code=? AND t.created_at BETWEEN ? AND ? GROUP BY COALESCE(c.id,0), COALESCE(c.name,'Uncategorized') ORDER BY n DESC"); $cat->execute($range); $cats = $cat->fetchAll();
$staff = $pdo->prepare("SELECT a.id, a.full_name, COALESCE(SUM(t.status IN " . status_in(OPEN_STATUSES) . "),0) active, COALESCE(SUM(t.status IN ('Resolved','Closed') AND t.resolved_at BETWEEN ? AND ?),0) done,
   AVG(CASE WHEN t.resolved_at BETWEEN ? AND ? THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) avg_res
   FROM users a LEFT JOIN tickets t ON t.assigned_to=a.id AND t.office_code=a.office_code WHERE a.role='admin' AND a.office_code=? GROUP BY a.id ORDER BY active DESC, a.full_name");
$staff->execute([$range[1], $range[2], $range[1], $range[2], $oc]); $staff = $staff->fetchAll();
$dly = $pdo->prepare('SELECT DATE(created_at) d, COUNT(*) n FROM tickets WHERE office_code=? AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)'); $dly->execute($range);
$byDay = array_column($dly->fetchAll(), 'n', 'd');
$days = []; for ($d = strtotime($from), $i = 0; $d <= strtotime($to) && $i < 92; $d = strtotime('+1 day', $d), $i++) $days[date('Y-m-d', $d)] = (int)($byDay[date('Y-m-d', $d)] ?? 0);
$max = max(1, max($days ?: [1]));

$team = $pdo->prepare("SELECT id, full_name FROM users WHERE role='admin' AND office_code=? AND status='active' ORDER BY full_name"); $team->execute([$oc]); $team = $team->fetchAll();
$na = $pdo->prepare("SELECT t.id, t.code, t.subject, t.priority, t.status, t.assigned_to, t.created_at, t.updated_at, asg.full_name assignee, " . sla_overdue_sql('t') . " overdue,
    (t.assigned_to IS NULL) unassigned, (t.updated_at < NOW() - INTERVAL 3 DAY) stale
    FROM tickets t LEFT JOIN users asg ON asg.id=t.assigned_to
    WHERE t.office_code=? AND t.status IN " . status_in(WORKING_STATUSES) . " AND (" . sla_overdue_sql('t') . " OR t.assigned_to IS NULL OR t.updated_at < NOW() - INTERVAL 3 DAY)
    ORDER BY overdue DESC, FIELD(t.priority,'Urgent','Normal','Low'), t.created_at LIMIT 25");
$na->execute([$oc]); $attn = $na->fetchAll();
$rec = fn(array $a = []) => url('admin/records.php') . '?' . http_build_query($a + ['status' => '']);   // status '' = every status (the history page itself opens on closed/cancelled only)
$rng = ['from' => $from, 'to' => $to];

if (($_GET['export'] ?? '') === 'csv') {
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
    audit('REPORT_EXPORT', "CSV $from to $to");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $oc . '-report_' . $from . '_to_' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Summary', $office['office_name'], "$from to $to"]);
    foreach ([['Tickets created', $k['total']], ['Open', $k['op']], ['In Progress', $k['ip']], ['Waiting for User', $k['wt']], ['Escalated', $k['esc']], ['Resolved', $k['rs']], ['Closed', $k['cl']], ['Cancelled', $k['cn']], ['Urgent', $k['urgent']], ['Resolution rate %', $rate ?? ''],
        ['Avg first response (min)', $avgFirst !== null && $avgFirst !== false ? round((float)$avgFirst) : ''], ['Avg resolution (min)', $k['avg_res'] !== null ? round((float)$k['avg_res']) : ''], ['Overdue now', $overdue], ['Active backlog now', $backlog]] as $r) fputcsv($o, $r);
    fputcsv($o, []); fputcsv($o, ['Category', 'Tickets', 'Still active']);
    foreach ($cats as $c) fputcsv($o, [$safe($c['category']), $c['n'], $c['active']]);
    fputcsv($o, []); fputcsv($o, ['Staff', 'Active assigned', 'Resolved in range', 'Avg resolution (min)']);
    foreach ($staff as $r) fputcsv($o, [$safe($r['full_name']), $r['active'], $r['done'], $r['avg_res'] !== null ? round((float)$r['avg_res']) : '']);
    exit;
}

page_start('Reports', e($office['office_name']) . ' · volume, backlog, response time and staff workload', '<a class="btn" href="?' . e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export CSV</a>');
?>
<div class="card"><form class="filters" method="get" style="margin:0">
  <label class="lbl" style="align-self:center;margin:0">From</label><input type="date" name="from" value="<?= e($from) ?>">
  <label class="lbl" style="align-self:center;margin:0">To</label><input type="date" name="to" value="<?= e($to) ?>">
  <button class="btn" type="submit"><i class="fa-solid fa-calendar-check"></i>Apply</button>
  <a class="btn" href="?from=<?= date('Y-m-d', strtotime('-6 days')) ?>&to=<?= date('Y-m-d') ?>">7 days</a>
  <a class="btn" href="?from=<?= date('Y-m-d', strtotime('-29 days')) ?>&to=<?= date('Y-m-d') ?>">30 days</a>
  <a class="btn" href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>">This month</a>
</form></div>

<div class="stats">
  <a class="stat" href="<?= e($rec($rng)) ?>" style="--c:var(--cyan);text-decoration:none;color:inherit"><div class="k">Tickets received</div><div class="v"><?= (int)$k['total'] ?></div><div class="s">in this date range · view →</div></a>
  <a class="stat" href="<?= e($rec(['status' => 'active'])) ?>" style="--c:var(--amber);text-decoration:none;color:inherit"><div class="k">Active backlog</div><div class="v"><?= $backlog ?></div><div class="s">not yet resolved · view →</div></a>
  <a class="stat" href="<?= e($rec(['assigned' => 'overdue'])) ?>" style="--c:var(--red);text-decoration:none;color:inherit"><div class="k">Overdue</div><div class="v"><?= $overdue ?></div><div class="s">past target · view →</div></a>
  <div class="stat" style="--c:var(--green)"><div class="k">Resolution rate</div><div class="v"><?= $rate === null ? '—' : $rate . '%' ?></div><div class="s">resolved + closed</div></div>
  <div class="stat" style="--c:var(--text-2)"><div class="k">Avg first response</div><div class="v" style="font-size:28px"><?= $avgFirst === null || $avgFirst === false ? '—' : $fmt($avgFirst) ?></div><div class="s">to the first public staff reply</div></div>
  <div class="stat" style="--c:var(--text-2)"><div class="k">Avg resolution</div><div class="v" style="font-size:28px"><?= $fmt($k['avg_res']) ?></div><div class="s">resolved tickets only</div></div>
</div>

<div class="card">
  <div class="card-h"><h3>Needs attention</h3><span class="mono"><?= count($attn) ?> ticket(s) · overdue, unassigned or no update for 3+ days</span></div>
  <?php if (!$attn): ?><div class="empty"><i class="fa-solid fa-circle-check"></i>Nothing needs attention right now.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Ticket</th><th>Why</th><th>Priority</th><th>Waiting</th><th>Assign to</th></tr></thead><tbody>
  <?php foreach ($attn as $t): ?><tr>
    <td><a href="<?= url('ticket.php?id=' . $t['id']) ?>"><b><?= e($t['subject']) ?></b></a><div class="sub"><?= e($t['code']) ?> · <?= e($t['status']) ?></div></td>
    <td><?= $t['overdue'] ? '<span class="badge pr-urgent">overdue</span> ' : '' ?><?= $t['unassigned'] ? '<span class="badge st-inprogress">unassigned</span> ' : '' ?><?= $t['stale'] ? '<span class="badge st-closed">no update 3d+</span>' : '' ?></td>
    <td><?= prio_badge($t['priority']) ?></td><td class="mono"><?= time_ago($t['created_at']) ?></td>
    <td><form method="post" style="display:flex;gap:6px;margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
      <select name="assigned_to"><?php foreach ($team as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$t['assigned_to'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?><?= (int)$m['id'] === $u['id'] ? ' (me)' : '' ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm"><?= $t['assignee'] ? 'Reassign' : 'Assign' ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>

<div class="card">
  <div class="card-h"><h3>Tickets received per day</h3><span class="mono"><?= (int)$k['total'] ?> in range</span></div>
  <?php if ((int)$k['total'] === 0): ?><div class="empty"><i class="fa-solid fa-chart-column"></i>No tickets in this range.</div><?php else:
    $W = 900; $H = 160; $n = count($days); $bw = $W / max(1, $n); $i = 0; ?>
    <div style="overflow-x:auto"><svg viewBox="0 0 <?= $W ?> <?= $H + 22 ?>" style="width:100%;min-width:520px;height:auto" role="img" aria-label="Tickets per day">
      <?php foreach ($days as $d => $c): $h = $c / $max * $H; $x = $i * $bw; ?>
        <rect x="<?= round($x + $bw * .15, 1) ?>" y="<?= round($H - $h, 1) ?>" width="<?= round($bw * .7, 1) ?>" height="<?= round(max($h, $c ? 2 : 0), 1) ?>" rx="2" fill="#5E94CF" opacity=".85"><title><?= e($d) ?>: <?= $c ?></title></rect>
        <?php if ($n <= 14 || $i % max(1, (int)floor($n / 8)) === 0): ?><text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $H + 15 ?>" fill="#5b6b84" font-size="10" text-anchor="middle" font-family="Nunito Sans, sans-serif"><?= date('M j', strtotime($d)) ?></text><?php endif; $i++; endforeach; ?>
      <text x="0" y="10" fill="#8795ab" font-size="10" font-family="Nunito Sans, sans-serif">peak <?= $max ?></text>
    </svg></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-h"><h3>Status and priority</h3></div>
  <div class="table-wrap"><table><thead><tr><th>Open</th><th>In progress</th><th>Waiting for user</th><th>Escalated</th><th>Resolved</th><th>Closed</th><th>Cancelled</th><th>Urgent</th><th>Active &amp; unassigned</th></tr></thead><tbody>
    <tr><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Open']))) ?>"><?= (int)$k['op'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'In Progress']))) ?>"><?= (int)$k['ip'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Waiting for User']))) ?>"><?= (int)$k['wt'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Escalated']))) ?>"><?= (int)$k['esc'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Resolved']))) ?>"><?= (int)$k['rs'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Closed']))) ?>"><?= (int)$k['cl'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['status' => 'Cancelled']))) ?>"><?= (int)$k['cn'] ?></a></td><td class="mono"><a href="<?= e($rec(array_merge($rng, ['priority' => 'Urgent']))) ?>"><?= (int)$k['urgent'] ?></a></td><td class="mono"><a href="<?= e($rec(['status' => 'active', 'assigned' => 'none'])) ?>"><?= (int)$k['unassigned'] ?></a></td></tr></tbody></table></div>
  <div class="hint">Counts are for tickets created in the range. Targets: Urgent <?= SLA_HOURS['Urgent'] ?>h · Normal <?= SLA_HOURS['Normal'] ?>h · Low <?= SLA_HOURS['Low'] ?>h. A ticket still Open or In progress past its target is overdue.</div>
</div>

<div class="card">
  <div class="card-h"><h3>Staff workload</h3></div>
  <div class="table-wrap"><table><thead><tr><th>Staff</th><th>Active assigned</th><th>Resolved in range</th><th>Avg resolution</th></tr></thead><tbody>
  <?php foreach ($staff as $r): ?><tr><td><a href="<?= e($rec(['assigned' => $r['id'], 'status' => 'active'])) ?>"><b><?= e($r['full_name']) ?></b></a></td><td class="mono"><?= (int)$r['active'] ?></td><td class="mono"><?= (int)$r['done'] ?></td><td class="mono"><?= $fmt($r['avg_res']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>

<div class="card">
  <div class="card-h"><h3>By category</h3><span class="mono">spot the high-volume concerns</span></div>
  <?php foreach ($cats as $c): ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px"><div style="width:230px;font-size:13px"><?php if ($c['cid']): ?><a href="<?= e($rec(array_merge($rng, ['category' => $c['cid']]))) ?>"><?= e($c['category']) ?></a><?php else: echo e($c['category']); endif; ?></div>
      <div class="bar" style="flex:1"><i style="width:<?= $c['n'] / max(1, (int)$k['total']) * 100 ?>%;background:var(--violet)"></i></div><div class="mono" style="width:90px;text-align:right"><?= $c['n'] ?> <span class="sub">(<?= (int)$c['active'] ?> active)</span></div></div>
  <?php endforeach; if (!$cats) echo '<div class="empty">No data in this range.</div>'; ?>
</div>
<?php page_end();
