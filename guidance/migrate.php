<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__).'/config/app.php';
foreach (['users' => ['id','username','password','role','office_code','verification'],'tickets' => ['id','office_code','user_id','category_id','assigned_to'],'replies' => ['ticket_id','is_internal'],'categories' => ['id','office_code']] as $table => $columns) {
    $actual = array_column($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(), 'Field');
    if (array_diff($columns, $actual)) {
        throw new RuntimeException('Incompatible shared schema: '.$table);
    }$s = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]);
    if (strtolower((string)$s->fetchColumn()) !== 'innodb') {
        throw new RuntimeException('InnoDB required: '.$table);
    }
}
if (!gone("SELECT office_code FROM offices WHERE office_code='guide'")) {
    throw new RuntimeException('The shared Guidance office code guide must exist. Do not rename another office.');
}
require_once __DIR__.'/migration-check.php';
$plan = gm_plan($pdo, __DIR__.'/migrations/001_guidance.sql');
$headAccount = null;
$at = array_search('--grant-head', $argv, true);
if ($at !== false) {
    $username = $argv[$at + 1] ?? '';
    $headAccount = gone("SELECT id FROM users WHERE username=? AND role='admin' AND office_code='guide' AND status='active'", [$username]);
    if (!$headAccount) throw new RuntimeException('Head must be an existing active shared admin with office_code guide. No migration was applied.');
}
if (!in_array('--apply', $argv, true)) {
    echo "PLAN ONLY: Guidance base schema checked. No database changes made.\n";
    foreach ($plan as $item) echo $item['table'].($item['exists'] ? " : existing, verified\n" : " : would create\n");
    if ($headAccount) echo "Would grant Guidance head to the specified existing account.\n";
    echo "Back up the shared database and uploads first. Apply explicitly with --apply only after coordinator approval.\n";
    return;
}
if ((int)gq('SELECT GET_LOCK(?,10)', ['guidance-write:'.DB_NAME])->fetchColumn() !== 1) throw new RuntimeException('Guidance is busy. No migration applied.');
try {
    // Recheck after acquiring the lock; refuse unexpected existing schemas.
    $plan = gm_plan($pdo, __DIR__.'/migrations/001_guidance.sql');
    foreach ($plan as $item) $pdo->exec($item['sql']);
    notif_ready($pdo);
    gq("INSERT IGNORE INTO gd_case_meta(ticket_id,created_by) SELECT id,NULL FROM tickets WHERE office_code='guide'");
    if ($headAccount) gq("INSERT INTO gd_staff(user_id,guidance_role,active) VALUES(?,'head',1) ON DUPLICATE KEY UPDATE guidance_role='head',active=1", [$headAccount['id']]);
    gq("INSERT INTO gd_settings(setting_key,setting_value) VALUES('schema','2') ON DUPLICATE KEY UPDATE setting_value='2'");
    echo "Installed Guidance extension. Existing shared records preserved.\nFirst installation requires a new sign-in. MySQL DDL is not transactional; keep backups and stop on any error.\n";
} finally { gq('SELECT RELEASE_LOCK(?)', ['guidance-write:'.DB_NAME]); }
