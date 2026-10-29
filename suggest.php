<?php
// Search-as-you-type suggestions for every search bar (like Google). Called by assets/js/app.js:
//   suggest.php?p=<page>&e=<section>&n=<field name>&q=<what was typed>   ->   [{"v":"value to put in the box","l":"label shown","h":"hint"}, ...]
require __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$page = basename((string)($_GET['p'] ?? '')); $e = (string)($_GET['e'] ?? ''); $name = (string)($_GET['n'] ?? 'q'); $q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || strlen($q) > 60 || !can_view_page($page, $e)) { echo '[]'; exit; }

// What each search bar looks in: [table, column, hint]. 'client:id' puts the borrower's ID in the box, 'client:name' the name.
$BOOKS = [['AcList', 'Title', 'Title'], ['AcList', 'Author', 'Author'], ['AcList', 'Accession', 'Accession'], ['AcList', 'Call_Number', 'Call no.'], ['AcList', 'Publisher', 'Publisher']];
$AV = [['AccSM', 'Title', 'Non-book'], ['AccSM', 'Accession', 'Accession'], ['AccSM', 'Author', 'Author']];
$SERIALS = [['Serial_TitleTemp', 'Serial_Title', 'Serial'], ['Serial_TitleTemp', 'ISSN', 'ISSN']];
$FILES = [['file_record', 'Title', 'File'], ['file_record', 'Author', 'Author'], ['file_record', 'Adviser', 'Adviser'], ['file_record', 'Accession', 'Accession']];
$map = [
    'acquisitions.php|q'   => [['acquitemp', 'Title', 'Title'], ['acquitemp', 'Author', 'Author'], ['acquitemp', 'Publisher', 'Publisher'], ['acquitemp', 'Dealer', 'Dealer'], ['acquitemp', 'ISBN', 'ISBN']],
    'archives.php|q'       => [['catalog_archive', 'Title', 'Title'], ['catalog_archive', 'Author', 'Author'], ['catalog_archive', 'Accession', 'Accession'], ['catalog_archive', 'Call_Number', 'Call no.']],
    'balance.php|idnum'    => 'client:id', 'circulation.php|idnum' => 'client:id', 'history.php|idnum' => 'client:id',
    'balance.php|q'        => 'client:name', 'clearance.php|q' => 'client:id', 'transactions.php|who' => [['txn_log', 'Item_name', 'Locker / computer / room']],
    'history.php|acc'      => [['AcList', 'Accession', 'Accession'], ['AccSM', 'Accession', 'Accession']],
    'equipment_loans.php|q' => [['equipment_loan', 'Equipment', 'Equipment'], ['equipment_loan', 'SN', 'Serial no.'], ['equipment_loan', 'Borrower_name', 'Borrower']],
    'files.php|q'          => $FILES,
    'inventory.php|q'      => [['inv_scan', 'Accession', 'Accession'], ['inv_scan', 'Title', 'Title'], ['inv_scan', 'Call_Number', 'Call no.']],
    'loans.php|q'          => [['Borrower', 'Title', 'Title'], ['Borrower', 'Accession_number', 'Accession'], ['Client', 'Name', 'Borrower'], ['Borrower', 'ID_Number', 'ID number']],
    'media.php|q'          => [['media_booking', 'Item', 'Item'], ['media_booking', 'Reserved_by', 'Reserved by'], ['media_booking', 'Faculty', 'Teacher'], ['media_booking', 'Subject', 'Subject']],
    'opac.php|q'           => array_merge($BOOKS, $AV, $SERIALS),
    'search.php|q'         => array_merge($BOOKS, $AV, $FILES, $SERIALS),
    'serials.php|q'        => [['Serial_TitleTemp', 'Serial_Title', 'Serial'], ['Serial_TitleTemp', 'ISSN', 'ISSN'], ['Serial_TitleTemp', 'Agent', 'Agent'], ['Serial_TitleTemp', 'Subject', 'Subject']],
    'transactions.php|q'   => [['txn_log', 'Item_name', 'Item'], ['txn_log', 'Borrower_name', 'Borrower'], ['txn_log', 'ID_Number', 'ID number']],
];
// crud.php depends on the section
$crud = [
    'books' => $BOOKS, 'nonbook' => $AV, 'authority' => [['Authority', 'Subject_heading', 'Heading']],
    'cashier' => [['Cashier', 'Name', 'Borrower'], ['Cashier', 'ID_number', 'ID number']], 'clients' => 'client:name',
    'holidays' => [], 'rooms' => [['room_reservation', 'Rooms', 'Room'], ['room_reservation', 'Location', 'Location']],
    'equipment' => [['Equipment', 'equipment', 'Equipment'], ['Equipment', 'brand', 'Brand'], ['Equipment', 'SN', 'Serial no.'], ['Equipment', 'location', 'Location']],
];
$src = $page === 'crud.php' ? ($crud[$e] ?? []) : ($map[$page . '|' . $name] ?? ($map[$page . '|q'] ?? []));

$esc = fn(string $s) => addcslashes($s, '%_\\');
$like = $esc($q);
$out = []; $seen = [];
$add = function (string $v, string $l, string $h) use (&$out, &$seen) {
    $v = trim($v); if ($v === '') return;
    $k = mb_strtolower($v . '|' . $h, 'UTF-8'); if (isset($seen[$k]) || count($out) >= 8) return;
    $seen[$k] = 1; $out[] = ['v' => $v, 'l' => $l !== '' ? $l : $v, 'h' => $h];
};
$cut = fn(string $s, int $n = 90) => function_exists('mb_substr') ? (mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s) : (strlen($s) > $n ? substr($s, 0, $n) . '...' : $s);
$pdo = db();

if (is_string($src)) {           // borrowers
    $byId = $src === 'client:id';
    foreach ([$like . '%', '%' . $like . '%'] as $pat) {
        if (count($out) >= 8) break;
        try {
            $st = $pdo->prepare('SELECT IDnum, Name FROM Client WHERE IDnum LIKE :a OR Name LIKE :b ORDER BY Name LIMIT 8'); $st->execute(['a' => $pat, 'b' => $pat]);
            foreach ($st->fetchAll() as $r) $add($byId ? (string)$r['IDnum'] : (string)$r['Name'], trim($r['Name'] . ' · ' . $r['IDnum'], ' ·'), 'Borrower');
        } catch (PDOException $ex) { }
    }
} else {
    foreach ([$like . '%', '%' . $like . '%'] as $pat) {           // words that start with what was typed first, then the ones that contain it
        foreach ($src as [$t, $c, $h]) {
            if (count($out) >= 8) break;
            try {
                $st = $pdo->prepare("SELECT DISTINCT `$c` AS v FROM `$t` WHERE `$c` LIKE :p AND `$c` IS NOT NULL AND `$c` <> '' ORDER BY `$c` LIMIT 4"); $st->execute(['p' => $pat]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $add((string)$v, $cut((string)$v), $h);
            } catch (PDOException $ex) { }     // a table or column that this database does not have: skip it
        }
        if (count($out) >= 5) break;
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
