<?php
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/layout.php';
if (DEBUG_ERRORS) {
    ini_set('display_errors', '1'); error_reporting(E_ALL);
    set_exception_handler(function (Throwable $x) { http_response_code(500); echo '<pre style="padding:20px;font:14px monospace;white-space:pre-wrap">Ticket page error: ' . htmlspecialchars($x->getMessage() . ' @ ' . basename($x->getFile()) . ':' . $x->getLine()) . '</pre>'; });
}
$u = require_role('student', 'admin');
$isAdmin = $u['role'] === 'admin';


$st = $pdo->prepare('SELECT t.*, COALESCE(c.name,\'Uncategorized\') AS category, o.office_name, o.brand_color, o.brand_icon, o.status AS office_status, us.full_name AS filer_name, us.role AS filer_role, us.school_id, us.email AS filer_email, asg.full_name AS assignee_name
                     FROM tickets t JOIN offices o ON o.office_code=t.office_code JOIN users us ON us.id=t.user_id LEFT JOIN users asg ON asg.id=t.assigned_to LEFT JOIN categories c ON c.id=t.category_id WHERE t.id=?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$t = $st->fetch();
// access: filer sees own tickets; admin sees only tickets of their own office
if (!$t || ($isAdmin ? $t['office_code'] !== $u['office_code'] : (int)$t['user_id'] !== $u['id'])) {
    flash('Ticket not found.', 'err'); redirect(home_for($u['role']));
}
if ($t['office_code']==='guide' && !g_case($u,$t)) g_denied('Ticket not found.',404);
$id = (int)$t['id'];
try { auto_close_waiting($pdo, ['id' => (int)($_GET['id'] ?? 0)]); } catch (Throwable $x) { error_log('auto_close_waiting: ' . $x->getMessage()); }   // no reply for a week → closed (student can reopen)
$locked = $t['status'] === 'Cancelled' || ($t['status'] === 'Closed' && ($isAdmin || !can_reopen($t)));   // students may still answer a closed ticket for a while, which reopens it
$team = [];   // admins of this office who can be assigned the ticket
if ($isAdmin) { $tm = $pdo->prepare("SELECT id, full_name FROM users WHERE role='admin' AND office_code=? AND status='active' ORDER BY full_name"); $tm->execute([$t['office_code']]); $team = $tm->fetchAll(); if ($t['office_code']==='guide') $team=array_values(array_filter($team,fn($a)=>gone("SELECT user_id FROM gd_staff WHERE user_id=? AND active=1 AND guidance_role IN ('head','counselor')",[$a['id']]))); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($t['office_code']==='guide') {
        if ((int)gq('SELECT GET_LOCK(?,10)',['guidance-write:'.DB_NAME])->fetchColumn()!==1) g_denied('Guidance is busy. Try again.',503);
        register_shutdown_function(function(){try{gq('SELECT RELEASE_LOCK(?)',['guidance-write:'.DB_NAME]);}catch(Throwable $e){}});
        $fresh=gone('SELECT * FROM tickets WHERE id=?',[$id]);if(!$fresh||!g_case($u,$fresh))g_denied('Ticket not found.',404);
        $t=array_merge($t,$fresh);
        if (($_POST['action']??'')==='assign'&&!g_head($u))g_denied('Only the Guidance head may reassign private cases.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'reply') {
        $msg = trim($_POST['message'] ?? '');
        $internal = $isAdmin && !empty($_POST['internal']);      // private note between office staff; the requester never sees it
        [$attErr, $attOk] = attach_collect();
        if ($locked) flash('This ticket is ' . strtolower($t['status']) . '.', 'err');
        elseif (mb_strlen($msg) < 2) flash('Write a message first.', 'err');
        elseif ($attErr) flash($attErr, 'err');
        elseif ($internal) {
            $pdo->prepare('INSERT INTO replies (ticket_id,user_id,message,is_internal) VALUES (?,?,?,1)')->execute([$id, $u['id'], $msg]);
            attach_save($pdo, $id, (int)$pdo->lastInsertId(), $u['id'], $attOk);
            $pdo->prepare('UPDATE tickets SET updated_at=NOW() WHERE id=?')->execute([$id]);
            audit('TICKET_NOTE', $t['code'] . ' internal note');
            flash('Internal note saved. The requester cannot see it.');
        } else {
            $pdo->prepare('INSERT INTO replies (ticket_id,user_id,message) VALUES (?,?,?)')->execute([$id, $u['id'], $msg]);
            attach_save($pdo, $id, (int)$pdo->lastInsertId(), $u['id'], $attOk);
            if ($isAdmin && !$t['assigned_to']) {                // first staff reply claims an unassigned ticket
                $pdo->prepare('UPDATE tickets SET assigned_to=? WHERE id=?')->execute([$u['id'], $id]);
                audit('TICKET_ASSIGNED', "{$t['code']} → {$u['username']} (first reply)");
            }
            if ($isAdmin && in_array($t['status'], ['Open', 'Reopened'], true)) {
                change_ticket_status($pdo, $t, 'In Progress', null, false);          // the reply email below already tells the requester
            } elseif (!$isAdmin && in_array($t['status'], ['Resolved', 'Closed'], true)) {      // filer answered a resolved/closed ticket → reopen
                change_ticket_status($pdo, $t, 'Reopened', null, false);
                audit('TICKET_REOPENED', $t['code'] . ' reopened by reply');
            } elseif (!$isAdmin && $t['status'] === 'Waiting for User') {             // filer gave the answer the office was waiting for
                change_ticket_status($pdo, $t, 'In Progress', null, false);
            } else $pdo->prepare('UPDATE tickets SET updated_at=NOW() WHERE id=?')->execute([$id]);
            $pdo->prepare('UPDATE tickets SET updated_at=NOW() WHERE id=?')->execute([$id]);
            if ($isAdmin) notify_requester($pdo, $id, 'New reply on your ticket', '<p><b>' . e($u['full_name']) . '</b> from ' . e($t['office_name']) . ' replied:</p><blockquote style="border-left:3px solid #ccc;margin:0 0 12px;padding:4px 12px;color:#333">' . nl2br(e(mb_strimwidth($msg, 0, 500, '…'))) . '</blockquote>');
            if (!$isAdmin) {                                     // the requester answered: tell the assigned admin (or the whole office if nobody has the ticket yet)
                $nt = 'New reply from requester: ' . $t['code']; $nb = '<p><b>' . e($u['full_name']) . '</b> replied:</p><p>' . e(mb_strimwidth($msg, 0, 200, '…')) . '</p>';
                if ($t['assigned_to']) notify_admin_user($pdo, (int)$t['assigned_to'], $nt, $nb, $id); else notify_office_admins($pdo, $t['office_code'], $nt, $nb, $id);
            }
            audit('TICKET_REPLY', $t['code']);
            flash('Reply sent.');
        }
    } elseif ($action === 'assign' && $isAdmin) {
        $to = (int)($_POST['assigned_to'] ?? 0);
        if ($t['office_code']==='guide' && $to && !gone("SELECT u.id FROM users u JOIN gd_staff s ON s.user_id=u.id WHERE u.id=? AND u.office_code='guide' AND u.role='admin' AND u.status='active' AND s.active=1 AND s.guidance_role IN ('head','counselor')",[$to]))g_denied('Select an active Guidance counselor.',400);
        $ok = $to === 0 || in_array($to, array_map('intval', array_column($team, 'id')), true);     // only active admins of this office
        if (!$ok) flash('Pick someone from this office.', 'err');
        elseif ($to !== (int)$t['assigned_to']) {
            $pdo->prepare('UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?')->execute([$to ?: null, $id]);
            $name = $to ? (array_column($team, 'full_name', 'id')[$to] ?? "#$to") : 'unassigned';
            audit('TICKET_ASSIGNED', "{$t['code']} → $name");
            if ($to && $to !== $u['id']) notify_admin_user($pdo, $to, 'Ticket assigned to you: ' . $t['code'], '<p><b>' . e($u['full_name']) . '</b> assigned this ticket to you:</p><p><b>' . e($t['subject']) . '</b></p>', $id);
            flash($to ? "Assigned to $name." : 'Ticket is now unassigned.');
        }
    } elseif ($action === 'status' && $isAdmin) {
        $ns = $_POST['status'] ?? '';
        if (change_ticket_status($pdo, $t, $ns)) flash("Status changed to $ns. The requester was emailed.");
    } elseif ($action === 'reopen' && !$isAdmin) {
        if (can_reopen($t)) {
            change_ticket_status($pdo, $t, 'Reopened', null, false);
            notify_office_admins($pdo, $t['office_code'], 'Ticket reopened: ' . $t['code'], '<p><b>' . e($u['full_name']) . '</b> reopened this ticket.</p><p><b>' . e($t['subject']) . '</b></p>', $id);
            flash('Ticket reopened. The office will pick it up again.');
        } else flash('This ticket can no longer be reopened. Please file a new ticket.', 'err');
    } elseif ($action === 'confirm_resolved' && !$isAdmin) {
        // The student confirms the fix worked: the ticket closes right away, no waiting for the office or the auto-close timer.
        if ($t['status'] === 'Resolved') {
            change_ticket_status($pdo, $t, 'Closed', null, false);
            audit('TICKET_CONFIRMED', $t['code'] . ' resolution confirmed by requester; ticket closed automatically');
            notify_office_admins($pdo, $t['office_code'], 'Resolution confirmed: ' . $t['code'], '<p><b>' . e($u['full_name']) . '</b> confirmed the resolution, so the ticket was closed automatically.</p><p><b>' . e($t['subject']) . '</b></p>', $id);
            flash('Thanks for confirming. Your ticket is now closed and moved to your Ticket history.');
        } else flash('This ticket is not waiting for your confirmation.', 'err');
    } elseif ($action === 'close_self' && !$isAdmin) {
        if ($t['status'] === 'Waiting for User') {
            change_ticket_status($pdo, $t, 'Closed', null, false);
            notify_office_admins($pdo, $t['office_code'], 'Closed by requester: ' . $t['code'], '<p><b>' . e($u['full_name']) . '</b> has no further answer and closed this ticket.</p>', $id);
            flash('Ticket closed. You can still reopen it within ' . REOPEN_WINDOW_DAYS . ' days.');
        }
    } elseif ($action === 'cancel' && !$isAdmin) {
        if (in_array($t['status'], ['Open', 'Reopened'], true)) { change_ticket_status($pdo, $t, 'Cancelled', null, false); flash('Your ticket was cancelled.'); }
        else flash('This ticket is already being handled. Reply to the office instead.', 'err');
    }
    redirect('ticket.php?id=' . $id);
}

$rs = $pdo->prepare('SELECT r.*, us.full_name, us.role FROM replies r JOIN users us ON us.id=r.user_id WHERE r.ticket_id=?' . ($isAdmin ? '' : ' AND r.is_internal=0') . ' ORDER BY r.created_at, r.id');
$rs->execute([$id]); $replies = $rs->fetchAll();
$finished = in_array($t['status'], HISTORY_STATUSES, true);
$back = $isAdmin ? ($finished ? 'admin/records.php' : 'admin/dashboard.php') : ($finished ? 'student/history.php' : 'student/dashboard.php');
$files = attach_list($pdo, $id, $isAdmin);

// Target resolution time (from the priority's SLA) and the ticket's activity history
$due = strtotime($t['created_at']) + (SLA_HOURS[$t['priority']] ?? SLA_HOURS['Normal']) * 3600;
$esc = fn(string $c) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $c);
$kinds = $isAdmin ? "'TICKET_CREATED','TICKET_STATUS','TICKET_ESCALATED','TICKET_ASSIGNED'" : "'TICKET_CREATED','TICKET_STATUS','TICKET_ESCALATED'";
$ev = $pdo->prepare("SELECT action, description, username, role, created_at FROM audit_logs WHERE action IN ($kinds) AND (description LIKE ? OR description LIKE ?) ORDER BY created_at, id");
// ASCII-only search patterns: audit_logs.description is latin1, so a '→' here makes MySQL reject the comparison.
$ev->execute([$esc($t['code']) . ':%', $esc($t['code']) . ' %']); $events = $ev->fetchAll();
$evText = function (array $e) use ($t) {
    $rest = trim((string)preg_replace('/^' . preg_quote($t['code'], '/') . '\s*[:→]\s*/u', '', (string)$e['description']));
    return match ($e['action']) {
        'TICKET_CREATED'   => 'Ticket created',
        'TICKET_STATUS'    => 'Status: ' . $rest,
        'TICKET_ESCALATED' => 'Escalated automatically (past its target time)',
        default            => 'Assigned to ' . $rest,
    };
};

page_start($t['subject'], e($t['code']) . ' · ' . e($t['category']), '<a class="btn" href="' . url($back) . '"><i class="fa-solid fa-arrow-left"></i>Back</a>');
?>
<div class="card"><?= ticket_tracker($t, true) ?></div>
<div class="two-col">
  <div>
    <div class="thread">
      <div class="msg">
        <div class="meta"><b><?= e($t['filer_name']) ?></b><span class="badge role-<?= e($t['filer_role']) ?>"><?= e($t['filer_role']) ?></span><span><?= date('M j, Y g:i A', strtotime($t['created_at'])) ?></span></div>
        <div class="body"><?= e($t['details']) ?></div><?= attach_chips($files[0] ?? []) ?>
      </div>
      <?php foreach ($replies as $r): $staff = $r['role'] === 'admin'; ?>
        <div class="msg <?= $staff ? 'staff' : '' ?>" <?= $r['is_internal'] ? 'style="border-style:dashed;background:var(--amber-dim,#fff7e0)"' : '' ?>>
          <div class="meta"><b><?= e($r['full_name']) ?></b><?= $r['is_internal'] ? '<span class="badge st-inprogress"><i class="fa-solid fa-lock"></i> Internal note</span>' : ($staff ? '<span class="badge role-admin">' . e($t['office_name']) . ' staff</span>' : '') ?><span><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></span></div>
          <div class="body"><?= e($r['message']) ?></div><?= attach_chips($files[(int)$r['id']] ?? []) ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$isAdmin && $t['status'] === 'Resolved'): ?>
      <div class="alert ok" style="flex-wrap:wrap;gap:12px"><i class="fa-solid fa-circle-check"></i><span style="flex:1;min-width:220px"><b>The office marked this ticket as resolved.</b> Is your concern solved? Confirming closes the ticket right away. If it is not solved, send a reply below and it will be reopened.</span>
        <form method="post" onsubmit="return confirm('Confirm that this is resolved? The ticket will be closed.')" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_resolved"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-circle-check"></i>Yes, it's resolved — close ticket</button></form></div>
    <?php endif; ?>
    <?php if (!$isAdmin && $t['status'] === 'Waiting for User'): ?>
      <div class="alert warn" style="flex-wrap:wrap;gap:12px"><i class="fa-solid fa-hourglass-half"></i><span style="flex:1;min-width:220px"><b>The office is waiting for your reply.</b> If you have nothing to add, you can close this ticket. Otherwise it closes by itself on <?= date('M j', strtotime($t['updated_at']) + WAITING_AUTOCLOSE_DAYS * 86400) ?> (you can still reopen it afterwards).</span>
        <form method="post" onsubmit="return confirm('Close this ticket?')" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="close_self"><button class="btn btn-sm" type="submit"><i class="fa-solid fa-circle-xmark"></i>I have no answer — close it</button></form></div>
    <?php elseif (!$isAdmin && can_reopen($t)): ?>
      <div class="alert warn" style="flex-wrap:wrap;gap:12px"><i class="fa-solid fa-lock"></i><span style="flex:1;min-width:220px">This ticket is closed. Still not solved? You can bring it back within <?= REOPEN_WINDOW_DAYS ?> days of closing.</span>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-rotate-left"></i>Reopen ticket</button></form></div>
    <?php endif; ?>
    <?php if ($locked): ?>
      <div class="alert warn"><i class="fa-solid fa-lock"></i>This ticket is <?= strtolower($t['status']) ?>. Open a new ticket if you need more help.</div>
    <?php else: ?>
      <div class="card" id="replybox"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="reply">
        <div class="field"><label><?= $isAdmin ? 'Reply to ' . e($t['filer_name']) : 'Add a reply' ?></label><textarea name="message" style="min-height:96px" required></textarea></div>
        <?= attach_input_html() ?>
        <?php if ($isAdmin): ?><label style="display:flex;gap:8px;align-items:center;margin:0 0 12px;font-size:13px"><input type="checkbox" name="internal" value="1"> Internal note — only <?= e($t['office_name']) ?> staff can see it</label><?php endif; ?>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i><?= $isAdmin ? 'Send' : 'Send reply' ?></button>
        <?php if (!$isAdmin && in_array($t['status'], ['Resolved', 'Closed'], true)): ?><span class="hint" style="margin-left:10px">Replying will reopen this ticket.</span><?php endif; ?>
      </form></div>
    <?php endif; ?>
  </div>
  <div>
    <div class="card">
      <div class="kv" style="grid-template-columns:1fr 1fr">
        <div><div class="k">Status</div><?= badge($t['status']) ?></div>
        <div><div class="k">Priority</div><?= prio_badge($t['priority']) ?></div>
        <div style="grid-column:1/-1"><div class="k">Office</div><?= office_chip($t) ?></div>
        <div style="grid-column:1/-1"><div class="k">Assigned to</div><?= $t['assignee_name'] ? e($t['assignee_name']) : '<span class="mono">Not yet assigned</span>' ?></div>
        <div><div class="k">Created</div><span class="mono"><?= date('M j, Y', strtotime($t['created_at'])) ?></span></div>
        <div><div class="k">Updated</div><span class="mono"><?= time_ago($t['updated_at']) ?></span></div>
        <div style="grid-column:1/-1"><div class="k">Target resolution</div>
          <?php if (in_array($t['status'], ['Resolved', 'Closed'], true)): ?><span class="mono">Resolved <?= date('M j, Y g:i A', strtotime($t['resolved_at'] ?: $t['updated_at'])) ?></span>
          <?php elseif ($t['status'] === 'Cancelled'): ?><span class="mono">—</span>
          <?php elseif ($t['status'] === 'Waiting for User'): ?><span class="mono">Paused while waiting for <?= $isAdmin ? 'the requester' : 'your reply' ?></span>
          <?php else: ?><span class="mono"><?= date('M j, Y g:i A', $due) ?></span> <?= time() > $due ? '<span class="badge pr-urgent">overdue</span>' : '' ?>
          <?php endif; ?></div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h3>Activity</h3></div>
      <?php foreach ($events as $e): ?>
        <div style="font-size:13px;padding:6px 0;border-bottom:1px dashed var(--line,#e3e8ef)"><?= e($evText($e)) ?>
          <div class="mono" style="font-size:11px"><?= date('M j, Y g:i A', strtotime($e['created_at'])) ?><?= $isAdmin ? ' · ' . e($e['username'] ?: 'system') : '' ?></div></div>
      <?php endforeach; ?>
      <?php if (!$events): ?><div class="mono" style="font-size:12px">No activity yet.</div><?php endif; ?>
    </div>
    <?php if (!$isAdmin && in_array($t['status'], ['Open', 'Reopened'], true)): ?>
    <div class="card"><form method="post" onsubmit="return confirm('Cancel this ticket?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <button class="btn btn-danger" type="submit" style="width:100%"><i class="fa-solid fa-ban"></i>Cancel ticket</button><div class="hint">Only until the office starts working on it.</div></form></div>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <div class="card">
      <div class="card-h"><h3>Assign</h3></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
        <div class="field"><select name="assigned_to"><option value="0">— Unassigned —</option><?php foreach ($team as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$t['assigned_to'] === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?><?= (int)$m['id'] === $u['id'] ? ' (me)' : '' ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit" style="width:100%">Save assignment</button>
      </form>
    </div>
    <div class="card">
      <div class="card-h"><h3>Update status</h3></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status">
        <div class="field"><select name="status"><?php foreach (STATUSES as $s): ?><option <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
        <button class="btn" type="submit" style="width:100%">Save status</button>
      </form>
    </div>
    <div class="card">
      <div class="card-h"><h3>Requester</h3></div>
      <div class="kv" style="grid-template-columns:1fr">
        <div><div class="k">Name</div><?= e($t['filer_name']) ?></div>
        <div><div class="k">ID</div><span class="mono"><?= e($t['school_id']) ?></span></div>
        <div><div class="k">Email</div><span class="mono"><?= e($t['filer_email']) ?></span></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($_GET['reply'])): ?><script>document.addEventListener('DOMContentLoaded',function(){var t=document.querySelector('#replybox textarea');if(t)t.focus({preventScroll:true});});</script><?php endif; ?>
<?php page_end();
