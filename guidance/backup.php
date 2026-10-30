<?php

// Encrypted Guidance export only. NOT an unattended full-system restore tool.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('sodium')) {
    throw new RuntimeException('Enable sodium.');
}
$cmd = $argv[1] ?? '';
if ($cmd === 'keygen') {
    echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)).PHP_EOL;
    exit;
}
$key = base64_decode(getenv('LCCT_GUIDANCE_BACKUP_KEY') ?: '', true);
if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
    throw new RuntimeException('Set LCCT_GUIDANCE_BACKUP_KEY to a private 32-byte base64 key. Store it separately from backups.');
}
$path = $argv[2] ?? '';
if (!$path) {
    throw new RuntimeException('Usage: php guidance/backup.php create|verify PATH.lcctgd');
}
if ($cmd === 'verify') {
    $raw = file_get_contents($path);
    if (substr($raw, 0, 8) !== "LCCTGD2\n") {
        throw new RuntimeException('Not a Guidance v2 backup.');
    }$clear = sodium_crypto_secretbox_open(substr($raw, 8 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 8, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    if ($clear === false) {
        throw new RuntimeException('Authentication failed. Wrong key or modified backup.');
    }$d = json_decode($clear, true, 512, JSON_THROW_ON_ERROR);
    if (($d['format'] ?? '') !== 'guidance-shared-v2') {
        throw new RuntimeException('Wrong schema.');
    }foreach ($d['files'] as $f) {
        if (hash('sha256', base64_decode($f['bytes'], true)) !== $f['sha256']) {
            throw new RuntimeException('File checksum failure.');
        }
    }echo 'Verified encrypted Guidance snapshot; '.count($d['tables']).' table sets and '.count($d['files']).' file objects. Verification is not a restore rehearsal.'.PHP_EOL;
    exit;
}
if ($cmd !== 'create') {
    throw new RuntimeException('Use keygen, create or verify.');
}
$parent = realpath(dirname($path));
$root = realpath(dirname(__DIR__));
if (!$parent || str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR), strtolower($root.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Create the destination directory outside the application/web root first.');
}
if (file_exists($path)) {
    throw new RuntimeException('Refusing to overwrite an existing backup.');
}
require dirname(__DIR__).'/config/app.php';
if (!g_ready()) {
    throw new RuntimeException('Install Guidance first.');
}
$lock = 'guidance-write:'.DB_NAME;
if ((int)gone('SELECT GET_LOCK(?,10) AS n', [$lock])['n'] !== 1) {
    throw new RuntimeException('Guidance is busy; retry later.');
}
try {
    $pdo->beginTransaction();
    $tables = [];
    foreach (['gd_staff','gd_case_meta','gd_preferences','gd_feedback','gd_programs','gd_events','gd_invites','gd_certificates','gd_appointments','gd_articles','gd_privacy','gd_settings'] as $t) {
        $tables[$t] = gall('SELECT * FROM `'.$t.'`');
    }
    if (gr_ready()) {
        foreach (['gd_plus_students','gd_plus_services','gd_plus_cases','gd_plus_case_log','gd_plus_events','gd_plus_attendance','gd_plus_waitlist','gd_plus_notices','gd_plus_privacy','gd_plus_privacy_log'] as $table) $tables[$table]=gall('SELECT * FROM `'.$table.'`');
        $tables['notifications_guidance']=gall('SELECT n.* FROM notifications n JOIN gd_plus_notices l ON l.notification_id=n.id');
    }
    $tables['tickets'] = gall("SELECT * FROM tickets WHERE office_code='guide'");
    $tables['replies'] = gall("SELECT r.* FROM replies r JOIN tickets t ON t.id=r.ticket_id WHERE t.office_code='guide'");
    $tables['ticket_files'] = gall("SELECT f.* FROM ticket_files f JOIN tickets t ON t.id=f.ticket_id WHERE t.office_code='guide'");
    $tables['categories'] = gall("SELECT * FROM categories WHERE office_code='guide'");
    $tables['audit_logs'] = gall("SELECT * FROM audit_logs WHERE office_code='guide'");
    $ids = [];
    foreach ($tables as $rows) {
        foreach ($rows as $r) {
            foreach (['user_id','student_id','counselor_id','assigned_to','created_by','updated_by','issued_by','reviewed_by','actor_id','checked_by','invited_by'] as $c) {
                if (!empty($r[$c])) {
                    $ids[(int)$r[$c]] = true;
                }
            }
        }
    }
    $tables['users_reference'] = $ids ? gall('SELECT id,username,full_name,email,role,office_code,status FROM users WHERE id IN ('.implode(',', array_keys($ids)).')') : [];
    $files = [];
    $base = realpath(attach_dir());
    foreach ($tables['ticket_files'] as $f) {
        $p = realpath(attach_dir().'/'.$f['stored_name']);
        if (!$p || !str_starts_with($p, $base.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('A referenced attachment is missing or outside the storage path. Backup aborted.');
        }$bytes = file_get_contents($p);
        $files[] = ['id' => $f['id'],'stored_name' => $f['stored_name'],'sha256' => hash('sha256', $bytes),'bytes' => base64_encode($bytes)];
    }
    $d = ['format' => 'guidance-shared-v2','source_commit' => '75fe13ee15b7e4ed8c93d19b5d539c80a67628b0','created_at' => date(DATE_ATOM),'restore_note' => 'Guidance subset; shared identities are references without passwords. Coordinate restore with the combined application owner.','tables' => $tables,'files' => $files];
    $pdo->commit();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $encrypted = "LCCTGD2\n".$nonce.sodium_crypto_secretbox(json_encode($d, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $nonce, $key);
    $h = fopen($path, 'xb');
    if (!$h) {
        throw new RuntimeException('Could not create backup.');
    }$written = fwrite($h, $encrypted);
    fclose($h);
    chmod($path, 0600);
    if ($written !== strlen($encrypted)) {
        unlink($path);
        throw new RuntimeException('Incomplete write; backup removed.');
    }echo 'Created encrypted Guidance subset: '.basename($path).PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }gq('SELECT RELEASE_LOCK(?)', [$lock]);
    sodium_memzero($key);
}
