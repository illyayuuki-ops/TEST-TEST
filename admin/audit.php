<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('admin');
$oc = $u['office_code']; $office = get_office($oc);      // read-only; only events tagged with this office are ever shown

$fa = $_GET['action'] ?? ''; $fr = $_GET['role'] ?? ''; $q = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
$where = 'office_code=?'; $p = [$oc];
if ($fa !== '') { $where .= ' AND action=?'; $p[] = $fa; }
if (in_array($fr, ['admin', 'student'], true)) { $where .= ' AND role=?'; $p[] = $fr; } else $fr = '';
if ($q !== '') { $where .= ' AND (username LIKE ? OR description LIKE ? OR ip_address LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= ' AND created_at>=?'; $p[] = "$from 00:00:00"; } else $from = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= ' AND created_at<=?'; $p[] = "$to 23:59:59"; } else $to = '';
$params = array_filter(['q' => $q, 'action' => $fa, 'role' => $fr, 'from' => $from, 'to' => $to]);

if (($_GET['export'] ?? '') === 'csv') {
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
    $st = $pdo->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT 20000"); $st->execute($p); $all = $st->fetchAll();
    audit('AUDIT_EXPORT', count($all) . ' audit event(s) exported to CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $oc . '-audit-trail_' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['When', 'User', 'Role', 'Action', 'Details', 'IP']);
    foreach ($all as $r) fputcsv($o, [$r['created_at'], $safe($r['username'] ?? 'system'), $r['role'], $r['action'], $safe($r['description']), $r['ip_address']]);
    exit;
}

$per = 25; $page = max(1, (int)($_GET['page'] ?? 1));
$tot = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where"); $tot->execute($p); $total = (int)$tot->fetchColumn();
$st = $pdo->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($p); $logs = $st->fetchAll();
$actions = $pdo->prepare('SELECT DISTINCT action FROM audit_logs WHERE office_code=? ORDER BY action'); $actions->execute([$oc]); $actions = $actions->fetchAll(PDO::FETCH_COLUMN);
$tone = fn($a) => str_contains($a, 'FAILED') || str_contains($a, 'LOCKED') || str_contains($a, 'DELETED') || str_contains($a, 'REJECTED') ? 'pr-urgent' : (str_contains($a, 'CREATED') || $a === 'LOGIN' || str_contains($a, 'APPROVED') ? 'st-resolved' : 'st-open');

page_start('Audit trail', e($office['office_name']) . ' · ' . number_format($total) . ' event(s) · ticket changes, assignments, sign-ins and admin actions',
  '<a class="btn" href="?' . e(http_build_query($params + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export CSV</a>');
?>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search user, ticket no., details or IP…">
    <select name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option <?= $fa === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
    <select name="role"><option value="">All roles</option><?php foreach (['admin' => 'Office admin', 'student' => 'Student'] as $k => $l): ?><option value="<?= $k ?>" <?= $fr === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($from) ?>" title="From"><input type="date" name="to" value="<?= e($to) ?>" title="To">
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
    <?php if ($params): ?><a class="btn" href="audit.php">Clear</a><?php endif; ?>
  </form>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?><tr>
    <td class="mono" style="white-space:nowrap"><?= date('M j, g:i:s A', strtotime($l['created_at'])) ?></td>
    <td><b><?= e($l['username'] ?: 'system') ?></b><?= $l['role'] ? '<div class="sub">' . e($l['role']) . '</div>' : '' ?></td>
    <td><span class="badge <?= $tone($l['action']) ?>"><?= e($l['action']) ?></span></td>
    <td style="max-width:380px;word-break:break-word"><?= e($l['description']) ?></td>
    <td class="mono"><?= e($l['ip_address']) ?></td></tr>
  <?php endforeach; if (!$logs) echo '<tr><td colspan="5"><div class="empty"><i class="fa-solid fa-clipboard-list"></i>No events match.</div></td></tr>'; ?></tbody></table></div>
  <?php pager($total, $per, $page, $params); ?>
  <div class="hint">Read-only. Shows only events from <?= e($office['office_name']) ?>.</div>
</div>
<?php page_end();
