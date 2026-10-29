<?php
// Streams one ticket attachment after checking the viewer may see it. The uploads folder itself is not web-accessible.
require __DIR__ . '/config/app.php';
$u = require_role('student', 'admin');
$isAdmin = $u['role'] === 'admin';
$s = $pdo->prepare('SELECT f.*, t.user_id AS owner_id, t.office_code, COALESCE(r.is_internal,0) AS internal FROM ticket_files f JOIN tickets t ON t.id=f.ticket_id LEFT JOIN replies r ON r.id=f.reply_id WHERE f.id=?');
try { $s->execute([(int)($_GET['id'] ?? 0)]); $f = $s->fetch(); } catch (Throwable $e) { $f = false; }
$allowed = $f && ($isAdmin ? $f['office_code'] === $u['office_code'] : ((int)$f['owner_id'] === $u['id'] && !$f['internal']));
$path = $allowed ? attach_dir() . '/' . basename($f['stored_name']) : '';
if (!$allowed || !is_file($path)) { http_response_code(404); exit('File not found.'); }
header('Content-Type: ' . $f['mime']);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\', "\r", "\n"], '_', $f['original_name']) . '"');
readfile($path);
