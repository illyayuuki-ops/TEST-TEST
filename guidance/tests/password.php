<?php
// Pure password tests: no database connection, fixture changes or web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/includes/guidance-password.php';
$checks = [];
function pc(string $name, bool $ok): void {
    global $checks;
    $checks[] = ['name'=>$name, 'passed'=>$ok];
    if (!$ok) throw new RuntimeException($name);
}
try {
    foreach (['Aa1'.str_repeat('x',9), 'Aa1'.str_repeat('x',125), ' Aa1'.str_repeat('é',123).' ', 'Aa1'.str_repeat('😀',125)] as $i=>$value) {
        pc('Accepted boundary / Unicode value '.$i, gp_error($value) === null);
        pc('Input preserved exactly '.$i, gp_value(['p'=>$value], 'p') === $value);
    }
    foreach (['Aa1'.str_repeat('x',8), 'Aa1'.str_repeat('x',126), str_repeat('a',12), 'aaaaaaaaaaA!', 'AAAAAAAAAAA1', "Aa1xxxxxxxx\0"] as $i=>$value) pc('Rejected invalid requirement '.$i, gp_error($value) !== null);
    foreach ([[], 123, "\xff", str_repeat('x',4097)] as $i=>$value) {
        $rejected = false;
        try { gp_value(['p'=>$value], 'p'); } catch (RuntimeException $e) { $rejected = $e->getCode() === 400; }
        pc('Malformed input rejected '.$i, $rejected);
    }
    pc('Missing value becomes empty, not an invented password', gp_value([], 'p') === '');
    $a = 'Aa1'.str_repeat('z',124).'!';
    $hash = gp_hash($a);
    pc('Native verifier accepts generated hash', password_verify($a, $hash));
    pc('Hash is Argon2id', password_get_info($hash)['algoName'] === 'argon2id');
    pc('Character 128 matters', !password_verify(substr($a,0,-1).'?', $hash));
    pc('72-byte prefix is not sufficient', !password_verify(substr($a,0,72), $hash));
    pc('Existing bcrypt hashes still verify natively', password_verify('Existing2026!', password_hash('Existing2026!', PASSWORD_BCRYPT)));
} finally {
    $out = ['passed'=>count(array_filter($checks,fn($c)=>$c['passed'])), 'total'=>count($checks), 'checks'=>$checks];
    if (!is_dir(__DIR__.'/output')) mkdir(__DIR__.'/output', 0700, true);
    file_put_contents(__DIR__.'/output/password-unit.json', json_encode($out, JSON_PRETTY_PRINT));
    echo json_encode(['passed'=>$out['passed'],'total'=>$out['total']])."\n";
}
