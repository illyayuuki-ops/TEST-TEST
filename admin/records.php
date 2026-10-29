<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code']; $office = get_office($oc);      // every query below is locked to this office

$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) !== false;
$fs = $_GET['status'] ?? 'finished';   // default view: finished (closed / cancelled) tickets; pick "All statuses" for everything $fp = $_GET['priority'] ?? ''; $fc = (int)($_GET['category'] ?? 0); $fa = $_GET['assigned'] ?? ''; $q = trim($_GET['q'] ?? '');
$from = $valid($_GET['from'] ?? '') ? $_GET['from'] : ''; $to = $valid($_GET['to'] ?? '') ? $_GET['to'] : '';
$catList = office_categories($oc, false);

$where = 't.office_code=?'; $p = [$oc];
if ($fs === 'finished') $where .= ' AND t.status IN ' . status_in(HISTORY_STATUSES);
elseif ($fs === 'active') $where .= ' AND t.status IN ' . status_in(OPEN_STATUSES);
elseif (in_array($fs, STATUSES, true)) { $where .= ' AND t.status=?'; $p[] = $fs; } else $fs = '';
if (in_array($fp, PRIORITIES, true)) { $where .= ' AND t.priority=?'; $p[] = $fp; }   else $fp = '';
if ($fc && in_array($fc, array_map('intval', array_column($catList, 'id')), true)) { $where .= ' AND t.category_id=?'; $p[] = $fc; } else $fc = 0;
if ($fa === 'none') $where .= ' AND t.assigned_to IS NULL';
elseif ($fa === 'overdue') $where .= ' AND ' . sla_overdue_sql('t');
elseif ((int)$fa > 0) { $where .= ' AND t.assigned_to=?'; $p[] = (int)$fa; } else $fa = '';
if ($from) { $where .= ' AND t.created_at>=?'; $p[] = "$from 00:00:00"; }
if ($to)   { $where .= ' AND t.created_at<=?'; $p[] = "$to 23:59:59"; }
if ($q !== '') { $where .= ' AND (t.subject LIKE ? OR t.code LIKE ? OR us.full_name LIKE ? OR us.school_id LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }

$sel = "SELECT t.*, COALESCE(c.name,'Uncategorized') AS category, us.full_name, us.school_id, us.role AS frole, asg.full_name AS assignee, " . sla_overdue_sql('t') . " AS overdue
        FROM tickets t JOIN users us ON us.id=t.user_id LEFT JOIN users asg ON asg.id=t.assigned_to LEFT JOIN categories c ON c.id=t.category_id WHERE $where ORDER BY t.created_at DESC, t.id DESC";

/* CSV export of the filtered list: subjects and status history only, never the message text */
if (($_GET['export'] ?? '') === 'csv') {
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;     // neutralise spreadsheet formulas
    $st = $pdo->prepare($sel . ' LIMIT 20000'); $st->execute($p); $all = $st->fetchAll();
    audit('RECORDS_EXPORT', count($all) . ' ticket record(s) exported to CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $oc . '-ticket-records_' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Ticket', 'Subject', 'Requester', 'ID', 'Type', 'Category', 'Priority', 'Status', 'Assigned to', 'Overdue', 'Created', 'Resolved', 'Hours to resolve']);
    foreach ($all as $r) fputcsv($o, [$r['code'], $safe($r['subject']), $safe($r['full_name']), $safe($r['school_id']), $r['frole'], $safe($r['category']), $r['priority'], $r['status'],
        $safe($r['assignee'] ?? ''), $r['overdue'] ? 'Yes' : 'No', $r['created_at'], $r['resolved_at'] ?? '', $r['resolved_at'] ? round((strtotime($r['resolved_at']) - strtotime($r['created_at'])) / 3600, 1) : '']);
    exit;
}

$per = 20; $page = max(1, (int)($_GET['page'] ?? 1));
$tot = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN users us ON us.id=t.user_id WHERE $where"); $tot->execute($p); $total = (int)$tot->fetchColumn();
$st = $pdo->prepare($sel . " LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($p); $tickets = $st->fetchAll();
$team = $pdo->prepare("SELECT id, full_name FROM users WHERE role='admin' AND office_code=? ORDER BY full_name"); $team->execute([$oc]); $team = $team->fetchAll();
$params = array_filter(['q' => $q, 'priority' => $fp, 'category' => $fc ?: '', 'assigned' => $fa, 'from' => $from, 'to' => $to]);
$hasFilter = (bool)$params || $fs !== 'finished'; $params['status'] = $fs;   // status is always kept (even empty = all) so paging and export keep the same view

/* Statistics of the office's finished tickets (all of history, not just the filtered rows) */
$sla = "CASE t.priority WHEN 'Urgent' THEN " . SLA_HOURS['Urgent'] . " WHEN 'Normal' THEN " . SLA_HOURS['Normal'] . " ELSE " . SLA_HOURS['Low'] . " END";
$hs = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(t.status='Closed'),0) cl, COALESCE(SUM(t.status='Cancelled'),0) cn,
    COALESCE(SUM(t.status='Closed' AND COALESCE(t.resolved_at,t.updated_at) >= NOW() - INTERVAL 30 DAY),0) cl30,
    AVG(CASE WHEN t.status='Closed' AND t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) avg_res,
    COALESCE(SUM(t.status='Closed' AND t.resolved_at IS NOT NULL AND TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) <= $sla),0) ontime,
    COALESCE(SUM(t.status='Closed' AND t.resolved_at IS NOT NULL),0) timed
    FROM tickets t WHERE t.office_code=? AND t.status IN " . status_in(HISTORY_STATUSES));
$hs->execute([$oc]); $h = $hs->fetch();
$ontimePct = (int)$h['timed'] > 0 ? round($h['ontime'] / $h['timed'] * 100) : null;
$fmtDur = function ($m) { if ($m === null) return '—'; $m = (float)$m; return $m < 90 ? round($m) . ' min' : ($m < 2880 ? round($m / 60, 1) . ' h' : round($m / 1440, 1) . ' days'); };

page_start('Ticket history', e($office['office_name']) . ' · ' . number_format($total) . ' record(s)' . ($fs === 'finished' ? ' · closed and cancelled tickets' : ''),
  '<a class="btn" href="?' . e(http_build_query($params + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export CSV</a>');
?>
<div class="stats">
  <div class="stat" style="--c:var(--text-2)"><div class="k">In history</div><div class="v"><?= (int)$h['total'] ?></div><div class="s">closed + cancelled</div></div>
  <a class="stat" href="?status=Closed" style="--c:var(--green);text-decoration:none;color:inherit"><div class="k">Closed</div><div class="v"><?= (int)$h['cl'] ?></div><div class="s"><?= (int)$h['cl30'] ?> in the last 30 days · view →</div></a>
  <a class="stat" href="?status=Cancelled" style="--c:var(--red);text-decoration:none;color:inherit"><div class="k">Cancelled</div><div class="v"><?= (int)$h['cn'] ?></div><div class="s">withdrawn by the requester · view →</div></a>
  <div class="stat" style="--c:var(--cyan)"><div class="k">Avg resolution</div><div class="v" style="font-size:28px"><?= $fmtDur($h['avg_res']) ?></div><div class="s">created → resolved, closed tickets</div></div>
  <div class="stat" style="--c:var(--green)"><div class="k">Resolved on time</div><div class="v"><?= $ontimePct === null ? '—' : $ontimePct . '%' ?></div><div class="s">within the priority target</div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Ticket no., subject, requester name or ID…">
    <select name="status"><option value="finished" <?= $fs === 'finished' ? 'selected' : '' ?>>Closed &amp; cancelled (history)</option><option value="">All statuses (including active)</option><option value="active" <?= $fs === 'active' ? 'selected' : '' ?>>Active (not yet resolved)</option><?php foreach (STATUSES as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="priority"><option value="">All priorities</option><?php foreach (PRIORITIES as $s): ?><option <?= $fp === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="category"><option value="">All categories</option><?php foreach ($catList as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $fc === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <select name="assigned"><option value="">Anyone</option><option value="none" <?= $fa === 'none' ? 'selected' : '' ?>>Unassigned</option><option value="overdue" <?= $fa === 'overdue' ? 'selected' : '' ?>>Overdue</option>
      <?php foreach ($team as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $fa === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($from) ?>" title="Created from"><input type="date" name="to" value="<?= e($to) ?>" title="Created to">
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
    <?php if ($hasFilter): ?><a class="btn" href="records.php">Clear</a><?php endif; ?>
  </form>
  <?php if (!$tickets): ?><div class="empty"><i class="fa-solid fa-folder-open"></i>No tickets in history yet.</div>
  <?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Ticket</th><th>Requester</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Created</th><th>Resolved</th></tr></thead><tbody>
    <?php foreach ($tickets as $t): ?><tr>
      <td><a href="<?= url('ticket.php?id=' . $t['id']) ?>"><b><?= e($t['subject']) ?></b></a><div class="sub"><?= e($t['code']) ?></div></td>
      <td><?= e($t['full_name']) ?> <span class="badge role-<?= e($t['frole']) ?>"><?= e($t['frole']) ?></span><div class="sub"><?= e($t['school_id']) ?></div></td>
      <td class="mono"><?= e($t['category']) ?></td><td><?= prio_badge($t['priority']) ?></td>
      <td><?= badge($t['status']) ?><?= $t['overdue'] ? ' <span class="badge pr-urgent">overdue</span>' : '' ?></td>
      <td><?= $t['assignee'] ? e($t['assignee']) : '<span class="mono">—</span>' ?></td>
      <td class="mono"><?= date('M j, Y g:i A', strtotime($t['created_at'])) ?></td>
      <td class="mono"><?= $t['resolved_at'] ? date('M j, Y', strtotime($t['resolved_at'])) : '—' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php pager($total, $per, $page, $params); ?>
  <?php endif; ?>
</div>
<?php page_end();
