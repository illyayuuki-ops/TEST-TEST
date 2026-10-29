<?php
require __DIR__ . '/../config/app.php';
if (isset($_SESSION['user'])) audit('LOGOUT', 'Signed out');
if (g_ready()) gq('DELETE FROM gd_sessions WHERE token_hash=?',[hash('sha256',session_id())]);
$_SESSION = [];
session_destroy();
redirect('auth/login.php');
