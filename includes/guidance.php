<?php

/** Guidance integration with the shared TicketDesk identity and ticket tables. */
function gq(string $sql, array $p = []): PDOStatement
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s;
}
function gone(string $sql, array $p = []): ?array
{
    return gq($sql, $p)->fetch() ?: null;
}
function gall(string $sql, array $p = []): array
{
    return gq($sql, $p)->fetchAll();
}
function g_ready(): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }try {
        gq('SELECT 1 FROM gd_staff LIMIT 1');
        return $ready = (bool)gone("SELECT setting_key FROM gd_settings WHERE setting_key='schema' AND setting_value='2'");
    } catch (PDOException $e) {
        return $ready = false;
    }
}
function g_staff(array $u): ?string
{
    if (($u['role'] ?? '') !== 'admin' || ($u['office_code'] ?? '') !== 'guide' || !g_ready()) {
        return null;
    }
    $r = gone("SELECT s.guidance_role FROM gd_staff s JOIN users u ON u.id=s.user_id WHERE s.user_id=? AND s.active=1 AND u.role='admin' AND u.office_code='guide' AND u.status='active'", [(int)$u['id']]);
    return $r['guidance_role'] ?? null;
}
function g_head(array $u): bool
{
    return g_staff($u) === 'head';
}
function g_case(array $u, array $t): bool
{
    if (($t['office_code'] ?? '') !== 'guide') {
        return false;
    }
    if (is_filer($u)) {
        return (int)$t['user_id'] === (int)$u['id'];
    }
    $role = g_staff($u);
    return $role === 'head' || ($role === 'counselor' && (int)($t['assigned_to'] ?? 0) === (int)$u['id']);
}
function g_staff_login(array $u): bool
{
    return $u['role'] === 'admin' && ($u['office_code'] === STAFF_OFFICE || g_staff($u) !== null);
}
function g_session_start(array $u): void
{
    $_SESSION['password_stamp'] = hash('sha256', $u['password']);
    $_SESSION['issued_at'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (g_ready()) {
        gq('INSERT INTO gd_sessions(token_hash,user_id,expires_at) VALUES(?,?,?)', [hash('sha256', session_id()),$u['id'],date('Y-m-d H:i:s', time() + 28800)]);
    }
}
function g_session_check(): void
{
    if (empty($_SESSION['user']) || !g_ready()) {
        return;
    }
    $id = (int)$_SESSION['user']['id'];
    $a = gone('SELECT * FROM users WHERE id=?', [$id]);
    $session = gone('SELECT user_id FROM gd_sessions WHERE token_hash=? AND user_id=? AND expires_at>NOW()', [hash('sha256', session_id()),$id]);
    if (!$a || !$session || $a['status'] !== 'active' || !hash_equals($_SESSION['password_stamp'] ?? '', hash('sha256', $a['password'])) || (!in_array($a['role'], ['student','alumni'], true) && !g_staff_login($a))) {
        $_SESSION = [];
        session_regenerate_id(true);
        if (defined('GD_JSON')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo '{"error":"Sign in again through the shared login."}';
            exit;
        }redirect('auth/login.php');
    }
    $_SESSION['user'] = array_intersect_key($a, array_flip(['id','username','full_name','role','office_code']));
    $_SESSION['user']['id'] = (int)$a['id'];
}
function g_denied(string $text = 'Guidance access denied.', int $status = 403): never
{
    http_response_code($status);
    if (defined('GD_JSON')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $text]);
    } else {
        echo '<h2>'.e($text).'</h2><p><a href="'.e(url('index.php')).'">Return to your workspace</a></p>';
    }exit;
}
function g_actor(): array
{
    if (empty($_SESSION['user'])) {
        if (defined('GD_JSON')) {
            g_denied('Sign in again through the shared login.', 401);
        }redirect('auth/login.php');
    }$u = require_role('student', 'admin');
    if (!g_ready()) {
        g_denied('Guidance is not installed yet.', 503);
    }if (!is_filer($u) && !g_staff($u)) {
        g_denied();
    }return $u;
}
function g_audit(array $u, string $action, string $ref = ''): void
{
    audit('GUIDANCE_'.$action, $ref, ['id' => $u['id'],'username' => $u['username'],'role' => $u['role'],'office_code' => 'guide']);
}
function g_notice(int $ticketId, ?int $only = null): void
{
    $t = gone("SELECT * FROM tickets WHERE id=? AND office_code='guide'", [$ticketId]);
    if (!$t) {
        return;
    }
    $recipients = gall("SELECT u.* FROM users u LEFT JOIN gd_staff s ON s.user_id=u.id WHERE u.status='active' AND (u.id=? OR (u.role='admin' AND u.office_code='guide' AND s.active=1 AND (s.guidance_role='head' OR (s.guidance_role='counselor' AND u.id=?))))", [$t['user_id'],$t['assigned_to'] ?? 0]);
    foreach ($recipients as $r) {
        if ($only !== null && (int)$r['id'] !== $only) {
            continue;
        }if (!g_case($r, $t)) {
            continue;
        }$pref = gone('SELECT ticket_updates FROM gd_preferences WHERE user_id=?', [$r['id']]);
        if ($pref && !(int)$pref['ticket_updates']) {
            continue;
        }notif_add($GLOBALS['pdo'], (int)$r['id'], $ticketId, 'Guidance request updated', 'Open the shared portal to view your authorized request.');
    }
}
/** Navigation grouping only. Endpoints still enforce their existing permissions. */
function g_nav(array $u): array
{
    $role = g_staff($u);
    $student = is_filer($u);
    $heading = $student ? 'Student · My work' : (['head'=>'Guidance Head · My work','counselor'=>'Counselor · My work','administrator'=>'Administrator · My work'][$role ?? ''] ?? 'Guidance');
    $items = [[$heading,null,null],['Overview','guidance/index.php','fa-people-group']];
    if ($student || in_array($role, ['head','counselor'], true)) {
        $requestLabel = $student ? 'My requests' : ($role === 'head' ? 'Requests & assignments' : 'Assigned requests');
        $items[] = [$requestLabel,'guidance/index.php?view=requests','fa-ticket'];
        $items[] = [$role === 'head' ? 'All appointments' : 'My appointments','guidance/index.php?view=appointments','fa-calendar'];
    }
    if ($role === 'administrator') {
        // This role's primary work is the existing activity operations page.
        // Do not duplicate its Activities link inside the shared group.
        $items[] = ['Activity operations','guidance/index.php?view=events','fa-calendar-days'];
    }
    if ($student) $items[] = ['Certificates & credits','guidance/index.php?view=history','fa-award'];
    if ($role === 'head') {
        $items[] = ['Privacy reviews','guidance/index.php?view=privacy','fa-shield-halved'];
        $items[] = ['Guidance audit','guidance/index.php?view=audit','fa-clipboard-list'];
    }
    $shared = [];
    if ($role !== 'administrator') $shared[] = ['Activities','guidance/index.php?view=events','fa-calendar-days'];
    $shared[] = ['Programs','guidance/index.php?view=programs','fa-seedling'];
    $shared[] = ['Calendar','guidance/index.php?view=calendar','fa-calendar-week'];
    if (!$student) $shared[] = ['Certificates & credits','guidance/index.php?view=history','fa-award'];
    $shared[] = ['Resources','guidance/index.php?view=knowledge','fa-book-open'];
    if ($role) $shared[] = ['Aggregate reports','guidance/index.php?view=reports','fa-chart-column'];
    // A fourth array element represents a collapsible group, not another route.
    $items[] = ['Shared Guidance tools',null,'fa-layer-group',$shared];
    return $items;
}
function g_signature(array $u): string
{
    $uid = (int)$u['id'];
    $role = g_staff($u);
    $scope = $role === 'head' ? '1=1' : ($role === 'counselor' ? 'assigned_to='.$uid : 'user_id='.$uid);
    $cases = gall("SELECT id,status,priority,assigned_to,updated_at,(SELECT COUNT(*) FROM replies r WHERE r.ticket_id=t.id".(is_filer($u) ? ' AND r.is_internal=0' : '').") AS replies FROM tickets t WHERE office_code='guide' AND $scope ORDER BY id");
    $events = gall('SELECT id,program_id,session_no,title,description,kind,status,starts_at,ends_at,venue,capacity,certificates_enabled,credit_units,minutes_before,minutes_after,updated_at FROM gd_events'.($role ? '' : " WHERE status<>'draft' AND id IN (SELECT event_id FROM gd_invites WHERE user_id=".$uid.')').' ORDER BY id');
    $data = [
        'identity' => [$uid,$u['full_name'],$role],
        'cases' => $cases,
        'events' => $events,
        'participation' => gall('SELECT id,state,attendance,checked_at FROM gd_invites'.($role ? '' : ' WHERE user_id='.$uid).' ORDER BY id'),
        'appointments' => gall('SELECT id,status,starts_at,ends_at,updated_at FROM gd_appointments'.($role === 'head' ? '' : ' WHERE user_id='.$uid.' OR counselor_id='.$uid).' ORDER BY id'),
        'articles' => gall('SELECT id,title,body,published,updated_at FROM gd_articles'.($role ? '' : ' WHERE published=1').' ORDER BY id'),
        'certificates' => gall('SELECT id FROM gd_certificates'.($role ? '' : ' WHERE user_id='.$uid).' ORDER BY id'),
        'programs' => gall('SELECT * FROM gd_programs'.($role ? '' : ' WHERE active=1').' ORDER BY id'),
        'categories' => gall("SELECT id,name,is_hidden FROM categories WHERE office_code='guide'".($role ? '' : ' AND is_hidden=0').' ORDER BY id'),
        'privacy' => gall('SELECT id,status,response,updated_at FROM gd_privacy'.($role === 'head' ? '' : ' WHERE user_id='.$uid).' ORDER BY id'),
        'preferences' => gone('SELECT ticket_updates FROM gd_preferences WHERE user_id=?',[$uid]),
        'team' => gall("SELECT u.id,u.full_name,u.status,s.guidance_role,s.active FROM users u JOIN gd_staff s ON s.user_id=u.id WHERE u.office_code='guide'".($role === 'head' ? '' : " AND u.role='admin' AND u.status='active' AND s.active=1 AND s.guidance_role IN ('head','counselor')").' ORDER BY u.id')
    ];
    return hash('sha256',json_encode($data));
}

function g_rate(string $key, int $limit, int $seconds = 900): bool
{
    $bucket = hash('sha256', $key);
    $until = date('Y-m-d H:i:s', time() + $seconds);
    gq('INSERT INTO gd_rate(bucket,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(expires_at>NOW(),attempts+1,1),expires_at=IF(expires_at>NOW(),expires_at,VALUES(expires_at))',[$bucket,$until]);
    return (int)gq('SELECT attempts FROM gd_rate WHERE bucket=?',[$bucket])->fetchColumn() <= $limit;
}
