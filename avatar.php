<?php
// Streams the signed-in student's / alumni's own profile photo. The uploads folder itself is not web-accessible.
require __DIR__ . '/config/app.php';
$u = require_role('student');
try { $s = $pdo->prepare('SELECT avatar_path FROM users WHERE id=?'); $s->execute([$u['id']]); $name = (string)$s->fetchColumn(); } catch (Throwable $e) { $name = ''; }
$path = $name !== '' ? avatar_dir() . '/' . basename($name) : '';
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');   // the page URL carries the file name, so a new photo gets a new URL
header('Content-Length: ' . filesize($path));
readfile($path);
