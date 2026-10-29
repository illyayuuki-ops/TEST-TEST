<?php

// Schedule through the operating system, not through a public web endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/config/app.php';
require __DIR__.'/domain.php';
if (!g_ready()) {
    throw new RuntimeException('Install Guidance first.');
}
$apply = in_array('--apply', $argv, true);
$result = g_transaction(function () use ($apply) {
    $due = gall("SELECT t.id FROM tickets t WHERE t.office_code='guide' AND t.status IN ('Open','Reopened','In Progress') AND ".sla_overdue_sql('t').' ORDER BY t.id LIMIT 500');
    $sessions = (int)gq('SELECT COUNT(*) FROM gd_sessions WHERE expires_at<=NOW()')->fetchColumn();
    $rates = (int)gq('SELECT COUNT(*) FROM gd_rate WHERE expires_at<=NOW()')->fetchColumn();
    if ($apply) {
        foreach ($due as $t) {
            gq("UPDATE tickets SET status='Escalated',resolved_at=NULL WHERE id=?", [$t['id']]);
            g_audit(['id' => null,'username' => 'Guidance scheduled task','role' => null], 'SLA_ESCALATED', 'Ticket #'.$t['id']);
            g_notice((int)$t['id']);
        }gq('DELETE FROM gd_sessions WHERE expires_at<=NOW()');
        gq('DELETE FROM gd_rate WHERE expires_at<=NOW()');
        gq("INSERT INTO gd_settings(setting_key,setting_value) VALUES('last_job',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [date(DATE_ATOM)]);
    }
    return ['dry_run' => !$apply,'guidance_overdue_to_escalate' => count($due),'expired_session_rows' => $sessions,'expired_rate_rows' => $rates,'note' => 'Uses shared 24/72/120-hour priority targets. No clinical records or other-office tickets are deleted or altered.'];
});
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
