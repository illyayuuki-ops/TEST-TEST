<?php
/** Email helpers: send through Brevo's HTTPS API (free hosts block SMTP / mail()). */

/** The Brevo API key. Accepts the plain key (xkeysib-...) or the encoded form Brevo's dashboard sometimes shows (base64 of {"api_key":"xkeysib-..."}). */
function brevo_key(): string {
    $k = trim(BREVO_API_KEY);
    if ($k !== '' && strpos($k, 'xkeysib-') !== 0) {
        $j = json_decode((string)base64_decode($k, true), true);
        if (is_array($j) && !empty($j['api_key'])) $k = trim((string)$j['api_key']);
    }
    return $k;
}

/** Send one email. Returns true on success, false otherwise (never throws). */
function send_mail(string $to, string $toName, string $subject, string $html): bool {
    if (getenv('LCCT_SYNTHETIC_TEST')==='1') return false;
    if (brevo_key() === '' || MAIL_FROM === '' || !function_exists('curl_init')) return false;
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . brevo_key()],
        CURLOPT_POSTFIELDS => json_encode([
            'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM],
            'to'          => [['email' => $to, 'name' => $toName]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ]),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { error_log('Brevo send failed (' . $code . '): ' . (string)$res); return false; }
    return true;
}

/** Create a fresh email-verification token for a user, store its hash, and email the link. */
function send_verification_email(PDO $pdo, int $userId, string $email, string $fullName, string $office = ''): bool {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET verify_token=?, verify_expires=DATE_ADD(NOW(), INTERVAL ' . (int)VERIFY_HOURS . ' HOUR) WHERE id=?')
        ->execute([hash('sha256', $token), $userId]);
    $link = rtrim(APP_URL, '/') . APP_BASE . '/auth/verify.php?token=' . $token . (valid_code($office) ? '&office=' . rawurlencode($office) : '');
    $name = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
          . '<h2>Confirm your LCCT Ticketing account</h2>'
          . "<p>Hi $name,</p><p>Click the button below to confirm your school email and activate your account. The link works for " . (int)VERIFY_HOURS . ' hours.</p>'
          . '<p><a href="' . $link . '" style="background:#1B3355;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;display:inline-block">Confirm my email</a></p>'
          . '<p style="font-size:12px;color:#666">If the button does not work, copy this link into your browser:<br>' . $link . '</p>'
          . "<p style=\"font-size:12px;color:#666\">If you did not create this account, ignore this email.</p></div>";
    return send_mail($email, $fullName, 'Confirm your LCCT Ticketing account', $html);
}

/** Create a one-hour password-reset token for a user, store its hash, and email the link. */
function send_reset_email(PDO $pdo, int $userId, string $email, string $fullName, string $office = ''): bool {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET reset_token=?, reset_expires=DATE_ADD(NOW(), INTERVAL ' . (int)RESET_MINUTES . ' MINUTE) WHERE id=?')
        ->execute([hash('sha256', $token), $userId]);
    $link = rtrim(APP_URL, '/') . APP_BASE . '/auth/reset_password.php?token=' . $token . (valid_code($office) ? '&office=' . rawurlencode($office) : '');
    $name = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
          . '<h2>Reset your LCCT Ticketing password</h2>'
          . "<p>Hi $name,</p><p>We received a request to reset your password. Click the button below to choose a new one. The link works for " . (int)RESET_MINUTES . ' minutes and can be used once.</p>'
          . '<p><a href="' . $link . '" style="background:#1B3355;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;display:inline-block">Choose a new password</a></p>'
          . '<p style="font-size:12px;color:#666">If the button does not work, copy this link into your browser:<br>' . $link . '</p>'
          . '<p style="font-size:12px;color:#666">If you did not ask for this, ignore this email. Your password stays the same.</p></div>';
    return send_mail($email, $fullName, 'Reset your LCCT Ticketing password', $html);
}

/** True if the address belongs to the school email domain. */
function is_school_email(string $email): bool {
    $at = strrpos($email, '@');
    return $at !== false && strtolower(substr($email, $at + 1)) === strtolower(SCHOOL_EMAIL_DOMAIN);
}

/** Email the person who filed a ticket (silently skipped if their address can't receive mail). */
function notify_requester(PDO $pdo, int $ticketId, string $subject, string $messageHtml): bool {
    if (g_ready()&&gone("SELECT id FROM tickets WHERE id=? AND office_code='guide'",[$ticketId])) {g_notice($ticketId);return false;}
    $s = $pdo->prepare('SELECT t.code, t.subject, u.id AS uid, u.email, u.full_name, o.office_name FROM tickets t JOIN users u ON u.id=t.user_id JOIN offices o ON o.office_code=t.office_code WHERE t.id=?');
    $s->execute([$ticketId]); $t = $s->fetch();
    if ($t) notif_add($pdo, (int)$t['uid'], $ticketId, $subject, $messageHtml);      // in-app bell, even when the email can't be sent
    if (!$t || $t['email'] === '' || str_ends_with(strtolower($t['email']), '.local')) return false;
    $link = rtrim(APP_URL, '/') . APP_BASE . '/ticket.php?id=' . $ticketId;
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
          . '<h2 style="margin-bottom:4px">' . $h($subject) . '</h2>'
          . '<p style="color:#666;margin-top:0">' . $h($t['code']) . ' · ' . $h($t['office_name']) . '</p>'
          . '<p>Hi ' . $h($t['full_name']) . ',</p>' . $messageHtml
          . '<p><b>' . $h($t['subject']) . '</b></p>'
          . '<p><a href="' . $link . '" style="background:#1B3355;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;display:inline-block">View my ticket</a></p>'
          . '<p style="font-size:12px;color:#666">You are getting this because you filed this ticket in LCCT Ticketing.</p></div>';
    return send_mail($t['email'], $t['full_name'], $subject . ' · ' . $t['code'], $html);
}

function notify_status_change(PDO $pdo, int $ticketId, string $status): void {
    $msg = match ($status) {
        'Waiting for User' => '<p>The office needs a reply from you before it can continue. Please open your ticket and answer.</p>',
        'Resolved'  => '<p>The office marked your ticket as <b>resolved</b>. If it is not fixed, reply on the ticket and it will be reopened.</p>',
        'Closed'    => '<p>Your ticket is now <b>closed</b>. File a new ticket if you need more help.</p>',
        'Cancelled' => '<p>Your ticket was <b>cancelled</b>.</p>',
        default     => '<p>The status of your ticket is now <b>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</b>.</p>',
    };
    notify_requester($pdo, $ticketId, 'Ticket update: ' . $status, $msg);
}

/** Email one staff member about a ticket (silently skipped if their address can't receive mail). $ticketId null = link to the queue. */
function send_staff_mail(string $email, string $name, string $subject, string $messageHtml, ?int $ticketId = null): bool {
    if ($email === '' || str_ends_with(strtolower($email), '.local')) return false;
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $link = rtrim(APP_URL, '/') . APP_BASE . ($ticketId ? '/ticket.php?id=' . $ticketId : '/admin/dashboard.php');
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
          . '<h2 style="margin-bottom:4px">' . $h($subject) . '</h2>'
          . '<p>Hi ' . $h($name) . ',</p>' . $messageHtml
          . '<p><a href="' . $link . '" style="background:#1B3355;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;display:inline-block">' . ($ticketId ? 'Open ticket' : 'Open the queue') . '</a></p>'
          . '<p style="font-size:12px;color:#666">You are getting this because you are an office admin in LCCT Ticketing.</p></div>';
    return send_mail($email, $name, $subject, $html);
}

/** Email every active admin of one office. $exceptId skips the admin who just did the action. */
function notify_office_admins(PDO $pdo, string $officeCode, string $subject, string $messageHtml, ?int $ticketId = null, ?int $exceptId = null): void {
    if ($officeCode==='guide') {if($ticketId&&g_ready())g_notice($ticketId);return;}
    try {
        $s = $pdo->prepare("SELECT id, email, full_name FROM users WHERE role='admin' AND status='active' AND office_code=?");
        $s->execute([$officeCode]);
        foreach ($s->fetchAll() as $a) {
            if ($exceptId !== null && (int)$a['id'] === $exceptId) continue;
            notif_add($pdo, (int)$a['id'], $ticketId, $subject, $messageHtml);      // in-app bell
            send_staff_mail($a['email'], $a['full_name'], $subject, $messageHtml, $ticketId);
        }
    } catch (Throwable $e) { error_log('Admin notification failed: ' . $e->getMessage()); }
}

/** Email one admin (for example the person a ticket was just assigned to). */
function notify_admin_user(PDO $pdo, int $adminId, string $subject, string $messageHtml, ?int $ticketId = null): void {
    if ($ticketId&&g_ready()&&gone("SELECT id FROM tickets WHERE id=? AND office_code='guide'",[$ticketId])) {g_notice($ticketId,$adminId);return;}
    try {
        $s = $pdo->prepare("SELECT email, full_name FROM users WHERE id=? AND role='admin' AND status='active'");
        $s->execute([$adminId]); $a = $s->fetch();
        if ($a) notif_add($pdo, $adminId, $ticketId, $subject, $messageHtml);      // in-app bell
        if ($a) send_staff_mail($a['email'], $a['full_name'], $subject, $messageHtml, $ticketId);
    } catch (Throwable $e) { error_log('Admin notification failed: ' . $e->getMessage()); }
}
