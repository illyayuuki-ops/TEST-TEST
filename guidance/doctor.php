<?php
// Read-only local diagnostics. Never imports data, grants access or runs migrations.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checks=[];
$check=function(string $name,bool $ok) use (&$checks):void { $checks[]=['check'=>$name,'passed'=>$ok]; };
$check('PHP 8.1 or newer',PHP_VERSION_ID>=80100);
foreach (['pdo_mysql','mbstring','fileinfo','sodium'] as $ext) $check('PHP extension '.$ext,extension_loaded($ext));
$check('Argon2id available',defined('PASSWORD_ARGON2ID'));
foreach (['assets/guidance.js','assets/sidebar-state.js','assets/vendor/jsQR.js','assets/vendor/qrcode.js','migrations/001_guidance.sql','migrations/002_restoration.sql','student-pages.php','migration-check.php'] as $file) $check('Guidance file '.$file,is_file(__DIR__.'/'.$file));
if (in_array(false,array_column($checks,'passed'),true)) { echo json_encode(['ok'=>false,'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;exit(1); }
require dirname(__DIR__).'/config/app.php';
require __DIR__.'/migration-check.php';
try {
    $pdo->query('SELECT 1');$check('Configured database reachable',true);
    $check('Existing Guidance office code guide',gone("SELECT office_code FROM offices WHERE office_code='guide'")!==null);
    $plan=gm_plan($pdo,__DIR__.'/migrations/001_guidance.sql');
    $check('Existing base tables structurally compatible',true);
    $check('Guidance base migration applied',g_ready());
    $check('Restoration extension enabled',gr_ready());
    $heads=g_ready()?(int)gq("SELECT COUNT(*) FROM gd_staff s JOIN users u ON u.id=s.user_id WHERE s.guidance_role='head' AND s.active=1 AND u.status='active' AND u.role='admin' AND u.office_code='guide'")->fetchColumn():0;
    $check('At least one active existing Guidance head',$heads>0);
    $result=['ok'=>!in_array(false,array_column($checks,'passed'),true),'checks'=>$checks,'note'=>'Read-only checks. Missing extension or head means setup is incomplete, not permission to import/reset data. This does not certify email, camera, HTTPS, backups or production security.'];
    echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;exit($result['ok']?0:2);
} catch (Throwable $e) {
    // Avoid printing connection strings, usernames, passwords or query data.
    echo json_encode(['ok'=>false,'checks'=>$checks,'error'=>'Schema checks failed. Ask the coordinator to review compatibility; do not import/reset the shared database.'],JSON_PRETTY_PRINT).PHP_EOL;exit(1);
}
