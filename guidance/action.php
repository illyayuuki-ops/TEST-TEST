<?php

define('GD_JSON', true);
require dirname(__DIR__).'/config/app.php';
require __DIR__.'/domain.php';
header('Content-Type: application/json');
try {
    $u = g_actor();
    gx($_SERVER['REQUEST_METHOD'] === 'POST', 'Use POST.', 405);
    gx(g_rate('write:'.$u['id'], 300), 'Too many requests. Try later.', 429);
    $result = g_transaction(fn () => g_action($u, gt($_POST, 'action', 1, 50), $_POST));
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $expected = $e instanceof RuntimeException && !($e instanceof PDOException) && $e->getCode() >= 400 && $e->getCode() <= 599;
    http_response_code($expected ? $e->getCode() : 500);
    if (!$expected) {
        error_log('Guidance action failed: '.get_class($e));
    }echo json_encode(['error' => $expected ? $e->getMessage() : 'Unable to save. Contact the administrator.']);
}
