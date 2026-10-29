<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2).'/config/app.php';
if (getenv('LCCT_SYNTHETIC_TEST') !== '1' || !str_ends_with(DB_NAME, '_test') || !in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) {
    throw new RuntimeException('Local synthetic _test database and explicit flag required.');
}
if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
    throw new RuntimeException('Refusing: fixture database must be completely empty.');
}
// Schema only: never execute the upstream SQL inserts, credentials, USE, DELETE or cleanup scripts.
$source = file_get_contents(dirname(__DIR__, 2).'/database/ticketdesk_hosting.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+\w+\s*\(.*?\) ENGINE=InnoDB[^;]*;/s', $source, $matches);
if (count($matches[0]) !== 9) {
    throw new RuntimeException('Unexpected source DDL inventory; review before importing.');
}foreach ($matches[0] as $sql) {
    $pdo->exec($sql);
}
$pdo->exec('ALTER TABLE users ADD COLUMN avatar_path VARCHAR(80) NULL');
foreach (OFFICE_PROFILES as $code => $o) {
    gq('INSERT INTO offices(office_code,office_name,description,brand_color,brand_icon) VALUES(?,?,?,?,?)', [$code,$o['name'],$o['description'],$o['color'],$o['icon']]);
    foreach ($o['categories'] as $name) {
        gq('INSERT INTO categories(office_code,name) VALUES(?,?)', [$code,$name]);
    }gq("INSERT INTO categories(office_code,name) VALUES(?,'Other')", [$code]);
}
require dirname(__DIR__).'/migrate.php';
$users = [['guide_head','Demo Guidance Head','admin','guide','head'],['guide_counselor','Demo Counselor','admin','guide','counselor'],['guide_other','Demo Other Counselor','admin','guide','counselor'],['guide_admin','Demo Guidance Administrator','admin','guide','administrator'],['student_one','Demo Student One','student',null,null],['student_two','Demo Student Two','student',null,null],['sao_demo','Demo Student Affairs Admin','admin','sao',null]];
foreach ($users as [$name,$full,$role,$office,$gd]) {
    gq("INSERT INTO users(username,email,full_name,school_id,password,role,office_code,status,verification) VALUES(?,?,?,?,?,?,?,'active','verified')", [$name,$name.'@example.invalid',$full,$name,password_hash('GuidanceDemo2026!', PASSWORD_DEFAULT),$role,$office]);
    $uid = (int)$pdo->lastInsertId();
    if ($gd) {
        gq('INSERT INTO gd_staff(user_id,guidance_role) VALUES(?,?)', [$uid,$gd]);
    }
}
echo "Synthetic shared accounts created. Password: GuidanceDemo2026!\nStudents: student_one / student_two; Guidance: guide_head / guide_counselor / guide_admin; Other office: sao_demo.\n";
