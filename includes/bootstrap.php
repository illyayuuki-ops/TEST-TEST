<?php
date_default_timezone_set("Asia/Manila"); // closing time and all scan times are Philippine time
// Loaded by every page: session, database connection, small helper functions.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$CONFIG = require __DIR__ . '/../config.php';

// Everything except the login page needs a logged-in session.
$__pub = in_array(basename($_SERVER['SCRIPT_NAME']), ['login.php', 'opac.php'], true) || (basename($_SERVER['SCRIPT_NAME']) === 'suggest.php' && basename((string)($_GET['p'] ?? '')) === 'opac.php');   // suggestions for the public catalog box
if (!$__pub && empty($_SESSION['auth'])) {   // opac.php is the public catalog
    header('Location: login.php'); exit;
}

// ---- Roles -------------------------------------------------------------
//  admin     : everything, including Setup, Lists, All tables and Cashier.
//  librarian : catalog, borrowers, circulation, reports (add / edit / delete). No Setup, Lists, All tables, Cashier.
//  staff     : circulation, loans, reservations; can only LOOK at books, non-book items and borrowers.
function role(): string { return $_SESSION['role'] ?? ''; }
function is_role(string ...$r): bool { return in_array(role(), $r, true); }
function can_view_page(string $script, string $entity = ''): bool {
    if ($script === 'opac.php') return true;   // the public catalog is open to everyone
    switch (role()) {
        case 'admin': return true;
        case 'librarian':
            if (in_array($script, ['setup.php', 'browse.php', 'lookups.php', 'utilities.php'], true)) return false;
            return !($script === 'crud.php' && in_array($entity, ['cashier', 'acquisitions'], true));
        case 'staff':
            if (in_array($script, ['index.php', 'circulation.php', 'loans.php', 'reservations.php', 'media.php', 'equipment_loans.php', 'attendance.php', 'transactions.php', 'serials.php', 'inventory.php', 'balance.php', 'search.php', 'files.php', 'suggest.php', 'holds.php', 'history.php', 'average.php', 'renewal_slip.php', 'online_requests.php', 'clearance.php', 'referral.php', 'daily.php'], true)) return true;
            return $script === 'crud.php' && in_array($entity, ['books', 'nonbook', 'clients'], true);
    }
    return false;
}
function can_edit_records(): bool { return is_role('admin', 'librarian'); }

// ---- Libraries ---------------------------------------------------------
// Every user belongs to one library, or to "All libraries" (sees everything). Stored in Users.Library and kept in the session at login.
// The levels ("libraries"): College, Senior High School, Junior High School, Middle School, High School, Lower School, Kindergarten.
// Catalog locations and loans are matched by prefix, so "College Library - Circulation" belongs to College.
function libraries(): array { return ['College', 'SHS', 'JHS', 'MS', 'HS', 'LS', 'KS']; }
function library_names(): array { return ['College' => 'College', 'SHS' => 'Senior High School', 'JHS' => 'Junior High School', 'MS' => 'Middle School', 'HS' => 'High School', 'LS' => 'Lower School', 'KS' => 'Kindergarten']; }
function user_library(): string { return $_SESSION['library'] ?? 'All libraries'; }
function lib_all(): bool { return user_library() === 'All libraries'; }
function deny(): void { http_response_code(403); exit('<h2>Not allowed</h2><p>Your account (' . htmlspecialchars(role()) . ') cannot open this page. <a href="index.php">Back to dashboard</a></p>'); }
if (!empty($_SESSION['auth'])) {
    $__s = basename($_SERVER['SCRIPT_NAME']);
    if ($__s !== 'logout.php' && !can_view_page($__s, (string)($_GET['e'] ?? ''))) deny();
}

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = "mysql:host={$CONFIG['db_host']};port={$CONFIG['db_port']};dbname={$CONFIG['db_name']};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            $detail = !empty($CONFIG['debug']) ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '<p>(Set <code>debug</code> to true in config.php to see details.)</p>';
            exit('<h2>Cannot connect to the database</h2><p>Check the database settings in <code>config.php</code> and make sure the SQL files were imported.</p>' . $detail);
        }
    }
    return $pdo;
}

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $sent = $_POST['csrf'] ?? '';
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expired. Go back and try again.');
    }
}
function flash(?string $msg = null) {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}
// Shorten long text for table cells (works with or without the mbstring extension).
function shorten($v, int $max = 120): string {
    $v = (string)($v ?? '');
    if (function_exists('mb_strlen')) return mb_strlen($v) > $max ? mb_substr($v, 0, $max) . '…' : $v;
    return strlen($v) > $max ? substr($v, 0, $max) . '...' : $v;
}
function redirect(string $url): void { header("Location: $url"); exit; }

// The Grade_level column on Client comes from 12_borrower_level.sql; the borrower screens work without it until it is imported.
function client_has_level(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool)db()->query("SHOW COLUMNS FROM Client LIKE 'Grade_level'")->fetch(); } catch (PDOException $e) { $has = false; } }
    return $has;
}

function client_has_section(): bool {   // Client.Section comes from 13_students.sql
    static $has = null;
    if ($has === null) { try { $has = (bool)db()->query("SHOW COLUMNS FROM Client LIKE 'Section'")->fetch(); } catch (PDOException $e) { $has = false; } }
    return $has;
}

// Entities = the tables that have full add / edit / delete screens.
function entities(): array {
    return [
        'books' => [
            'title' => 'Books (Accession List)', 'table' => 'AcList', 'pk' => 'ID',
            'fields' => [
                'Accession' => ['Accession no.', 'text'], 'Title' => ['Title', 'textarea'], 'Author' => ['Author', 'text'],
                'Publisher' => ['Publisher', 'text'], 'Call_Number' => ['Call number', 'text'], 'ISBN' => ['ISBN', 'text'],
                'Ye_ar' => ['Year', 'text'], 'Status' => ['Status', 'text'], 'Location' => ['Location', 'text'],
            ],
            'list' => ['Accession', 'Title', 'Author', 'Call_Number', 'Location', 'Ye_ar'],
            'search' => ['Accession', 'Title', 'Author', 'Publisher', 'Call_Number', 'ISBN'],
            'filter' => 'Location',
        ],
        'acquisitions' => [
            'title' => 'Acquisitions (Purchases)', 'table' => 'acquitemp', 'pk' => 'ID',
            'fields' => [
                'Title' => ['Title', 'text'], 'Author' => ['Author', 'text'], 'Publisher' => ['Publisher', 'text'],
                'ISBN' => ['ISBN', 'text'], 'Ye_ar' => ['Year', 'text'], 'Quantity' => ['Quantity', 'number'],
                'Price' => ['Price', 'number'], 'Da_te' => ['Date', 'date'], 'Status' => ['Status', 'text'],
                'Mode' => ['Mode', 'text'], 'MType' => ['Material type', 'text'], 'Dept' => ['Department', 'text'],
                'Dealer' => ['Dealer', 'text'],
            ],
            'list' => ['Title', 'Author', 'Quantity', 'Price', 'Da_te', 'Dept', 'Dealer'],
            'search' => ['Title', 'Author', 'Publisher', 'ISBN', 'Dealer'],
            'filter' => 'Dept',
        ],
        'nonbook' => [
            'title' => 'Non-book Materials', 'table' => 'AccSM', 'pk' => 'ID',
            'fields' => [
                'Accession' => ['Accession no.', 'text'], 'Title' => ['Title', 'text'], 'Author' => ['Author', 'text'],
                'Publisher' => ['Publisher', 'text'], 'Call_Number' => ['Call number', 'text'], 'Ye_ar' => ['Year', 'text'],
                'Type' => ['Type', 'text'], 'Status' => ['Status', 'text'], 'Location' => ['Location', 'text'],
            ],
            'list' => ['Accession', 'Title', 'Type', 'Location', 'Status'],
            'search' => ['Accession', 'Title', 'Author', 'Publisher', 'Call_Number'],
            'filter' => 'Type',
        ],
        'authority' => [
            'title' => 'Authority (Subject Headings)', 'table' => 'Authority', 'pk' => 'ID',
            'fields' => ['Subject_heading' => ['Heading', 'text']],
            'list' => ['Subject_heading'], 'search' => ['Subject_heading'], 'filter' => null,
        ],
        'cashier' => [
            'title' => 'Cashier (Payments)', 'table' => 'Cashier', 'pk' => 'ID',
            'fields' => ['ID_number' => ['ID number', 'text'], 'Name' => ['Name', 'text'],
                         'DatePayment' => ['Date paid', 'date'], 'Amount' => ['Amount', 'number']],
            'list' => ['ID_number', 'Name', 'DatePayment', 'Amount'], 'search' => ['ID_number', 'Name'], 'filter' => null,
        ],
        'clients' => [
            'title' => 'Borrowers', 'table' => 'Client', 'pk' => 'ID',
            'fields' => [
                'IDnum' => ['ID number', 'text'], 'Name' => ['Name', 'text'], 'Address' => ['Address', 'text'],
                'User_class' => ['User class (Student, Faculty, ...)', 'text'], 'Degree_Course' => ['Course', 'text'],
                'Year_Level' => ['Year level', 'text'], 'telephone' => ['Telephone', 'text'],
            ] + (client_has_level() ? ['Grade_level' => ['Grade level', 'select:levels']] : []) + (client_has_section() ? ['Section' => ['Section', 'text']] : []),
            'list' => array_merge(['IDnum', 'Name'], client_has_level() ? ['Grade_level'] : [], ['User_class', 'Degree_Course', 'Year_Level'], client_has_section() ? ['Section'] : [], ['Bkloan']),
            'search' => ['IDnum', 'Name', 'Degree_Course'], 'filter' => 'User_class', 'filter2' => client_has_level() ? 'Grade_level' : null,
        ],
        'holidays' => [
            'title' => 'Holidays (library closed)', 'table' => 'Holiday', 'pk' => 'ID',
            'fields' => ['Holiday' => ['Date', 'date']], 'list' => ['Holiday'], 'search' => ['Holiday'], 'filter' => null,
        ],
        'rooms' => [
            'title' => 'Rooms', 'table' => 'room_reservation', 'pk' => 'rmID',
            'fields' => [
                'Rooms' => ['Room', 'text'], 'Capacity' => ['Capacity', 'text'], 'Description' => ['Description', 'text'],
                'Location' => ['Location', 'text'], 'Accessories' => ['Accessories', 'text'], 'Remarks' => ['Remarks', 'text'],
            ],
            'list' => ['Rooms', 'Capacity', 'Location', 'Remarks'], 'search' => ['Rooms', 'Description', 'Location'], 'filter' => null,
        ],
        'equipment' => [
            'title' => 'Equipment', 'table' => 'Equipment', 'pk' => 'eqID',
            'fields' => [
                'equipment' => ['Equipment', 'text'], 'brand' => ['Brand', 'text'], 'SN' => ['Serial no.', 'text'],
                'physical_description' => ['Description', 'text'], 'accessories' => ['Accessories', 'text'],
                'department' => ['Department', 'text'], 'location' => ['Location', 'text'], 'status' => ['Status', 'text'],
                'date_acquisition' => ['Date acquired', 'date'], 'mode_acquisition' => ['Mode of acquisition', 'text'],
                'price' => ['Price', 'number'], 'remarks' => ['Remarks', 'text'],
            ],
            'list' => ['equipment', 'brand', 'SN', 'location', 'status'],
            'search' => ['equipment', 'brand', 'SN', 'location'], 'filter' => 'status',
        ],
    ];
}

function all_tables(): array {
    global $CONFIG;
    $st = db()->prepare("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name");
    $st->execute([$CONFIG['db_name']]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $n = (int) db()->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $r['t']) . "`")->fetchColumn();
        $out[] = ['t' => $r['t'], 'n' => $n];
    }
    return $out;
}

require_once __DIR__ . '/utils.php';
