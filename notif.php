<?php
/** Notification actions for the bell: open one (marks it read and goes to its ticket), poll for new ones, mark all read. */
require __DIR__ . '/config/app.php';
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); header('Content-Type: application/json'); echo '{"auth":false}'; exit; }
$uid = (int)$u['id'];
notif_ready($pdo);

if (isset($_GET['go'])) {                                   // click on a notification
    $id = (int)$_GET['go']; $tid = 0;
    try {
        $s = $pdo->prepare('SELECT ticket_id FROM notifications WHERE id=? AND user_id=?'); $s->execute([$id, $uid]); $tid = (int)$s->fetchColumn();
        $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$id, $uid]);
    } catch (Throwable $e) { error_log('notif go: ' . $e->getMessage()); }
    if (gr_ready()) {
        $deep=gone('SELECT l.view_name,l.record_id FROM gd_plus_notices l JOIN notifications n ON n.id=l.notification_id WHERE n.id=? AND n.user_id=?',[$id,$uid]);
        if ($deep && in_array($deep['view_name'],['event','appointments','history','settings','requests'],true)) redirect('guidance/index.php?view='.$deep['view_name'].((int)$deep['record_id']?'&id='.(int)$deep['record_id']:''));
    }
    redirect($tid ? 'ticket.php?id=' . $tid : home_for($u['role']));
}

header('Content-Type: application/json'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'readall') {
        try { $pdo->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$uid]); } catch (Throwable $e) { error_log('notif readall: ' . $e->getMessage()); }
    }
}
session_write_close();
$items = notif_recent($pdo, $uid, 8);
echo json_encode(['count' => notif_unread($pdo, $uid), 'html' => notif_items_html($items), 'last' => $items ? (int)$items[0]['id'] : 0]);
