<?php
require __DIR__ . '/../config/app.php';
$u = require_role('student');
$o = get_office(trim($_POST['office_code'] ?? ''));
if ($o && $o['status'] === 'active') {
    $_SESSION['office_code'] = $o['office_code'];
    audit('OFFICE_SWITCH', 'Now reporting to ' . $o['office_code']);
    flash('Now reporting to ' . $o['office_name'] . '.');
} else {
    flash('Please choose an available office.', 'err');
}
redirect('student/profile.php');
