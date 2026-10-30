<?php

if (PHP_SAPI !== 'cli' || getenv('LCCT_SYNTHETIC_TEST') !== '1') {
    exit('Synthetic CLI only.');
}
require dirname(__DIR__, 2).'/config/app.php';
require dirname(__DIR__).'/domain.php';
$checks = [];
function tc(string $name, bool $passed): void
{
    global $checks;
    $checks[] = ['name' => $name,'passed' => $passed];
    if (!$passed) {
        throw new RuntimeException($name);
    }
}
function bad(string $name, callable $f, int $code = 400): void
{
    try {
        $f();
        tc($name, false);
    } catch (RuntimeException $e) {
        tc($name, $e->getCode() === $code);
    }
}
try {
    tc('Shared Guidance readiness marker', g_ready());
    tc('Expected base plus additive gd tables', (int)gq("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'gd\\_%'")->fetchColumn() === (gr_ready()?24:14));
    $h = gone("SELECT * FROM users WHERE username='guide_head'");
    $c = gone("SELECT * FROM users WHERE username='guide_counselor'");
    $a = gone("SELECT * FROM users WHERE username='guide_admin'");
    $s = gone("SELECT * FROM users WHERE username='student_one'");
    $t = ['office_code' => 'guide','user_id' => $s['id'],'assigned_to' => $c['id']];
    tc('Head case permission', g_case($h, $t));
    tc('Assigned counselor case permission', g_case($c, $t));
    tc('Administrator not clinical', !g_case($a, $t));
    tc('Owner permission', g_case($s, $t));
    tc('Unassigned counselor denied', !g_case($c, array_merge($t, ['assigned_to' => null])));
    tc('Integer validation', gi(['x' => '42'], 'x') === 42);
    bad('Integer suffix rejected', fn () => gi(['x' => '42evil'], 'x'));
    bad('Array input rejected', fn () => gt(['x' => ['bad']], 'x'));
    bad('Invalid enum rejected', fn () => ge(['x' => 'administrator'], 'x', ['student']));
    bad('Impossible date rejected', fn () => gdtime(['x' => '2026-02-30T12:00'], 'x'));
    bad('Timezone suffix rejected', fn () => gdtime(['x' => '2026-12-01T12:00Z'], 'x'));
    tc('Philippine local date conversion', gdtime(['x' => '2026-12-01T12:00'], 'x') === '2026-12-01 12:00:00');
    foreach (['=SUM(1,2)','+1','-2','@SUM(A1)',' =SUM(A1)',"\ttext", "\ntext", "\xEF\xBB\xBF=1"] as $f) {
        $csv = g_csv([[$f]]);
        $r = str_getcsv(trim($csv), ',', '"', '');
        tc('CSV formula defense '.bin2hex(substr($f, 0, 3)), str_starts_with($r[0], "'"));
    }
    tc('CSV normal Unicode preserved', str_contains(g_csv([['Pag-aaruga']]), 'Pag-aaruga'));
    $pdo->beginTransaction();
    try {
        gq("INSERT INTO gd_programs(title,description,active) VALUES('ROLLBACK-ONLY-TEST','not committed',1)");
        $id = (int)$pdo->lastInsertId();
    } finally {
        $pdo->rollBack();
    }tc('Transactions really roll back', gone('SELECT id FROM gd_programs WHERE id=?', [$id]) === null);
    $key = 'unit-rate-'.bin2hex(random_bytes(8));
    tc('Rate allows first', g_rate($key, 2));
    tc('Rate allows second', g_rate($key, 2));
    tc('Rate blocks third', !g_rate($key, 2));
    gq('DELETE FROM gd_rate WHERE bucket=?', [hash('sha256', $key)]);
    $r = g_reports();
    tc('Reports include SLA and response metrics', count(array_filter($r, fn ($v) => $v[0] === 'Hours')) === 2);
    tc('Reports contain no counseling text', !str_contains(json_encode($r), 'INTERNAL-ONLY'));
    tc('Shared priorities intentionally retained', PRIORITIES === ['Low','Normal','Urgent']);
} catch (Throwable $e) {
    $checks[] = ['name' => 'Unexpected exception: '.$e->getMessage(),'passed' => false];
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    $failed = true;
} finally {
    $out = __DIR__.'/output';
    if (!is_dir($out)) {
        mkdir($out);
    }$result = ['passed' => count(array_filter($checks, fn ($c) => $c['passed'])),'total' => count($checks),'checks' => $checks];
    file_put_contents($out.'/core.json',json_encode($result,JSON_PRETTY_PRINT));
    echo json_encode(['passed' => $result['passed'],'total' => $result['total']]).PHP_EOL;
}if (isset($failed)) {
    exit(1);
}
