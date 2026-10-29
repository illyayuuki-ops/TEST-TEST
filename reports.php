<?php
// Printed reports of the old Reports menu, rebuilt for the web. Pick a report, set the dates / library if it asks, then Print.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pageTitle = 'Reports';
const REP_LIMIT = 3000;
$from = valid_date($_GET['from'] ?? '', date('Y-m-01')); $to = valid_date($_GET['to'] ?? '', date('Y-m-d'));
$lib = trim((string)($_GET['lib'] ?? ''));
if (!lib_all()) $lib = user_library();                       // a user tied to one library only reports on it
elseif ($lib !== '' && !in_array($lib, libraries(), true)) $lib = '';

// title, group, SQL ({LIB} is replaced by the library filter), flags: dates = uses From/To, lib = [column, 'prefix'|'exact'] or null
$reports = [
  // ----- catalog
  'accession' => ['Accession list', 'Catalog', "SELECT Accession, Title, Author, Call_Number, Location FROM AcList WHERE 1=1 {LIB} ORDER BY Accession", ['lib' => ['Location', 'prefix']]],
  'barcode'   => ['Barcode list', 'Catalog', "SELECT Accession, Call_Number, Title FROM AcList WHERE 1=1 {LIB} ORDER BY Accession", ['lib' => ['Location', 'prefix']]],
  'biblio'    => ['Bibliography', 'Catalog', "SELECT Author, Title, Publisher, Ye_ar AS Year, Call_Number FROM AcList WHERE Author IS NOT NULL AND Author <> '' {LIB} ORDER BY Author, Title", ['lib' => ['Location', 'prefix']]],
  'shelf'     => ['Shelf list', 'Catalog', "SELECT Call_Number, Accession, Title, Author, Location FROM AcList WHERE Call_Number IS NOT NULL AND Call_Number <> '' {LIB} ORDER BY Call_Number", ['lib' => ['Location', 'prefix']]],
  'authority' => ['Authority file', 'Catalog', "SELECT Subject_heading AS Heading FROM Authority WHERE Subject_heading IS NOT NULL AND Subject_heading <> '' ORDER BY Subject_heading", []],
  'class'     => ['Classification', 'Catalog', "SELECT Call_Number FROM AcList WHERE 1=1 {LIB}", ['lib' => ['Location', 'prefix'], 'post' => 'dewey']],
  'collection'=> ['Collection', 'Catalog', "SELECT COALESCE(Location,'(none)') AS Location, COUNT(*) AS Books FROM AcList WHERE 1=1 {LIB} GROUP BY Location ORDER BY Location", ['lib' => ['Location', 'prefix']]],
  'nonbook'   => ['Non-book items by type', 'Catalog', "SELECT COALESCE(Type,'(none)') AS Type, COUNT(*) AS Items FROM AccSM GROUP BY Type ORDER BY Items DESC", []],
  'files'     => ['Files by kind', 'Catalog', "SELECT Type AS Kind, COUNT(*) AS Records FROM file_record WHERE 1=1 {LIB} GROUP BY Type ORDER BY Records DESC", ['lib' => ['Library', 'exact'], 'need' => 'file_record']],
  // ----- acquisitions
  'dept'      => ['Purchases per department', 'Acquisitions', "SELECT COALESCE(Dept,'(none)') AS Department, COUNT(*) AS Titles, SUM(Quantity) AS Copies, ROUND(SUM(Quantity*Price),2) AS Total_Cost FROM acquitemp WHERE Da_te BETWEEN :from AND :to GROUP BY Dept ORDER BY Total_Cost DESC", ['dates' => 1]],
  'dealer'    => ['Purchases per dealer', 'Acquisitions', "SELECT COALESCE(Dealer,'(none)') AS Dealer, COUNT(*) AS Titles, ROUND(SUM(Quantity*Price),2) AS Total_Cost FROM acquitemp WHERE Da_te BETWEEN :from AND :to GROUP BY Dealer ORDER BY Total_Cost DESC", ['dates' => 1]],
  'year'      => ['Purchases per year', 'Acquisitions', "SELECT YEAR(Da_te) AS Year, COUNT(*) AS Titles, SUM(Quantity) AS Copies, ROUND(SUM(Quantity*Price),2) AS Total_Cost FROM acquitemp WHERE Da_te IS NOT NULL GROUP BY YEAR(Da_te) ORDER BY 1 DESC", []],
  // ----- circulation
  'history'   => ['Borrowing history', 'Circulation', "SELECT b.ID_Number AS ID_number, c.Name, b.Accession_number AS Accession, b.Title, DATE(b.Date_Borrowed) AS Borrowed, DATE(b.Due_date) AS Due, DATE(b.Date_returned) AS Returned, b.Status FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE DATE(b.Date_Borrowed) BETWEEN :from AND :to {LIB} ORDER BY b.Date_Borrowed DESC", ['dates' => 1, 'lib' => ['b.Library', 'prefix']]],
  'loancount' => ['Loan counter (by borrower class)', 'Circulation', "SELECT COALESCE(c.User_class,'(unknown)') AS Borrower_class, COUNT(*) AS Loans, SUM(b.Date_returned IS NOT NULL) AS Returned FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE DATE(b.Date_Borrowed) BETWEEN :from AND :to {LIB} GROUP BY c.User_class ORDER BY Loans DESC", ['dates' => 1, 'lib' => ['b.Library', 'prefix']]],
  'loanstat'  => ['Loan statistic (per day)', 'Circulation', "SELECT DATE(b.Date_Borrowed) AS Day, COUNT(*) AS Loans, SUM(b.Date_returned IS NOT NULL) AS Returned FROM Borrower b WHERE DATE(b.Date_Borrowed) BETWEEN :from AND :to {LIB} GROUP BY DATE(b.Date_Borrowed) ORDER BY 1", ['dates' => 1, 'lib' => ['b.Library', 'prefix']]],
  'overdue'   => ['Overdue items', 'Circulation', "SELECT b.ID_Number AS ID_number, c.Name, b.Accession_number AS Accession, b.Title, DATE(b.Due_date) AS Due, DATEDIFF(CURDATE(), b.Due_date) AS Days_past_due FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE b.Date_returned IS NULL AND COALESCE(b.Status,'') <> 'Lost' AND b.Due_date < CURDATE() {LIB} ORDER BY b.Due_date", ['lib' => ['b.Library', 'prefix']]],
  'lost'      => ['Lost record', 'Circulation', "SELECT b.ID_Number AS ID_number, c.Name, b.Accession_number AS Accession, b.Title, DATE(b.Date_Borrowed) AS Borrowed FROM Borrower b LEFT JOIN Client c ON c.IDnum = b.ID_Number WHERE b.Status = 'Lost' AND b.Date_returned IS NULL {LIB} ORDER BY b.Date_Borrowed DESC", ['lib' => ['b.Library', 'prefix']]],
  'reserved'  => ['Materials reservation list', 'Circulation', "SELECT Name, IDnumber AS ID_number, Accession, Title, Kind, DateReserved AS Reserved, DateExpired AS Expires, Claimed FROM Reservation WHERE DateReserved BETWEEN :from AND :to ORDER BY DateReserved DESC", ['dates' => 1]],
  'payments'  => ['Payment', 'Circulation', "SELECT DatePayment AS Date_paid, ID_number, Name, Amount FROM Cashier WHERE DatePayment BETWEEN :from AND :to ORDER BY DatePayment DESC, ID DESC", ['dates' => 1]],
  'balances'  => ['Unpaid fines (balance)', 'Circulation', "SELECT f.ID_Number AS ID_number, c.Name, COUNT(*) AS Fines, SUM(f.Amount - f.Paid) AS Amount_owed FROM fine_balance f LEFT JOIN Client c ON c.IDnum = f.ID_Number WHERE f.Status = 'Unpaid' {LIB} GROUP BY f.ID_Number, c.Name ORDER BY Amount_owed DESC", ['lib' => ['f.Library', 'prefix'], 'need' => 'fine_balance']],
  'serialhist'=> ['Serial transaction history', 'Circulation', "SELECT s.DateOut AS Checked_out, t.Serial_Title AS Title, s.Date_Year AS Issue, s.Volume, s.Number, c.Name AS Borrower, s.DateDue AS Due, s.DateIn AS Returned FROM SerialsTemp s JOIN Serial_TitleTemp t ON t.Code = s.Code LEFT JOIN Client c ON c.IDnum = s.ID WHERE s.DateOut IS NOT NULL AND DATE(s.DateOut) BETWEEN :from AND :to {LIB} ORDER BY s.DateOut DESC", ['dates' => 1, 'lib' => ['t.Library', 'exact']]],
  // ----- equipment and rooms
  'eq_all'    => ['Equipment loans (all equipment)', 'Equipment and rooms', "SELECT Equipment, SN, ID_Number AS ID_number, Borrower_name AS Borrower, Date_out AS Date_out, Due_at AS Due_at, Returned_at AS Returned, Status FROM equipment_loan WHERE DATE(Date_out) BETWEEN :from AND :to {LIB} ORDER BY Date_out DESC", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'equipment_loan']],
  'eq_item'   => ['Use of each equipment', 'Equipment and rooms', "SELECT Equipment, SN, COUNT(*) AS Loans, SUM(Hours) AS Hours_lent, SUM(Status = 'Out') AS Still_out FROM equipment_loan WHERE DATE(Date_out) BETWEEN :from AND :to {LIB} GROUP BY Equipment, SN ORDER BY Loans DESC", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'equipment_loan']],
  'eq_list'   => ['Equipment inventory', 'Equipment and rooms', "SELECT equipment AS Equipment, SN, brand AS Brand, department AS Department, location AS Location, status AS Status FROM Equipment ORDER BY equipment, SN", ['need' => 'Equipment']],
  'eq_book'   => ['Equipment bookings', 'Equipment and rooms', "SELECT Booking_date AS Date, Time_slot AS Time, Item AS Equipment, Reserved_by AS Reserved_by, Faculty, Subject, Purpose FROM media_booking WHERE Kind = 'Equipment' AND Booking_date BETWEEN :from AND :to {LIB} ORDER BY Booking_date, Start_min", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'media_booking']],
  'room_all'  => ['Room reservations (all rooms)', 'Equipment and rooms', "SELECT Booking_date AS Date, Time_slot AS Time, Item AS Room, Reserved_by AS Reserved_by, Faculty, Subject, Purpose FROM media_booking WHERE Kind = 'Venue' AND Booking_date BETWEEN :from AND :to {LIB} ORDER BY Booking_date, Start_min", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'media_booking']],
  'room_use'  => ['Use of each room', 'Equipment and rooms', "SELECT Item AS Room, COUNT(*) AS Reservations, COUNT(DISTINCT Booking_date) AS Days_used FROM media_booking WHERE Kind = 'Venue' AND Booking_date BETWEEN :from AND :to {LIB} GROUP BY Item ORDER BY Reservations DESC", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'media_booking']],
  // ----- checklist and money
  'checklist' => ['Shelf checklist (tick each book on the shelf)', 'Catalog', "SELECT '[   ]' AS Found, Accession, Call_Number, Title, Location FROM AcList WHERE 1=1 {LIB} ORDER BY Call_Number, Accession", ['lib' => ['Location', 'prefix']]],
  'invvalue'  => ['Inventory value (cost of materials)', 'Acquisitions', "SELECT CASE MType WHEN 'SM' THEN 'Audio-visual' WHEN 'EQ' THEN 'Equipment' ELSE 'Books' END AS Material, COUNT(*) AS Titles, SUM(Quantity) AS Copies, ROUND(SUM(Quantity*Price),2) AS Total_Cost FROM acquitemp GROUP BY Material ORDER BY Total_Cost DESC", []],
  'budget'    => ['Budget report (spending per budget)', 'Acquisitions', "SELECT COALESCE(Budget,'(none)') AS Budget, COUNT(*) AS Titles, SUM(Quantity) AS Copies, ROUND(SUM(Quantity*Price),2) AS Total_Cost FROM acquitemp WHERE Da_te BETWEEN :from AND :to GROUP BY Budget ORDER BY Total_Cost DESC", ['dates' => 1]],
  // ----- patrons and system
  'patronlog' => ['Patron logfile (attendance)', 'Patrons and system', "SELECT Scanned_at AS Time_in,{OUT} ID_Number AS ID_number, Name, User_class AS Class, Library FROM attendance_log WHERE DATE(Scanned_at) BETWEEN :from AND :to {LIB} ORDER BY Scanned_at DESC", ['dates' => 1, 'lib' => ['Library', 'exact'], 'need' => 'attendance_log']],
  'syslog'    => ['System logfile (user logins)', 'Patrons and system', "SELECT Username, Full_name AS Name, Role AS Access_right, Library, Last_login FROM Users ORDER BY Last_login DESC, Username", ['need' => 'Users']],
];
foreach (['dept', 'dealer', 'year', 'invvalue', 'budget'] as $k) if (!is_role('admin')) unset($reports[$k]);   // purchase prices are for the administrator only
$groups = []; foreach ($reports as $k => $d) $groups[$d[1]][$k] = $d[0];

$r = $_GET['r'] ?? '';
include __DIR__ . '/includes/header.php';
if (!isset($reports[$r])) { ?>
  <h1>Reports</h1>
  <p class="muted">Pick a report. Reports that depend on dates or on a library ask for them before printing.</p>
  <?php foreach ($groups as $g => $items): ?><h2><?= e($g) ?></h2><div class="cards">
    <?php foreach ($items as $k => $title): ?><a class="card stat" href="reports.php?r=<?= e($k) ?>"><span><?= e($title) ?></span></a><?php endforeach; ?></div><?php endforeach; ?>
  <p class="muted">The old system's printed letters and book cards (overdue notices, shelf cards) are not repeated here: overdue notices print from Due / out today.</p>
<?php } else {
  [$title, , $sql, $o] = $reports[$r];
  $usesDates = !empty($o['dates']); $libDef = $o['lib'] ?? null;
  $rows = []; $err = ''; $more = false;
  if (!empty($o['need']) && !table_exists($o['need'])) $err = 'This report needs a table that is not in the database yet (import the latest SQL files).';
  else {
    $args = []; $libSql = '';
    if ($libDef && $lib !== '') { [$col, $mode] = $libDef; $libSql = " AND $col " . ($mode === 'prefix' ? 'LIKE :lib' : '= :lib'); $args['lib'] = $mode === 'prefix' ? $lib . '%' : $lib; }
    if ($usesDates) { $args['from'] = $from; $args['to'] = $to; }
    $sqlx = str_replace(['{LIB}', '{OUT}'], [$libSql, att_has_inout() ? ' Time_out,' : ''], $sql);
    if (att_has_inout()) att_autoclose();
    try {
      $st = db()->prepare($sqlx . (($o['post'] ?? '') === '' && stripos($sqlx, 'GROUP BY') === false ? ' LIMIT ' . (REP_LIMIT + 1) : '')); $st->execute($args); $rows = $st->fetchAll();
      if (($o['post'] ?? '') === 'dewey') {   // count books per main Dewey class (first number of the call number, e.g. 371.9 -> 300)
        $names = ['000' => 'Computer science, information and general works', '100' => 'Philosophy and psychology', '200' => 'Religion', '300' => 'Social sciences', '400' => 'Language', '500' => 'Science', '600' => 'Technology', '700' => 'Arts and recreation', '800' => 'Literature', '900' => 'History and geography'];
        $cnt = []; foreach ($rows as $x) { $c = preg_match('/\b(\d{3})(?:\.\d+)?\b/', (string)$x['Call_Number'], $m) ? substr($m[1], 0, 1) . '00' : ''; $k = $c === '' ? '(no class number)' : ($c === '000' ? '000' : $c); $cnt[$k] = ($cnt[$k] ?? 0) + 1; }
        ksort($cnt); $rows = []; foreach ($cnt as $k => $n) $rows[] = ['Class' => $k, 'Subject' => $names[$k] ?? '', 'Books' => $n];
      }
      if (count($rows) > REP_LIMIT && empty($o['post'])) { $rows = array_slice($rows, 0, REP_LIMIT); $more = true; }
    } catch (PDOException $ex) { $rows = []; $err = 'This report could not be built.' . (!empty($CONFIG['debug']) ? ' ' . $ex->getMessage() : ''); }
  }
  $numCols = ['Books', 'Items', 'Hours_lent', 'Still_out', 'Reservations', 'Days_used', 'Titles', 'Copies', 'Loans', 'Returned', 'Records', 'Fines', 'Visits', 'Days_past_due']; $moneyCols = ['Total_Cost', 'Amount', 'Amount_owed'];
  $period = $usesDates ? fdate($from) . ' to ' . fdate($to) : ''; ?>
  <p class="no-print"><a href="reports.php">‹ Reports</a></p>
  <div class="head-row"><h1><?= e($title) ?></h1><button class="btn no-print" data-print>Print</button></div>
  <div class="rep-head"><b><?= e(report_heading()) ?></b><span><?= e($title) ?><?= $period ? ' · ' . e($period) : '' ?><?= $libDef && $lib !== '' ? ' · ' . e($lib) : '' ?></span></div>
  <?php if ($usesDates || $libDef): ?><form method="get" class="toolbar no-print"><input type="hidden" name="r" value="<?= e($r) ?>">
    <?php if ($usesDates): ?><label>From <input type="date" name="from" value="<?= e($from) ?>"></label><label>To <input type="date" name="to" value="<?= e($to) ?>"></label><?php endif; ?>
    <?php if ($libDef): ?><select name="lib" aria-label="Library" <?= lib_all() ? '' : 'disabled' ?>><option value="">All libraries</option><?php foreach (libraries() as $l): ?><option <?= $l === $lib ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn primary">Show</button></form><?php endif; ?>
  <p class="muted no-print">Generated <?= date('M j, Y g:i A') ?> · <?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?><?= $more ? ' (first ' . number_format(REP_LIMIT) . ' shown)' : '' ?></p>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <div class="table-wrap"><table class="data" data-sortable><thead><tr>
    <?php foreach (array_keys($rows[0] ?? []) as $c): ?><th><?= e(str_replace('_', ' ', $c)) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><?php foreach ($row as $c => $v): ?><td><?= $v !== null && is_numeric($v) && in_array($c, $moneyCols, true) ? e(number_format((float)$v, 2)) : ($v !== null && is_numeric($v) && in_array($c, $numCols, true) ? e(number_format((float)$v)) : e($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    <?php if (!$rows && !$err): ?><tr><td class="muted">Nothing to show for this selection.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php }
include __DIR__ . '/includes/footer.php';
