<?php

/** Optional fresh local review database. Never imports the original dump's records. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80100) {
    throw new RuntimeException('PHP 8.1+ required.');
}
foreach (['pdo_mysql','mbstring','fileinfo','sodium'] as $ext) {
    if (!extension_loaded($ext)) {
        throw new RuntimeException('Enable PHP extension '.$ext.' in your separate local PHP INI.');
    }
}
$root = dirname(__DIR__);
$local = $root.'/config/local.php';
if (file_exists($local)) {
    throw new RuntimeException('config/local.php already exists. It has NOT been changed. Start the configured application, or review that configuration before creating another database.');
}
$name = getenv('LCCT_LOCAL_TEST_DB') ?: 'lcct_guidance_v2_test';
if (!preg_match('/^[a-z][a-z0-9_]{1,55}_test$/', $name)) {
    throw new RuntimeException('Use a lowercase local database name ending in _test.');
}
$port = filter_var(getenv('LCCT_LOCAL_MYSQL_PORT') ?: 3306, FILTER_VALIDATE_INT);
if (!$port || $port < 1 || $port > 65535) {
    throw new RuntimeException('Invalid local MySQL port.');
}
$server = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', getenv('LCCT_LOCAL_ROOT_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$exists->execute([$name]);
if ($exists->fetchColumn()) {
    throw new RuntimeException('That database already exists. Nothing was changed. Choose a new LCCT_LOCAL_TEST_DB name; do not delete an existing database.');
}
$user = 'lcctg_'.bin2hex(random_bytes(6));
$pass = bin2hex(random_bytes(24));
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$account = $server->quote($user)."@'127.0.0.1'";
$server->exec('CREATE USER '.$account.' IDENTIFIED BY '.$server->quote($pass));
$server->exec('GRANT ALL PRIVILEGES ON `'.$name.'`.* TO '.$account);
$config = ['DB_HOST' => '127.0.0.1','DB_PORT' => $port,'DB_NAME' => $name,'DB_USER' => $user,'DB_PASS' => $pass,'SYNTHETIC_TEST' => true];
$f = fopen($local, 'xb');
if (!$f) {
    throw new RuntimeException('Cannot create private local configuration.');
}fwrite($f, "<?php\nreturn ".var_export($config, true).";\n");
fclose($f);
chmod($local, 0600);
putenv('LCCT_SYNTHETIC_TEST=1');
require __DIR__.'/tests/setup.php';
echo "Fresh local review database created. Existing databases and accounts were not changed.\nStart from the source root: php -S 127.0.0.1:8081 guidance/dev-router.php\n";
