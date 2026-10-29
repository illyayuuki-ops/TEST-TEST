<?php

define('GD_JSON', true);
require dirname(__DIR__).'/config/app.php';
header('Content-Type: application/json');
$u = g_actor();
session_write_close();
echo json_encode(['sig' => g_signature($u)]);
