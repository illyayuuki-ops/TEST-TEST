<?php
// Circulation rules and actions: borrowing, returning, renewing, lost items, fines.
// Uses the original tables: Client (borrowers), Borrower (loans), Reservation, Holiday, OverdueCharge, Cashier.

function circ_cfg(): array {
    global $CONFIG;
    return array_merge([
        'loan_days' => 7,              // regular books
        'overnight_days' => 1,         // items whose location contains reserve_keyword
        'nonbook_days' => 3,           // non-book materials (CDs, maps, ...)
        'max_loans' => 3,              // items one borrower may hold at once
        'block_if_overdue' => true,    // no new loans while something is overdue
        'no_loan_keywords' => ['Reference'],
        'reserve_keyword' => 'Reserve',
        'reservation_days' => 3,
        'hold_days' => 1,              // how long a held item waits for its borrower (open days)
        'block_if_unpaid' => false,    // true = no new loans while the borrower has an unpaid fine
        'default_fine_per_day' => 5,
        'currency' => '₱',
    ], $CONFIG['circulation'] ?? []);
}

function money($n): string { return circ_cfg()['currency'] . number_format((float)$n, 2); }
function fdate($d): string { return $d ? date('M j, Y', strtotime((string)$d)) : ''; }

// ---------- calendar: the library is closed on the days ticked in Utilities > Settings and on dates in the Holiday table ----------
function closed_dates(): array {
    static $c = null;
    if ($c === null) {
        $rows = db()->query("SELECT DATE_FORMAT(Holiday, '%Y-%m-%d') FROM Holiday WHERE Holiday IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $c = array_flip($rows);
    }
    return $c;
}
// Weekdays excluded in Utilities > Settings (OverdueCharge flags). With no settings row, only Sunday is excluded.
function excluded_weekdays(): array {
    static $w = null;
    if ($w === null) {
        $r = db()->query('SELECT Monday, Friday, Saturday, Sunday FROM OverdueCharge LIMIT 1')->fetch();
        $w = $r ? array_keys(array_filter([1 => $r['Monday'], 5 => $r['Friday'], 6 => $r['Saturday'], 0 => $r['Sunday']])) : [0];
    }
    return $w;
}
function is_closed(DateTime $d): bool { return in_array((int)$d->format('w'), excluded_weekdays(), true) || isset(closed_dates()[$d->format('Y-m-d')]); }
function due_date_for(int $days): DateTime {
    $d = new DateTime('today');
    $d->modify("+$days day");
    for ($i = 0; $i < 14 && is_closed($d); $i++) $d->modify('+1 day');   // due dates never fall on a closed day
    return $d;
}
// open days between the due date and the return date (or today if still out)
function late_days(?string $due, ?string $returned = null): int {
    if (!$due) return 0;
    $d = new DateTime(substr($due, 0, 10));
    $end = new DateTime($returned ? substr($returned, 0, 10) : 'today');
    $n = 0;
    for ($i = 0; $i < 3650; $i++) {
        $d->modify('+1 day');
        if ($d > $end) break;
        if (!is_closed($d)) $n++;
    }
    return $n;
}

// ---------- fines: per-day rate by borrower class, read from the OverdueCharge table ----------
function fine_rate(?string $userClass, bool $overnight): float {
    static $row = null;
    if ($row === null) $row = db()->query('SELECT * FROM OverdueCharge LIMIT 1')->fetch() ?: [];
    if ($overnight && !empty($row['Overnight'])) return (float)$row['Overnight'];
    $map = ['student' => 'Student', 'faculty' => 'Faculty', 'grade school' => 'Grade_school', 'high school' => 'High_school', 'ls' => 'Grade_school', 'hs' => 'High_school',
            'supervisor' => 'Supervisor', 'admin' => 'Admin', 'staff' => 'Staff', 'personnel' => 'Personnel'];
    $k = strtolower(trim(str_replace(['_', '-'], ' ', (string)$userClass)));
    if (isset($map[$k]) && isset($row[$map[$k]])) return (float)$row[$map[$k]];
    return (float)($row['OvedueCharge'] ?? circ_cfg()['default_fine_per_day']);
}
function is_overnight(?string $location): bool {
    $k = circ_cfg()['reserve_keyword'];
    return $k !== '' && stripos((string)$location, $k) !== false;
}
function loan_days_for(string $kind, ?string $location): int {
    $c = circ_cfg();
    if (is_overnight($location)) return (int)$c['overnight_days'];
    return (int)($kind === 'Book' ? $c['loan_days'] : $c['nonbook_days']);
}

// ---------- lookups ----------
function client_by_id(string $idnum): ?array {
    if ($idnum === '') return null;
    $st = db()->prepare('SELECT * FROM Client WHERE IDnum = ? LIMIT 1'); $st->execute([$idnum]);
    return $st->fetch() ?: null;
}
function find_item(string $acc): ?array {
    foreach ([['AcList', 'Book'], ['AccSM', 'Non-book']] as [$t, $kind]) {
        $st = db()->prepare("SELECT Accession, Title, Call_Number, Location, '$kind' AS Kind FROM `$t` WHERE Accession = ? LIMIT 1");
        $st->execute([$acc]);
        if ($r = $st->fetch()) return $r;
    }
    return null;
}
function open_loan(string $acc): ?array {
    $st = db()->prepare('SELECT * FROM Borrower WHERE Accession_number = ? AND Date_returned IS NULL LIMIT 1'); $st->execute([$acc]);
    return $st->fetch() ?: null;
}
function active_reservation(string $acc): ?array {
    $st = db()->prepare("SELECT * FROM Reservation WHERE Accession = ? AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= CURDATE() ORDER BY DateReserved, MFN LIMIT 1");
    $st->execute([$acc]);
    return $st->fetch() ?: null;
}
function refresh_client(string $idnum): void {   // keeps Client.Bkloan / Client.Overdue in step with the loans
    $q = db()->prepare("UPDATE Client SET
        Bkloan = (SELECT COUNT(*) FROM Borrower WHERE ID_Number = :a AND Date_returned IS NULL AND Status <> 'Lost'),
        Overdue = (SELECT COUNT(*) FROM Borrower WHERE ID_Number = :b AND Date_returned IS NULL AND Status <> 'Lost' AND Due_date < CURDATE())
        WHERE IDnum = :c");
    $q->execute(['a' => $idnum, 'b' => $idnum, 'c' => $idnum]);
}
function loan_counts(string $idnum): array {
    $st = db()->prepare("SELECT COUNT(*) AS n, SUM(Due_date < CURDATE()) AS late FROM Borrower WHERE ID_Number = ? AND Date_returned IS NULL AND Status <> 'Lost'");
    $st->execute([$idnum]);
    $r = $st->fetch();
    return [(int)$r['n'], (int)$r['late']];
}

// ---------- actions: each returns [true|false, message] ----------
function circ_checkout(array $client, string $acc): array {
    $c = circ_cfg();
    if ($acc === '') return [false, 'Enter an accession number.'];
    $item = find_item($acc);
    if (!$item) return [false, "No item found with accession number \"$acc\"."];
    $loc = (string)$item['Location'];
    foreach ($c['no_loan_keywords'] as $k) {
        if ($k !== '' && stripos($loc, $k) !== false) return [false, "\"{$item['Title']}\" is kept in $loc and cannot be borrowed."];
    }
    if ($open = open_loan($item['Accession'])) {
        return [false, $open['Status'] === 'Lost' ? 'This item is marked as lost.' : 'This item is already on loan.'];
    }
    $lp = db()->prepare("SELECT 1 FROM Borrower WHERE Accession_number = ? AND Status = 'Lost/Paid' LIMIT 1"); $lp->execute([$item['Accession']]);
    if ($lp->fetchColumn()) return [false, 'This copy was lost and already paid for, so it cannot be borrowed.'];
    [$n, $late] = loan_counts($client['IDnum']);
    if ($n >= (int)$c['max_loans']) return [false, "Loan limit reached ({$c['max_loans']} items)."];
    if ($c['block_if_overdue'] && $late > 0) return [false, 'This borrower has overdue items. Return them first.'];
    if (!empty($c['block_if_unpaid']) && ce_unpaid_total($client['IDnum']) > 0) return [false, 'This borrower has an unpaid fine of ' . money(ce_unpaid_total($client['IDnum'])) . '. Settle it first.'];
    $res = active_reservation($item['Accession']);
    if ($res && (string)$res['IDnumber'] !== (string)$client['IDnum']) {
        return [false, "Reserved for {$res['Name']} until " . fdate($res['DateExpired']) . '.'];
    }
    $due = due_date_for(loan_days_for($item['Kind'], $loc));
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO Borrower (Accession_number, Title, Date_Borrowed, Due_date, Date_returned, Overdue, Library, Status, ID_Number, Type)
                   VALUES (?, ?, NOW(), ?, NULL, 0, ?, 'Borrowed', ?, ?)")
        ->execute([$item['Accession'], $item['Title'], $due->format('Y-m-d 00:00:00'), $loc, $client['IDnum'], $item['Kind']]);
    if ($res) $pdo->prepare("UPDATE Reservation SET Claimed = 'Yes' WHERE MFN = ?")->execute([$res['MFN']]);
    $pdo->commit();
    refresh_client($client['IDnum']);
    return [true, "Borrowed: {$item['Title']}. Due " . $due->format('D, M j, Y') . '.'];
}

function circ_return(int $loanId, string $idnum, bool $finePaid): array {
    $st = db()->prepare('SELECT * FROM Borrower WHERE ID = ? AND ID_Number = ?'); $st->execute([$loanId, $idnum]);
    $loan = $st->fetch();
    if (!$loan || $loan['Date_returned'] !== null) return [false, 'That loan was not found or is already returned.'];
    $client = client_by_id($idnum);
    $late = $loan['Status'] === 'Lost' ? 0 : late_days($loan['Due_date']);
    $fine = $late * fine_rate($client['User_class'] ?? null, is_overnight($loan['Library']));
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare("UPDATE Borrower SET Date_returned = NOW(), Overdue = ?, Status = 'Returned' WHERE ID = ?")->execute([$late, $loanId]);
    if ($fine > 0 && $finePaid) {
        $pdo->prepare('INSERT INTO Cashier (ID_number, Name, DatePayment, Amount) VALUES (?, ?, CURDATE(), ?)')
            ->execute([$idnum, $client['Name'] ?? '', $fine]);
    } elseif ($fine > 0) {
        ce_assess($loan, $idnum, $late, $fine);   // not collected now: it becomes part of the borrower's balance
    }
    $pdo->commit();
    refresh_client($idnum);
    serial_sync($loan['Accession_number'] ?? '', 'return');
    $msg = "Returned: {$loan['Title']}.";
    if ($fine > 0) $msg .= " $late day(s) late, fine " . money($fine) . ($finePaid ? ' (collected).' : (ce_have_balance() ? ' (not collected, added to the borrower\'s balance).' : ' (not collected).'));
    return [true, $msg];
}

function circ_renew(int $loanId, string $idnum): array {
    $st = db()->prepare('SELECT * FROM Borrower WHERE ID = ? AND ID_Number = ? AND Date_returned IS NULL'); $st->execute([$loanId, $idnum]);
    $loan = $st->fetch();
    if (!$loan) return [false, 'Loan not found.'];
    if ($loan['Status'] === 'Lost') return [false, 'A lost item cannot be renewed.'];
    if (late_days($loan['Due_date']) > 0) return [false, 'This item is overdue. Return it instead.'];
    $res = active_reservation($loan['Accession_number']);
    if ($res && (string)$res['IDnumber'] !== (string)$idnum) return [false, "Reserved for {$res['Name']}, so it cannot be renewed."];
    $item = find_item($loan['Accession_number']);
    $kind = $item['Kind'] ?? (strncmp((string)$loan['Accession_number'], 'SER-', 4) === 0 ? 'Serial' : 'Book');
    $due = due_date_for(loan_days_for($kind, $loan['Library']));
    db()->prepare("UPDATE Borrower SET Due_date = ?, Status = 'Renewed' WHERE ID = ?")->execute([$due->format('Y-m-d 00:00:00'), $loanId]);
    refresh_client($idnum);
    serial_sync($loan['Accession_number'], 'renew', $due->format('Y-m-d 00:00:00'));
    return [true, "Renewed: {$loan['Title']}. New due date " . $due->format('D, M j, Y') . '.'];
}

function circ_lost(int $loanId, string $idnum): array {
    $a = db()->prepare('SELECT Accession_number FROM Borrower WHERE ID = ? AND ID_Number = ?'); $a->execute([$loanId, $idnum]); $acc = (string)$a->fetchColumn();
    $st = db()->prepare("UPDATE Borrower SET Status = 'Lost' WHERE ID = ? AND ID_Number = ? AND Date_returned IS NULL");
    $st->execute([$loanId, $idnum]);
    refresh_client($idnum);
    if ($st->rowCount()) serial_sync($acc, 'lost');
    return $st->rowCount() ? [true, 'Marked as lost.'] : [false, 'Loan not found.'];
}

// Settle a lost item: the borrower pays for it (amount may be 0 if it was replaced or waived), the payment goes to the Cashier list,
// and the loan is closed with the status "Lost/Paid" (that is what Inventory then reports for the copy).
function circ_settle_lost(int $loanId, string $idnum, float $amount, string $by): array {
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM Borrower WHERE ID = ? AND ID_Number = ? AND Date_returned IS NULL AND Status = 'Lost'"); $st->execute([$loanId, $idnum]);
    $loan = $st->fetch();
    if (!$loan) return [false, 'That lost item was not found (it may already be settled).'];
    if ($amount < 0 || $amount > 1000000) return [false, 'Enter the amount paid, 0 or more.'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE Borrower SET Status = 'Lost/Paid', Date_returned = NOW() WHERE ID = ?")->execute([$loanId]);
        if ($amount > 0) {
            $c = client_by_id($idnum);
            $pdo->prepare('INSERT INTO Cashier (ID_number, Name, DatePayment, Amount, Note) VALUES (?,?,?,?,?)')
                ->execute([$idnum, $c['Name'] ?? null, date('Y-m-d'), round($amount, 2), substr('Lost item settled: ' . $loan['Accession_number'] . ($by !== '' ? " (by $by)" : ''), 0, 120)]);
        }
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    refresh_client($idnum);
    return [true, 'Settled: ' . $loan['Title'] . ($amount > 0 ? ' (' . money($amount) . ' collected)' : '') . '. Marked Lost/Paid.'];
}

// ---------- serial issues ----------
// A serial issue is loaned like any other item: a row in Borrower with accession "SER-<SerialsTemp.IDD>".
// These keep the issue record (SerialsTemp) and the old history table (TempSerialTransaction) in step when the loan changes.
function serial_sync(string $acc, string $event, ?string $due = null): void {
    if (strncmp($acc, 'SER-', 4) !== 0 || !($idd = (int)substr($acc, 4))) return;
    $pdo = db();
    try {
        if ($event === 'return') {
            $pdo->prepare('UPDATE SerialsTemp SET Status = 0, DateIn = NOW() WHERE IDD = ?')->execute([$idd]);
            $pdo->prepare('UPDATE TempSerialTransaction SET DateIn = NOW() WHERE IDD = ? AND DateIn IS NULL')->execute([$idd]);
        } elseif ($event === 'lost') {
            $pdo->prepare('UPDATE SerialsTemp SET Status = 2 WHERE IDD = ?')->execute([$idd]);
        } elseif ($event === 'renew' && $due) {
            $pdo->prepare('UPDATE SerialsTemp SET DateDue = ? WHERE IDD = ?')->execute([$due, $idd]);
            $pdo->prepare('UPDATE TempSerialTransaction SET DateDue = ? WHERE IDD = ? AND DateIn IS NULL')->execute([$due, $idd]);
        }
    } catch (PDOException $ex) { /* the serial tables have not been updated yet (05_serials.sql); the loan itself is already saved */ }
}

require_once __DIR__ . '/circ_extra.php';   // fine balance, holds, history, photo helpers
