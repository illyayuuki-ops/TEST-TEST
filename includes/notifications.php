<?php
/** In-app notifications (the bell in the top bar). The table is created on first use, so no manual SQL is needed
    (database/upgrade_notifications.sql does the same thing if you prefer to run it yourself). */

function notif_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ticket_id INT NULL,
            title VARCHAR(160) NOT NULL,
            body VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            KEY idx_user_read (user_id, read_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $ok = true;
    } catch (Throwable $e) { error_log('notifications table: ' . $e->getMessage()); $ok = false; }
    return $ok;
}

/** Add one notification for one user. $messageHtml may contain tags; they are stripped. Never throws. */
function notif_add(PDO $pdo, int $userId, ?int $ticketId, string $title, string $messageHtml = ''): void {
    if ($userId <= 0 || !notif_ready($pdo)) return;
    try {
        $body = trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($messageHtml), ENT_QUOTES, 'UTF-8')));
        $pdo->prepare('INSERT INTO notifications (user_id,ticket_id,title,body) VALUES (?,?,?,?)')
            ->execute([$userId, $ticketId ?: null, mb_strimwidth($title, 0, 160, '…'), mb_strimwidth($body, 0, 250, '…')]);
    } catch (Throwable $e) { error_log('notif_add: ' . $e->getMessage()); }
}

function notif_unread(PDO $pdo, int $userId): int {
    if (!notif_ready($pdo)) return 0;
    try { $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL'); $s->execute([$userId]); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function notif_recent(PDO $pdo, int $userId, int $limit = 8): array {
    if (!notif_ready($pdo)) return [];
    try { $s = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$limit); $s->execute([$userId]); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** The list inside the bell dropdown (also returned by notif.php?action=poll). */
function notif_items_html(array $items): string {
    if (!$items) return '<div class="bell-empty"><i class="fa-regular fa-bell-slash"></i>You are all caught up. New updates will show here.</div>';
    $h = '';
    foreach ($items as $n) {
        $when = date('M j, Y g:i A', strtotime($n['created_at']));
        $h .= '<a class="bell-item' . ($n['read_at'] ? '' : ' unread') . '" href="' . e(url('notif.php?go=' . (int)$n['id'])) . '">'
            . '<span class="bi-dot"></span><span class="bi-body"><b>' . e($n['title']) . '</b>'
            . ($n['body'] !== '' ? '<span class="bi-text">' . e($n['body']) . '</span>' : '')
            . '<span class="bi-time"><i class="fa-regular fa-clock"></i>' . e($when) . '</span></span></a>';
    }
    return $h;
}
