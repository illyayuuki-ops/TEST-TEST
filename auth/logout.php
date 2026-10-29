<?php
require __DIR__ . '/../config/app.php';
if (isset($_SESSION['user'])) audit('LOGOUT', 'Signed out');
$_SESSION = [];
session_destroy();
redirect('auth/login.php');
