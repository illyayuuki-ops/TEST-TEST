<?php
// Helpers for Equipment circulation (check-out / check-in). Loans live in the equipment_loan table.

const EQ_MAX_HOURS = 72;
const EQ_DEFAULT_HOURS = 3;

function eq_available_sql(): string { return "(status IS NULL OR status = '' OR status = 'Available')"; }

// Equipment that can be lent right now (for the pick-from-list dropdown).
function eq_available_items(): array {
    return db()->query('SELECT eqID, equipment, SN, location FROM Equipment WHERE equipment IS NOT NULL AND equipment <> \'\' AND ' . eq_available_sql() . ' ORDER BY equipment, SN')->fetchAll();
}

function eq_label(array $r): string {
    return $r['equipment'] . (($r['SN'] ?? '') !== '' ? ' (' . $r['SN'] . ')' : '');
}

// Find equipment by scanned serial number (exact match, ignoring case and spaces around it).
function eq_by_sn(string $sn): ?array {
    $st = db()->prepare('SELECT * FROM Equipment WHERE SN IS NOT NULL AND SN <> \'\' AND LOWER(TRIM(SN)) = LOWER(?) LIMIT 1');
    $st->execute([trim($sn)]);
    return $st->fetch() ?: null;
}

function eq_by_id(int $id): ?array {
    $st = db()->prepare('SELECT * FROM Equipment WHERE eqID = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}

// The borrower's unreturned equipment loan, if any (limit: one item at a time). Lost items do not count.
function eq_open_loan_for(string $idnum): ?array {
    $st = db()->prepare("SELECT * FROM equipment_loan WHERE ID_Number = ? AND Status = 'Out' ORDER BY ID LIMIT 1");
    $st->execute([$idnum]);
    return $st->fetch() ?: null;
}

function eq_loan_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM equipment_loan WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && $r['Library'] !== user_library()) return null;   // a library-bound user only handles that library's loans
    return $r;
}

function eq_is_overdue(array $loan, ?string $now = null): bool {
    return $loan['Status'] === 'Out' && strtotime($loan['Due_at']) < strtotime($now ?? date('Y-m-d H:i:s'));
}

// "2 h 15 min late" / "due in 1 h 5 min"
function eq_time_note(array $loan): string {
    if ($loan['Status'] !== 'Out') return '';
    $diff = strtotime($loan['Due_at']) - time();
    $abs = abs($diff); $h = intdiv($abs, 3600); $m = intdiv($abs % 3600, 60);
    $txt = ($h ? "$h h " : '') . "$m min";
    return $diff < 0 ? "$txt late" : "due in $txt";
}

// Check out. Returns [ok(bool), message]. Equipment.status flips to 'Out' only if it was still available (guards against two staff lending the same item).
function eq_checkout(string $idnum, ?array $item, int $hours, string $library): array {
    $pdo = db();
    $client = client_by_id($idnum);
    if (!$client) return [false, "Borrower ID \"$idnum\" is not registered. Add the borrower first under Borrowers."];
    if (!$item) return [false, 'Equipment not found. Scan a serial number or pick one from the list.'];
    if ($hours < 1 || $hours > EQ_MAX_HOURS) return [false, 'Number of hours must be between 1 and ' . EQ_MAX_HOURS . '.'];
    $open = eq_open_loan_for($idnum);
    if ($open) return [false, "{$client['Name']} still has {$open['Equipment']}" . ($open['SN'] ? " ({$open['SN']})" : '') . ' out (since ' . date('M j, g:i A', strtotime($open['Date_out'])) . '). Check it in first; one item at a time.'];

    $now = date('Y-m-d H:i:s'); $due = date('Y-m-d H:i:s', strtotime("+$hours hours"));
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare('UPDATE Equipment SET status = \'Out\' WHERE eqID = ? AND ' . eq_available_sql());
        $u->execute([$item['eqID']]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return [false, eq_label($item) . ' is not available (status: ' . ($item['status'] ?: 'unknown') . ').']; }
        $pdo->prepare('INSERT INTO equipment_loan (eqID, Equipment, SN, ID_Number, Borrower_name, Library, Hours, Date_out, Due_at, Status, Encoded_by) VALUES (?,?,?,?,?,?,?,?,?,\'Out\',?)')
            ->execute([$item['eqID'], $item['equipment'], ($item['SN'] ?? '') !== '' ? $item['SN'] : null, $idnum, $client['Name'], $library, $hours, $now, $due, $_SESSION['user'] ?? '']);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    return [true, eq_label($item) . " lent to {$client['Name']} for $hours hour" . ($hours === 1 ? '' : 's') . ', due ' . date('g:i A', strtotime($due)) . ' (' . date('M j', strtotime($due)) . ').'];
}

// Check in (also works for an item that was marked lost and then found).
function eq_checkin(array $loan): array {
    if ($loan['Status'] === 'Returned') return [false, 'This loan is already returned.'];
    $pdo = db(); $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE equipment_loan SET Status = \'Returned\', Returned_at = ?, Received_by = ? WHERE ID = ?')->execute([$now, $_SESSION['user'] ?? '', $loan['ID']]);
        $pdo->prepare('UPDATE Equipment SET status = \'Available\' WHERE eqID = ?')->execute([$loan['eqID']]);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    $late = $loan['Status'] === 'Out' && strtotime($loan['Due_at']) < strtotime($now);
    $fineNote = '';
    if ($late) {
        // Late hours are rounded up (1 minute late = 1 hour). The fine goes onto the borrower's balance (Balance & payments), where it is paid like any other fine.
        $lateHours = (int)ceil((strtotime($now) - strtotime($loan['Due_at'])) / 3600);
        $rate = (float)(($GLOBALS['CONFIG']['circulation']['equipment_fine_per_hour'] ?? 5));
        $amount = round($lateHours * $rate, 2);
        if ($amount > 0 && function_exists('ce_have_balance') && ce_have_balance()) {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM fine_balance WHERE Note = ?'); $dup->execute(['Equipment loan #' . $loan['ID']]);
            if (!(int)$dup->fetchColumn()) {
                $pdo->prepare("INSERT INTO fine_balance (ID_Number, Loan_ID, Accession, Title, Days_late, Amount, Paid, Status, Assessed, Library, Note)
                               VALUES (?, NULL, ?, ?, ?, ?, 0, 'Unpaid', ?, ?, ?)")
                    ->execute([$loan['ID_Number'], $loan['SN'] ?? null, $loan['Equipment'], (int)ceil($lateHours / 24), $amount, date('Y-m-d'), $loan['Library'], 'Equipment loan #' . $loan['ID']]);
                $fineNote = ' Fine: ' . ($GLOBALS['CONFIG']['circulation']['currency'] ?? '₱') . number_format($amount, 2) . " for $lateHours late hour" . ($lateHours === 1 ? '' : 's') . ' (added to the borrower\'s balance).';
            }
        }
    }
    return [true, $loan['Equipment'] . ' checked in' . ($late ? ' (it was overdue)' : '') . '.' . $fineNote];
}

function eq_mark_lost(array $loan): array {
    if ($loan['Status'] !== 'Out') return [false, 'Only an item that is out can be marked lost.'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE equipment_loan SET Status = \'Lost\' WHERE ID = ?')->execute([$loan['ID']]);
        $pdo->prepare('UPDATE Equipment SET status = \'Lost\' WHERE eqID = ?')->execute([$loan['eqID']]);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    return [true, $loan['Equipment'] . ' marked as lost. If it turns up, use Check in to put it back in service.'];
}
