<?php

require dirname(__DIR__).'/config/app.php';
require __DIR__.'/domain.php';
$u = g_actor();
if (($_GET['type'] ?? 'own') === 'reports') {
    if (!g_staff($u)) {
        g_denied();
    }header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="guidance-aggregate.csv"');
    echo g_csv(array_merge([['Dimension','Group','Value (small groups suppressed)']], g_reports()));
    exit;
}
$tickets = gall("SELECT t.*,c.name AS category FROM tickets t LEFT JOIN categories c ON c.id=t.category_id WHERE t.office_code='guide' AND t.user_id=?", [$u['id']]);
foreach ($tickets as &$t) {
    $t['replies'] = gall('SELECT message,created_at FROM replies WHERE ticket_id=? AND is_internal=0 ORDER BY id', [$t['id']]);
    $t['files'] = gall('SELECT f.id,f.original_name,f.size_bytes FROM ticket_files f LEFT JOIN replies r ON r.id=f.reply_id WHERE f.ticket_id=? AND (r.id IS NULL OR r.is_internal=0)', [$t['id']]);
}unset($t);
$data = ['exported_at' => date(DATE_ATOM),'notice' => 'Own Guidance records only; staff-only notes and attachment bytes excluded. No automatic erasure.','user' => array_intersect_key($u, array_flip(['id','username','full_name'])),'tickets' => $tickets,'appointments' => gall('SELECT * FROM gd_appointments WHERE user_id=?', [$u['id']]),'participation' => gall('SELECT i.state,i.attendance,i.checked_at,e.title,e.starts_at FROM gd_invites i JOIN gd_events e ON e.id=i.event_id WHERE i.user_id=?', [$u['id']]),'certificates' => gall('SELECT * FROM gd_certificates WHERE user_id=?', [$u['id']]),'privacy' => gall('SELECT * FROM gd_privacy WHERE user_id=?', [$u['id']])];
g_audit($u, 'own_export');
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="my-guidance-records.json"');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
