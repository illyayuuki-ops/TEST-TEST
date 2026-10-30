<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
    session_start();
}
date_default_timezone_set('Asia/Manila');

/* Shared settings: credentials belong in the local environment, never Git. */
$local = is_file(__DIR__.'/local.php') ? require __DIR__.'/local.php' : [];
if (!is_array($local)) throw new RuntimeException('Local configuration must return an array.');
if (!empty($local['SYNTHETIC_TEST'])) putenv('LCCT_SYNTHETIC_TEST=1');
define('DB_PORT', (int)($local['DB_PORT'] ?? getenv('LCCT_DB_PORT') ?: 3306));
define('DB_HOST', $local['DB_HOST'] ?? getenv('LCCT_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', $local['DB_NAME'] ?? getenv('LCCT_DB_NAME') ?: 'lcct_guidance_v2_test');
define('DB_USER', $local['DB_USER'] ?? getenv('LCCT_DB_USER') ?: 'lcct_guidance_dev');
define('DB_PASS', $local['DB_PASS'] ?? getenv('LCCT_DB_PASSWORD') ?: '');
const APP_NAME_A = 'LCCT';
const APP_NAME_B = 'icketing';
const MAX_ATTEMPTS = 5;     // failed logins before lockout
const LOCK_MINUTES = 15;    // lockout length
/* The only office that has staff (admin) accounts; only its admin can sign in as staff. */
const STAFF_OFFICE = 'sao';

/* ── Verification & email ─────────────────────────────────────── */
const SCHOOL_EMAIL_DOMAIN = 'lcctanauan.edu.ph';   // students must register with name@THIS (no "@") — change if your student emails use another domain
const VERIFY_HOURS   = 24;                          // how long the email confirmation link works
const RESET_MINUTES  = 60;                          // how long a password-reset link works
const CARD_MAX_BYTES = 3 * 1024 * 1024;             // profile photo size limit (3 MB)
define('BREVO_API_KEY', $local['BREVO_API_KEY'] ?? getenv('LCCT_BREVO_API_KEY') ?: '');
define('MAIL_FROM', $local['MAIL_FROM'] ?? getenv('LCCT_MAIL_FROM') ?: '');
const MAIL_FROM_NAME = 'LCCT Ticketing';

/* Categories live in the `categories` table (one list per office).
   New offices start with this generic set; 'Other' is a normal, editable category; it is also where tickets go when another category is deleted (re-created automatically if missing). */
const DEFAULT_CATEGORIES = ['General Inquiry', 'Request', 'Complaint', 'Other'];
const LOCKED_CATEGORY    = 'Other';
const STATUSES = ['Open','Reopened','In Progress','Waiting for User','Escalated','Resolved','Closed','Cancelled'];
const WORKING_STATUSES = ['Open','Reopened','In Progress','Escalated'];                       // the office must act; the overdue clock runs
const OPEN_STATUSES    = ['Open','Reopened','In Progress','Waiting for User','Escalated'];   // not finished yet (Waiting for User pauses the clock)
const HISTORY_STATUSES = ['Closed','Cancelled'];                                                       // finished tickets: they leave the tracking pages and live in Ticket history
const PRIORITIES = ['Low','Normal','Urgent'];
/* Resolution targets in hours (checklist: priority and SLA rules). Tickets the office still has to act on, older than their target, count as overdue. */
const SLA_HOURS = ['Urgent' => 24, 'Normal' => 72, 'Low' => 120];
/* ── Department profiles (from the project checklist, section 3: Minimum Department Coverage) ───────────
   Keyed by office code. A new office whose code matches one of these starts with that department's categories;
   any other code gets DEFAULT_CATEGORIES. */
const OFFICE_PROFILES = [
    'registrar' => ['name'=>'Registrar', 'description'=>'Records, enrollment, transcripts and document requests', 'color'=>'#7B6BC4', 'icon'=>'fa-file-lines',
        'categories'=>['Enrollment Records', 'Transcript Request', 'Diploma Request', 'Transfer Credentials', 'Correction of Records']],
    'finance' => ['name'=>'Finance', 'description'=>'Payments, assessments, refunds and billing', 'color'=>'#2F9E6F', 'icon'=>'fa-money-bill-wave',
        'categories'=>['Payment Concern', 'Assessment Query', 'Refund', 'Billing Dispute', 'Scholarship Billing']],
    'hr' => ['name'=>'Human Resources', 'description'=>'Employment records, payroll, leave and benefits', 'color'=>'#C26A4A', 'icon'=>'fa-briefcase',
        'categories'=>['Employment Records', 'Payroll Concern', 'Leave Application', 'Benefits', 'Faculty / Staff Request']],
    'itdc' => ['name'=>'ITDC', 'description'=>'Network, accounts, software and computer lab support', 'color'=>'#2A8FA8', 'icon'=>'fa-laptop-code',
        'categories'=>['Network Access', 'Wi-Fi Concern', 'Computer Lab & Office Support', 'Software Installation', 'Email & Account Access']],
    'sao' => ['name'=>'Student Affairs Office', 'description'=>'IDs, discipline, org permits, student welfare and campus events', 'color'=>'#5E94CF', 'icon'=>'fa-user-graduate',
        'categories'=>['Student Activities', 'Organization Request', 'Disciplinary Concern', 'Student Welfare', 'Campus Events']],
    'admission' => ['name'=>'Admission & Scholarships', 'description'=>'Admission, applications, scholarships and financial assistance', 'color'=>'#B8860B', 'icon'=>'fa-user-graduate',
        'categories'=>['Admission Requirements', 'Application Status', 'Scholarship Application', 'Scholarship Renewal', 'Financial Assistance']],
    'main' => ['name'=>'Maintenance Office', 'description'=>'Repairs, utilities and facilities requests', 'color'=>'#8A6D3B', 'icon'=>'fa-screwdriver-wrench',
        'categories'=>['Classroom Repair', 'Electrical Concern', 'Plumbing', 'Air-Conditioning', 'Furniture', 'Facilities Request']],
    'library' => ['name'=>'Library', 'description'=>'Borrowing, database access, overdue notices and research help', 'color'=>'#2F9E6F', 'icon'=>'fa-book',
        'categories'=>['Book Reservation', 'Library Card Concern', 'Database Access', 'Overdue Notice', 'Research Assistance']],
    'marketing' => ['name'=>'Marketing', 'description'=>'Publications, promotions, social media and branding', 'color'=>'#D1477A', 'icon'=>'fa-bullhorn',
        'categories'=>['Publication Request', 'Event Promotion', 'Social Media Content', 'Branding Materials', 'Media Inquiry']],
    'aea' => ['name'=>'External Affairs & Alumni', 'description'=>'Alumni records, partnerships, MOAs and alumni events', 'color'=>'#7B6BC4', 'icon'=>'fa-award',
        'categories'=>['Alumni Records', 'Partnerships', 'Memorandum of Agreement', 'Alumni Events', 'External Linkages']],
    'guide' => ['name'=>'Guidance Office', 'description'=>'Counseling, career guidance and referrals', 'color'=>'#3E9B9B', 'icon'=>'fa-people-group',
        'categories'=>['Counseling Appointment', 'Career Guidance', 'Psychological Services', 'Student Concern', 'Referral Request']],
    'acads' => ['name'=>'Academic Office', 'description'=>'Curriculum, scheduling, academic policies (Basic Ed & College)', 'color'=>'#4A6FA5', 'icon'=>'fa-school',
        'categories'=>['Curriculum Concern', 'Class Scheduling', 'Academic Policy', 'Faculty Concern', 'Basic Education Request', 'College Academic Request']],
];

const OFFICE_ICONS = [
    'fa-screwdriver-wrench'=>'Maintenance','fa-bullhorn'=>'Marketing','fa-award'=>'Alumni','fa-school'=>'School',
    'fa-building'=>'Building','fa-user-graduate'=>'Graduate','fa-file-lines'=>'Document','fa-book'=>'Book',
    'fa-money-bill-wave'=>'Cashier','fa-stethoscope'=>'Clinic','fa-laptop-code'=>'IT','fa-briefcase'=>'Career',
    'fa-shield-halved'=>'Security','fa-people-group'=>'Guidance','fa-flask'=>'Laboratory','fa-dumbbell'=>'Sports',
];

/* ── Base URL (works in a sub-folder like /ticketing, at the web root, or behind symlinks on shared hosting) ───
   Strategy: compare THIS request's script path on disk with its URL path; the difference is the folder the app lives in. */
$__root = rtrim(str_replace('\\', '/', (string)realpath(__DIR__ . '/..')), '/');
$__sf   = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
$__sn   = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__base = null;
if ($__root !== '' && $__sf !== '' && $__sn !== '' && stripos($__sf, $__root . '/') === 0) {
    $__rel = substr($__sf, strlen($__root));                       // e.g. /auth/login.php
    if (strlen($__sn) >= strlen($__rel) && strcasecmp(substr($__sn, -strlen($__rel)), $__rel) === 0) $__base = rtrim(substr($__sn, 0, strlen($__sn) - strlen($__rel)), '/');
}
if ($__base === null) {   // fallback: document-root comparison
    $__doc = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $__base = ($__doc !== '' && stripos($__root, $__doc) === 0) ? rtrim(substr($__root, strlen($__doc)), '/') : '';
}
define('APP_BASE', $__base);
/* Site root used in emailed links (auto-detected from the request; set it manually if emails show the wrong address). */
define('APP_URL', ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

/* ── mbstring fallbacks (XAMPP has it enabled; this just avoids a hard dependency) ── */
if (!function_exists('mb_strlen'))     { function mb_strlen($s) { return strlen(utf8_decode((string)$s)); } }
if (!function_exists('mb_strimwidth')) { function mb_strimwidth($s, $st, $w, $trim = '') { $s = (string)$s; return strlen($s) > $w ? substr($s, $st, $w - strlen($trim)) . $trim : $s; } }

/* ── Database ──────────────────────────────────────────────────── */
if (getenv('LCCT_SYNTHETIC_TEST')==='1' && (!in_array(DB_HOST,['127.0.0.1','localhost','::1'],true)||!str_ends_with(DB_NAME,'_test'))) exit('Synthetic mode requires a local _test database.');
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");   // keep MySQL NOW() and PHP time() on the same clock
} catch (PDOException $ex) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Database connection failed. Check the existing private configuration and database service. Do not import or reset the shared database.\n"); exit(1); }
    http_response_code(503);
    header('Cache-Control: no-store');
    exit('<h3>Database temporarily unavailable.</h3><p>Contact the application administrator to check the database service and private configuration. Do not import, reset or replace the shared database.</p>');
}

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/guidance.php';
g_session_check();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* ── Helpers ───────────────────────────────────────────────────── */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function url(string $p = ''): string { return APP_BASE . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }

/** Send the redirect to the browser right away, then keep running (for slow work such as emails) so the person is not left waiting. */
function redirect_then_continue(string $p): void {
    ignore_user_abort(true);
    @set_time_limit(60);
    session_write_close();
    header('Location: ' . url($p));
    header('Connection: close');
    header('Content-Length: 0');
    http_response_code(302);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    // A form bigger than the server's post_max_size (e.g. big attachments) arrives completely empty; say so instead of "session expired".
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        exit('The upload was too large for this server. Go back and attach smaller files (or none), then try again.');
    }
    $sess = (string)($_SESSION['csrf'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($sess === '' || !hash_equals($sess, (string)($_POST['csrf'] ?? '')))) {
        http_response_code(419);
        exit('Session expired. Go back, refresh the page and try again.');
    }
}

function home_for(string $role): string {
    return match ($role) {
        'admin'       => (($_SESSION['user']['office_code']??'')==='guide' ? 'guidance/index.php' : 'admin/dashboard.php'),
        default       => 'student/dashboard.php',
    };
}

/** Guard a page. $roles uses 'student' to mean student OR alumni. */
function require_role(string ...$roles): array {
    $u = $_SESSION['user'] ?? null;
    if (!$u) redirect('auth/login.php');
    if (($u['role']??'')==='admin' && ($u['office_code']??'')==='guide' && str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']??''),'/admin/')) {
        if ($_SERVER['REQUEST_METHOD']==='POST') g_denied('Use the Guidance workspace for these actions.');
        $map=['dashboard.php'=>'requests','records.php'=>'requests','reports.php'=>'reports','categories.php'=>'categories','team.php'=>'team','audit.php'=>'audit'];
        redirect('guidance/index.php?view='.($map[basename($_SERVER['SCRIPT_NAME'])]??'overview'));
    }
    $r = in_array($u['role'], ['student', 'alumni'], true) ? 'student' : $u['role'];
    if (!in_array($r, $roles, true)) redirect(home_for($u['role']));
    // re-check the account is still active
    global $pdo;
    $s = $pdo->prepare('SELECT status, must_change_password FROM users WHERE id=?');
    $s->execute([$u['id']]);
    $row = $s->fetch();
    if (!$row || $row['status'] !== 'active') { session_destroy(); redirect('auth/login.php'); }
    if ($row['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        redirect('auth/change_password.php');
    }
    csrf_check();
    return $u;
}

function is_filer(array $u): bool { return in_array($u['role'], ['student', 'alumni'], true); }

function get_office(string $code): ?array {
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM offices WHERE office_code=?');
    $s->execute([$code]);
    return $s->fetch() ?: null;
}
function active_offices(): array {
    global $pdo;
    return $pdo->query("SELECT * FROM offices WHERE status='active' ORDER BY office_name")->fetchAll();
}

function status_class(string $s): string { return 'st-' . strtolower(str_replace(' ', '', $s)); }
function badge(string $s): string { return '<span class="badge ' . status_class($s) . '">' . e($s) . '</span>'; }
function prio_badge(string $p): string { return '<span class="badge pr-' . strtolower($p) . '">' . e($p) . '</span>'; }

function time_ago(string $ts): string {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}

/** Profile of a known department (see OFFICE_PROFILES), or null for any other office code. */
function office_profile(string $officeCode): ?array { return OFFICE_PROFILES[$officeCode] ?? null; }

/** ('A','B','C') for use inside SQL IN (...). Only ever called with the constant status lists above. */
function status_in(array $list): string { return "('" . implode("','", $list) . "')"; }

/** SQL condition: ticket is still active and older than the target for its priority. $a = table alias of tickets. */
function sla_overdue_sql(string $a = 't'): string {
    $case = "CASE $a.priority WHEN 'Urgent' THEN " . SLA_HOURS['Urgent'] . " WHEN 'Normal' THEN " . SLA_HOURS['Normal'] . " ELSE " . SLA_HOURS['Low'] . " END";
    return "($a.status IN " . status_in(WORKING_STATUSES) . " AND TIMESTAMPDIFF(HOUR,$a.created_at,NOW()) >= $case)";
}

/** Give a (new) office its starting categories: the department's own list when the code is a known department, else the generic set. Call inside the office-creation transaction. */
function seed_default_categories(PDO $pdo, string $officeCode): void {
    $ins = $pdo->prepare('INSERT IGNORE INTO categories (office_code,name,is_locked,sort_order) VALUES (?,?,?,?)');
    $names = office_profile($officeCode)['categories'] ?? DEFAULT_CATEGORIES;
    foreach ($names as $i => $name) $ins->execute([$officeCode, $name, 0, ($i + 1) * 10]);
    $ins->execute([$officeCode, LOCKED_CATEGORY, 0, 999]);
}

/** Categories of one office, in display order. $visibleOnly hides the ones the admin switched off. */
function office_categories(string $officeCode, bool $visibleOnly = true): array {
    global $pdo;
    $s = $pdo->prepare('SELECT * FROM categories WHERE office_code=?' . ($visibleOnly ? ' AND is_hidden=0' : '') . ' ORDER BY sort_order, id');
    $s->execute([$officeCode]);
    return $s->fetchAll();
}

/** Id of the office's fallback category (named LOCKED_CATEGORY, i.e. "Other"), optionally skipping one id. Creates it at the end if the office has none. */
function fallback_category_id(string $officeCode, int $exceptId = 0): int {
    global $pdo;
    $s = $pdo->prepare('SELECT id FROM categories WHERE office_code=? AND name=? AND id<>? LIMIT 1'); $s->execute([$officeCode, LOCKED_CATEGORY, $exceptId]);
    if ($id = (int)$s->fetchColumn()) return $id;
    $n = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM categories WHERE office_code=?'); $n->execute([$officeCode]);
    $pdo->prepare('INSERT INTO categories (office_code,name,is_locked,sort_order) VALUES (?,?,0,?)')->execute([$officeCode, LOCKED_CATEGORY, (int)$n->fetchColumn()]);
    return (int)$pdo->lastInsertId();
}

/* ── Student email / ID rules ─────────────────────────────────────────────────
   Student email = studentnumber@lcctanauan.edu.ph (e.g. 24-12803@lcctanauan.edu.ph).
   The student number is digits only (one hyphen between digit groups is allowed, as in 24-12803).
   The Student ID typed at sign-up must be exactly that same number. */
const STUDENT_NUM_RE = '[0-9]+(?:-[0-9]+)*';
function student_email_ok(string $email): bool {
    return (bool)preg_match('/^' . STUDENT_NUM_RE . '@' . preg_quote(SCHOOL_EMAIL_DOMAIN, '/') . '$/i', trim($email));
}
function student_num_from_email(string $email): string { return (string)strstr(trim($email), '@', true); }
function student_id_ok(string $id): bool { return (bool)preg_match('/^' . STUDENT_NUM_RE . '$/', trim($id)); }
const STUDENT_EMAIL_MSG = 'Invalid email. Use your school email in this format: studentnumber@' . SCHOOL_EMAIL_DOMAIN . ' (example: 24-12803@' . SCHOOL_EMAIL_DOMAIN . ').';
const STUDENT_ID_MSG    = 'Invalid student ID. Use numbers only (the same number that is in your school email).';
const STUDENT_MATCH_MSG = 'Your student ID must match the number in your school email.';

function valid_code(string $c): bool { return (bool)preg_match('/^[a-z0-9_]{2,30}$/', $c); }

/** Change a ticket's status in one place: keeps resolved_at right, writes the audit log and emails the requester. $t needs id, code, status. */
function change_ticket_status(PDO $pdo, array $t, string $ns, ?array $actor = null, bool $notify = true): bool {
    if (!in_array($ns, STATUSES, true) || $ns === $t['status']) return false;
    $guide = gone("SELECT status FROM tickets WHERE id=? AND office_code='guide'",[$t['id']]);
    if ($guide) {
        $flow=['Open'=>['In Progress','Waiting for User','Escalated','Resolved','Cancelled'],'Reopened'=>['In Progress','Waiting for User','Escalated','Resolved','Cancelled'],'In Progress'=>['Waiting for User','Escalated','Resolved'],'Waiting for User'=>['In Progress','Escalated','Resolved','Closed'],'Escalated'=>['In Progress','Waiting for User','Resolved'],'Resolved'=>['Closed','Reopened'],'Closed'=>['Reopened'],'Cancelled'=>[]];
        if (!in_array($ns,$flow[$guide['status']]??[],true)) return false;
    }
    $resolved = in_array($ns, ['Resolved', 'Closed'], true);
    $pdo->prepare('UPDATE tickets SET status=?, resolved_at=' . ($resolved ? 'COALESCE(resolved_at,NOW())' : 'NULL') . ' WHERE id=?')->execute([$ns, $t['id']]);
    audit('TICKET_STATUS', "{$t['code']}: {$t['status']} → $ns", $actor);
    if ($ns === 'Escalated') {                                    // tell the office's admins (not the one who just did it)
        $oc = $pdo->prepare('SELECT office_code FROM tickets WHERE id=?'); $oc->execute([$t['id']]);
        if ($code = $oc->fetchColumn()) notify_office_admins($pdo, (string)$code, 'Escalated: ' . $t['code'], '<p>Ticket <b>' . e($t['code']) . '</b> was escalated and needs attention.</p>', (int)$t['id'],
            ($_SESSION['user']['role'] ?? '') === 'admin' ? (int)$_SESSION['user']['id'] : null);
    }
    if ($notify) notify_status_change($pdo, (int)$t['id'], $ns);
    return true;
}

/** Escalate this office's overdue tickets automatically (Open / Reopened / In Progress past their target → Escalated). Cheap; runs when an admin opens the queue or reports. */
function auto_escalate(PDO $pdo, string $officeCode): int {
    $s = $pdo->prepare("SELECT t.id, t.code, t.status FROM tickets t WHERE t.office_code=? AND t.status IN ('Open','Reopened','In Progress') AND " . sla_overdue_sql('t') . ' LIMIT 50');
    $s->execute([$officeCode]); $n = 0; $done = [];
    foreach ($s->fetchAll() as $t) {
        $pdo->prepare("UPDATE tickets SET status='Escalated', resolved_at=NULL WHERE id=?")->execute([$t['id']]);
        audit('TICKET_ESCALATED', "{$t['code']}: {$t['status']} → Escalated (past its target time)", ['id' => null, 'username' => 'system', 'role' => null, 'office_code' => $officeCode]);
        $n++; $done[] = $t['code'];
    }
    if ($n) notify_office_admins($pdo, $officeCode, "Escalated: $n ticket(s) past their target time",
        '<p>These tickets passed their target time and were escalated automatically:</p><ul><li>' . implode('</li><li>', array_map('e', $done)) . '</li></ul>');
    return $n;
}

/* ── No-reply handling for students ───────────────────────────────────────────
   A ticket in "Waiting for User" closes by itself after this many days without any activity.
   A closed ticket can still be reopened by its student for REOPEN_WINDOW_DAYS days. */
const WAITING_AUTOCLOSE_DAYS = 7;
const REOPEN_WINDOW_DAYS     = 30;

/** Can the student still bring this closed ticket back? (Cancelled tickets stay cancelled.) */
function can_reopen(array $t): bool {
    return $t['status'] === 'Closed' && strtotime($t['updated_at']) > time() - REOPEN_WINDOW_DAYS * 86400;
}

/** Close tickets that waited too long for the student. $scope may hold user_id, office_code and/or id. Cheap; runs when the student or office opens their pages. */
function auto_close_waiting(PDO $pdo, array $scope = []): int {
    $sql = "SELECT id, code FROM tickets WHERE office_code<>'guide' AND status='Waiting for User' AND updated_at < (NOW() - INTERVAL " . (int)WAITING_AUTOCLOSE_DAYS . ' DAY)';
    $p = [];
    foreach (['user_id', 'office_code', 'id'] as $k) if (isset($scope[$k])) { $sql .= " AND $k=?"; $p[] = $scope[$k]; }
    $s = $pdo->prepare($sql . ' LIMIT 50'); $s->execute($p); $n = 0;
    foreach ($s->fetchAll() as $t) {
        $pdo->prepare("UPDATE tickets SET status='Closed', resolved_at=COALESCE(resolved_at,NOW()) WHERE id=?")->execute([$t['id']]);
        audit('TICKET_STATUS', "{$t['code']}: Waiting for User → Closed (no reply for " . WAITING_AUTOCLOSE_DAYS . ' days)', ['id' => null, 'username' => 'system', 'role' => null, 'office_code' => null]);
        notify_requester($pdo, (int)$t['id'], 'Ticket closed: no reply',
            '<p>Your ticket <b>' . e($t['code']) . '</b> was closed because the office did not hear back for ' . WAITING_AUTOCLOSE_DAYS . ' days.</p><p>It is not lost: open the ticket and press <b>Reopen ticket</b> within ' . REOPEN_WINDOW_DAYS . ' days to continue.</p>');
        $n++;
    }
    return $n;
}

/* Set to false once everything works. While true, a crash on the ticket page shows its error message instead of a blank page. */
const DEBUG_ERRORS = false;
