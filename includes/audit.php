<?php
/** Write an audit entry (same idea as comprowg). Never throws. */
function audit(string $action, string $description = '', ?array $actor = null): void {
    global $pdo;
    $u = $actor ?? ($_SESSION['user'] ?? []);
    $office=$u['office_code'] ?? ($_SESSION['office_code'] ?? null);
    if ($office==='guide' && !str_starts_with($action,'GUIDANCE_')) $description='Guidance action recorded (case content withheld).';
    try {
        $pdo->prepare('INSERT INTO audit_logs (user_id,username,role,office_code,action,description,ip_address) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $u['id'] ?? null, $u['username'] ?? null, $u['role'] ?? null,
                $u['office_code'] ?? ($_SESSION['office_code'] ?? null),
                $action, $description, $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) { /* logging must never break the app */ }
}
