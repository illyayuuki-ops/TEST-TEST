<?php
// Old staff sign-in address: everyone now signs in on the one login page, which detects admin or student automatically.
require __DIR__ . '/../config/app.php';
redirect('auth/login.php');
