<?php
require __DIR__ . '/config/app.php';
redirect(isset($_SESSION['user']) ? home_for($_SESSION['user']['role']) : 'auth/login.php');
