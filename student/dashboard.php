<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../includes/layout.php';
$u = require_role('student');

auto_close_waiting($pdo, ['user_id' => $u['id']]);   // tickets that waited too long for a reply close by themselves (student can reopen)
$fs = $_GET['status'] ?? ''; $fo = $_GET['office'] ?? ''; $q = trim($_GET['q'] ?? '');
$sql = 'SELECT t.*, COALESCE(c.name,\'Uncategorized\') AS category, o.office_name, o.brand_color, o.brand_icon FROM tickets t JOIN offices o ON o.office_code=t.office_code LEFT JOIN categories c ON c.id=t.category_id WHERE t.user_id=? AND t.status NOT IN ' . status_in(HISTORY_STATUSES);   // closed / cancelled tickets live in Ticket history
$p = [$u['id']];
if (in_array($fs, array_diff(STATUSES, HISTORY_STATUSES), true)) { $sql .= ' AND t.status=?'; $p[] = $fs; }
if ($fo !== '') { $sql .= ' AND t.office_code=?'; $p[] = $fo; }
if ($q !== '') { $sql .= ' AND (t.subject LIKE ? OR t.code LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$st = $pdo->prepare($sql . ' ORDER BY t.updated_at DESC'); $st->execute($p);
$tickets = $st->fetchAll();

$c = $pdo->prepare('SELECT status, COUNT(*) n FROM tickets WHERE user_id=? GROUP BY status'); $c->execute([$u['id']]);
$counts = array_fill_keys(STATUSES, 0); foreach ($c->fetchAll() as $r) $counts[$r['status']] = (int)$r['n'];
$myOffices = $pdo->prepare('SELECT DISTINCT o.office_code, o.office_name FROM tickets t JOIN offices o ON o.office_code=t.office_code WHERE t.user_id=? AND t.status NOT IN ' . status_in(HISTORY_STATUSES));
$myOffices->execute([$u['id']]); $myOffices = $myOffices->fetchAll();

$lastQ = $pdo->prepare('SELECT MAX(updated_at) FROM tickets WHERE user_id=?'); $lastQ->execute([$u['id']]); $lastTs = $lastQ->fetchColumn() ?: null;   // newest change on any of my tickets
$active = $counts['Open'] + $counts['Reopened'] + $counts['In Progress'] + $counts['Escalated'] + $counts['Waiting for User'] + $counts['Resolved'];
$recent = notif_recent($pdo, (int)$u['id'], 6);
$first = e(explode(' ', $u['full_name'])[0]);
$line = $active ? ('You have <b>' . $active . '</b> active ticket' . ($active === 1 ? '' : 's') . '.' . ($counts['Waiting for User'] ? ' <b>' . $counts['Waiting for User'] . '</b> need your reply.' : '') . ($counts['Resolved'] ? ' <b>' . $counts['Resolved'] . '</b> waiting for your confirmation.' : ''))
                 : 'No active tickets right now. Need help from an office? Create a new ticket.';

page_start('My tickets', 'Track everything you have reported');
echo dash_hero('Student portal', 'Hi ' . $first . ', welcome back', $line, $lastTs,
  '<a class="btn btn-primary" href="' . e(url('student/new_ticket.php')) . '"><i class="fa-solid fa-plus"></i>New ticket</a><a class="btn" href="' . e(url('student/history.php')) . '"><i class="fa-solid fa-clock-rotate-left"></i>Ticket history</a>');
?>
<div class="stats">
  <div class="stat" style="--c:var(--blue)"><i class="fa-solid fa-inbox ic"></i><div class="k">Open</div><div class="v"><?= $counts['Open'] + $counts['Reopened'] ?></div><div class="s">received by the office</div></div>
  <div class="stat" style="--c:var(--gold)"><i class="fa-solid fa-screwdriver-wrench ic"></i><div class="k">In progress</div><div class="v"><?= $counts['In Progress'] + $counts['Escalated'] ?></div><div class="s">being handled</div></div>
  <div class="stat" style="--c:#e08a00"><i class="fa-solid fa-reply ic"></i><div class="k">Needs your reply</div><div class="v"><?= $counts['Waiting for User'] ?></div><div class="s">the office is waiting for you</div></div>
  <div class="stat" style="--c:var(--green)"><i class="fa-solid fa-circle-check ic"></i><div class="k">To confirm</div><div class="v"><?= $counts['Resolved'] ?></div><div class="s">resolved — confirm to close</div></div>
</div>
<div class="dash">
<div>
<div class="card">
  <div class="card-h"><h3>Active tickets</h3><span class="mono"><?= count($tickets) ?> shown</span></div>
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject or ticket code…">
    <select name="status"><option value="">All statuses</option><?php foreach (array_diff(STATUSES, HISTORY_STATUSES) as $s): ?><option <?= $fs === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <select name="office"><option value="">All offices</option><?php foreach ($myOffices as $o): ?><option value="<?= e($o['office_code']) ?>" <?= $fo === $o['office_code'] ? 'selected' : '' ?>><?= e($o['office_name']) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i>Filter</button>
    <?php if ($fs || $fo || $q): ?><a class="btn" href="dashboard.php">Clear</a><?php endif; ?>
  </form>
  <?php if (!$tickets): ?>
    <div class="empty"><i class="fa-solid fa-ticket"></i>No active tickets. Create one to get started, or check your Ticket history for finished ones.</div>
  <?php else: ?>
  <div class="tk-grid">
    <?php foreach ($tickets as $t): $wait = $t['status'] === 'Waiting for User'; $done = $t['status'] === 'Resolved'; ?>
      <div class="tk-card<?= $wait ? ' needs' : '' ?><?= $done ? ' resolved' : '' ?>">
        <div class="tk-top"><?= office_chip($t) ?><?= prio_badge($t['priority']) ?></div>
        <a class="tk-title" href="<?= url('ticket.php?id=' . $t['id']) ?>"><?= e($t['subject']) ?></a>
        <div class="tk-sub"><?= e($t['code']) ?> · <?= e($t['category']) ?></div>
        <?= ticket_tracker($t) ?>
        <div class="tk-foot">
          <span class="tk-last" title="<?= e(time_ago($t['updated_at'])) ?>"><i class="fa-regular fa-clock"></i><span>Last update<b><?= e(fmt_dt($t['updated_at'])) ?></b></span></span>
          <?php if ($wait): ?><a class="btn btn-primary btn-sm" href="<?= url('ticket.php?id=' . $t['id'] . '#replybox') ?>"><i class="fa-solid fa-reply"></i>Reply now</a>
          <?php elseif ($done): ?><a class="btn btn-primary btn-sm" href="<?= url('ticket.php?id=' . $t['id']) ?>"><i class="fa-solid fa-circle-check"></i>Review &amp; confirm</a>
          <?php else: ?><a class="btn btn-sm" href="<?= url('ticket.php?id=' . $t['id']) ?>">View details<i class="fa-solid fa-arrow-right"></i></a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</div>
<aside>
  <div class="card panel">
    <div class="card-h"><h3>Recent updates</h3><i class="fa-regular fa-bell" style="color:var(--text-3)"></i></div>
    <div class="upd-list"><?= notif_items_html($recent) ?></div>
  </div>
</aside>
</div>
<?php page_end();
