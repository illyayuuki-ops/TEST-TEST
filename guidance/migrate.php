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
foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__.'/migrations/001_guidance.sql')) as $q) {
    if (trim($q) !== '') {
        $pdo->exec($q);
    }
}
notif_ready($pdo);
gq("INSERT IGNORE INTO gd_case_meta(ticket_id,created_by) SELECT id,NULL FROM tickets WHERE office_code='guide'");
$at = array_search('--grant-head', $argv, true);
if ($at !== false) {
    $username = $argv[$at + 1] ?? '';
    $a = gone("SELECT id FROM users WHERE username=? AND role='admin' AND office_code='guide' AND status='active'", [$username]);
    if (!$a) {
        throw new RuntimeException('Head must be an existing active shared admin with office_code guide.');
    }gq("INSERT INTO gd_staff(user_id,guidance_role,active) VALUES(?,'head',1) ON DUPLICATE KEY UPDATE guidance_role='head',active=1", [$a['id']]);
}
gq("INSERT INTO gd_settings(setting_key,setting_value) VALUES('schema','2') ON DUPLICATE KEY UPDATE setting_value='2'");
echo "Installed Guidance extension. Shared users, tickets, replies, categories and files were not replaced.\nExisting sessions require one new sign-in after first installation.\n";
