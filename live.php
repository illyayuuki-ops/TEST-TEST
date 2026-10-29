<?php
/** Tiny "has anything changed?" endpoint for the auto-refresh script (see page_end() in includes/layout.php).
    Returns {"sig":"..."}; the page reloads itself when the signature differs from the one it loaded with. */
require __DIR__ . '/config/app.php';
require __DIR__ . '/includes/layout.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$u = $_SESSION['user'] ?? null;
if (!$u) { http_response_code(401); echo '{"sig":"","auth":false}'; exit; }
session_write_close();
echo json_encode(['sig' => live_signature($pdo, $u, isset($_GET['t']) ? (int)$_GET['t'] : 0)]);
