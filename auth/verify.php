<?php
require __DIR__ . '/../config/app.php';
// Email confirmation link: /auth/verify.php?token=...
$token = (string)($_GET['token'] ?? '');
$qo = strtolower(trim((string)($_GET['office'] ?? ''))); $qs = (valid_code($qo) && get_office($qo)) ? '&office=' . $qo : '';   // office carried over from the QR code
if (!preg_match('/^[a-f0-9]{64}$/', $token)) redirect('auth/login.php?verify=bad' . $qs);
$st = $pdo->prepare("SELECT id, username, role, office_code, email, school_id FROM users WHERE verify_token=? AND verify_expires > NOW() AND verification='email_pending' LIMIT 1");
$st->execute([hash('sha256', $token)]);
$u = $st->fetch();
if (!$u) redirect('auth/login.php?verify=bad' . $qs);
// The account itself must be valid: email = studentnumber@school-domain and the student ID must match that number. Otherwise do NOT confirm it.
if (!student_email_ok((string)$u['email']) || !student_id_ok((string)$u['school_id']) || student_num_from_email((string)$u['email']) !== trim((string)$u['school_id'])) {
    audit('EMAIL_VERIFY_REJECTED', 'Confirmation refused: email/ID not valid or not matching', ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']]);
    redirect('auth/login.php?verify=invalid' . $qs);
}
$pdo->prepare("UPDATE users SET verification='verified', verify_token=NULL, verify_expires=NULL WHERE id=?")->execute([$u['id']]);
audit('EMAIL_VERIFIED', 'School email confirmed', ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role']]);
redirect('auth/login.php?verified=1' . $qs);
