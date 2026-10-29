<?php
// Circulation extras: fine balance and payments, estimate, holds, history, daily lists, borrower photos.
// Built on the existing tables (Client, Borrower, Reservation, Cashier). New: fine_balance (09_circulation_extras.sql).
// Written for PHP 7.2+ and kept free of MySQL-only SQL where possible.

// ---------- library restriction (Borrower.Library holds the item's location, e.g. "College Library - Circulation") ----------
function ce_lib_where(string $col, array &$args, string $chosen = '', string $p = 'cl'): string {
    if (!lib_all()) { $args[$p] = user_library() . '%'; return " AND $col LIKE :$p"; }
    if ($chosen !== '' && in_array($chosen, libraries(), true)) { $args[$p] = $chosen . '%'; return " AND $col LIKE :$p"; }
    return '';
}
function ce_lib_picker(string $chosen): string {
    if (!lib_all()) return '';
    $h = '<select name="lib" aria-label="Library"><option value="">All libraries</option>';
    foreach (libraries() as $l) $h .= '<option' . ($l === $chosen ? ' selected' : '') . '>' . e($l) . '</option>';
    return $h . '</select>';
}

// ---------- tables ready? ----------
function ce_have_balance(): bool {
    static $ok = null;
    if ($ok === null) { try { db()->query('SELECT 1 FROM fine_balance LIMIT 1'); $ok = true; } catch (PDOException $ex) { $ok = false; } }
    return $ok;
}
function ce_need_sql(string $title): void {
    include __DIR__ . '/header.php';
    echo '<h1>' . e($title) . '</h1><div class="card"><p>The fine balance table is missing. Import <code>09_circulation_extras.sql</code> in phpMyAdmin, then reload this page.</p></div>';
    include __DIR__ . '/footer.php'; exit;
}
function ce_has_hold_kind(): bool {
    static $ok = null;
    if ($ok === null) { try { db()->query('SELECT Kind FROM Reservation LIMIT 1'); $ok = true; } catch (PDOException $ex) { $ok = false; } }
    return $ok;
}

function ce_date(?string $s, string $default = ''): string {   // a valid Y-m-d or the default
    $s = trim((string)$s);
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : $default;
}
function ce_find_clients(string $q): array {
    $st = db()->prepare('SELECT IDnum, Name, User_class FROM Client WHERE Name LIKE ? OR IDnum LIKE ? ORDER BY Name LIMIT 20');
    $st->execute(["%$q%", "%$q%"]);
    return $st->fetchAll();
}

// ---------- fine balance ----------
// A fine that is not collected at return becomes a line in fine_balance. Payments go to Cashier (as before) and reduce the balance, oldest first.
function ce_assess(array $loan, string $idnum, int $late, float $amount): void {
    if ($amount <= 0 || !ce_have_balance()) return;
    $pdo = db();
    $x = $pdo->prepare('SELECT COUNT(*) FROM fine_balance WHERE Loan_ID = ?'); $x->execute([(int)$loan['ID']]);
    if ((int)$x->fetchColumn() > 0) return;   // never assess the same loan twice
    $pdo->prepare("INSERT INTO fine_balance (ID_Number, Loan_ID, Accession, Title, Days_late, Amount, Paid, Status, Assessed, Library)
                   VALUES (?, ?, ?, ?, ?, ?, 0, 'Unpaid', ?, ?)")
        ->execute([$idnum, (int)$loan['ID'], $loan['Accession_number'], $loan['Title'], $late, round($amount, 2), date('Y-m-d'), $loan['Library']]);
}
function ce_unpaid_rows(string $idnum): array {
    if (!ce_have_balance()) return [];
    $st = db()->prepare("SELECT * FROM fine_balance WHERE ID_Number = ? AND Status = 'Unpaid' ORDER BY Assessed, ID");
    $st->execute([$idnum]);
    return $st->fetchAll();
}
function ce_unpaid_total(string $idnum): float {
    $t = 0.0;
    foreach (ce_unpaid_rows($idnum) as $r) $t += (float)$r['Amount'] - (float)$r['Paid'];
    return round($t, 2);
}
function ce_pay(string $idnum, float $amount, string $by = ''): array {
    $amount = round($amount, 2);
    $client = client_by_id($idnum);
    if (!$client) return [false, 'Borrower not found.'];
    $rows = ce_unpaid_rows($idnum);
    $total = ce_unpaid_total($idnum);
    if (!$rows || $total <= 0) return [false, 'This borrower has no unpaid fines.'];
    if ($amount <= 0) return [false, 'Enter an amount above zero.'];
    if ($amount > $total + 0.004) return [false, 'That is more than the balance of ' . money($total) . '.'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        $left = $amount; $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            if ($left < 0.005) break;
            $rem = round((float)$r['Amount'] - (float)$r['Paid'], 2);
            $apply = min($rem, $left);
            $paid = round((float)$r['Paid'] + $apply, 2);
            $done = ($rem - $apply) < 0.005;
            $pdo->prepare('UPDATE fine_balance SET Paid = ?, Status = ?, Settled = ? WHERE ID = ?')
                ->execute([$paid, $done ? 'Paid' : 'Unpaid', $done ? $now : null, (int)$r['ID']]);
            $left = round($left - $apply, 2);
        }
        $pdo->prepare('INSERT INTO Cashier (ID_number, Name, DatePayment, Amount, Note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idnum, $client['Name'] ?? '', date('Y-m-d'), $amount, 'Fine payment' . ($by !== '' ? " (received by $by)" : '')]);
        $pdo->commit();
    } catch (Exception $ex) { $pdo->rollBack(); return [false, 'Payment not saved: ' . $ex->getMessage()]; }
    $rest = ce_unpaid_total($idnum);
    return [true, 'Received ' . money($amount) . ($rest > 0 ? '. Balance left: ' . money($rest) . '.' : '. Balance cleared.')];
}
function ce_waive(int $fineId, string $by): array {
    if (!can_edit_records()) return [false, 'Only the admin or a librarian can waive a fine.'];
    $st = db()->prepare("UPDATE fine_balance SET Status = 'Waived', Settled = ?, Note = ? WHERE ID = ? AND Status = 'Unpaid'");
    $st->execute([date('Y-m-d H:i:s'), 'Waived by ' . $by, $fineId]);
    return $st->rowCount() ? [true, 'Fine waived.'] : [false, 'That fine was not found or is already settled.'];
}
function ce_loan_fine_map(string $idnum): array {   // Loan_ID => fine_balance row, for the history screens
    if (!ce_have_balance()) return [];
    $st = db()->prepare('SELECT * FROM fine_balance WHERE ID_Number = ?'); $st->execute([$idnum]);
    $m = []; foreach ($st->fetchAll() as $r) if ($r['Loan_ID'] !== null) $m[(int)$r['Loan_ID']] = $r;
    return $m;
}

// ---------- one loan: days late, fine, status text ----------
// $asOf lets the estimate screen ask "what if it is returned on this date?"
function ce_loan_calc(array $l, ?string $userClass, ?string $asOf = null): array {
    $open = $l['Date_returned'] === null; $lost = $l['Status'] === 'Lost';
    if ($lost) $late = 0;
    elseif ($open) $late = late_days($l['Due_date'], $asOf);
    else $late = (int)$l['Overdue'];
    $rate = fine_rate($userClass, is_overnight($l['Library']));
    return ['late' => $late, 'rate' => $rate, 'fine' => round($late * $rate, 2), 'open' => $open, 'lost' => $lost];
}
function ce_loan_state(array $l, array $calc, array $fineMap): string {
    if ($calc['lost']) return 'Lost';
    if ($calc['open']) return $calc['late'] > 0 ? 'Overdue' : 'On loan';
    if ($calc['fine'] <= 0) return 'Returned';
    $f = $fineMap[(int)$l['ID']] ?? null;
    if (!$f) return 'Returned, fine collected';
    if ($f['Status'] === 'Unpaid') return 'Fine unpaid ' . money((float)$f['Amount'] - (float)$f['Paid']);
    if ($f['Status'] === 'Waived') return 'Returned, fine waived';
    return 'Returned, fine paid';
}

// ---------- holds: keep an available item on the shelf for one borrower until a set day ----------
// Stored in Reservation with Kind = 'Hold'. The existing borrow rule already stops anyone else from borrowing a held item.
function ce_hold_days(): int { return max(1, (int)(circ_cfg()['hold_days'] ?? 1)); }
function ce_place_hold(array $client, string $acc): array {
    $acc = trim($acc);
    if ($acc === '') return [false, 'Enter an accession number.'];
    $item = find_item($acc);
    if (!$item) return [false, "No item found with accession number \"$acc\"."];
    $loc = (string)$item['Location'];
    foreach (circ_cfg()['no_loan_keywords'] as $k) {
        if ($k !== '' && stripos($loc, $k) !== false) return [false, "\"{$item['Title']}\" is kept in $loc and cannot be borrowed, so it cannot be held."];
    }
    if ($open = open_loan($item['Accession'])) {
        return [false, ($open['Status'] === 'Lost' ? 'This item is marked as lost.' : 'This item is on loan right now. Use Reservations to queue for it.')];
    }
    if ($ex = active_reservation($item['Accession'])) {
        return [false, 'Already ' . (($ex['Kind'] ?? 'Reserve') === 'Hold' ? 'held' : 'reserved') . " for {$ex['Name']} until " . fdate($ex['DateExpired']) . '.'];
    }
    $max = (int)circ_cfg()['max_loans'];
    $st = db()->prepare("SELECT COUNT(*) FROM Reservation WHERE IDnumber = ? AND Kind = 'Hold' AND (Claimed IS NULL OR Claimed <> 'Yes') AND DateExpired >= ?");
    $st->execute([$client['IDnum'], date('Y-m-d')]);
    if ((int)$st->fetchColumn() >= $max) return [false, "This borrower already has $max items on hold."];
    $until = due_date_for(ce_hold_days())->format('Y-m-d');
    db()->prepare("INSERT INTO Reservation (IDnumber, Name, Accession, Title, Call_number, Type, DateReserved, DateExpired, Claimed, Kind)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'No', 'Hold')")
        ->execute([$client['IDnum'], $client['Name'], $item['Accession'], $item['Title'], $item['Call_Number'], substr($item['Kind'], 0, 10), date('Y-m-d'), $until]);
    return [true, "Holding \"{$item['Title']}\" for {$client['Name']} until " . fdate($until) . '.'];
}

// ---------- borrower photo (file on disk, no database column) ----------
const CE_PHOTO_DIR = 'assets/img/borrowers';
function ce_photo_name(string $idnum): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $idnum) . '-' . substr(md5($idnum), 0, 6) . '.jpg';
}
function ce_photo_path(string $idnum): string { return dirname(__DIR__) . '/' . CE_PHOTO_DIR . '/' . ce_photo_name($idnum); }
function ce_photo_url(string $idnum): ?string {
    $p = ce_photo_path($idnum);
    return is_file($p) ? CE_PHOTO_DIR . '/' . ce_photo_name($idnum) . '?v=' . filemtime($p) : null;
}
function ce_photo_save(string $idnum, array $f): array {
    if (empty($f['tmp_name']) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'That photo is too large.' : 'Choose a photo first.'];
    }
    if ($f['size'] > 3 * 1024 * 1024) return [false, 'The photo is over 3 MB. Use a smaller one.'];
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return [false, 'Only JPG or PNG photos are accepted.'];
    $dir = dirname(__DIR__) . '/' . CE_PHOTO_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [false, 'The photo folder cannot be created. Check folder permissions.'];
    $dest = ce_photo_path($idnum);
    if (function_exists('imagecreatefromjpeg')) {   // re-encode: strips anything that is not pixels and keeps the file small
        $src = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($f['tmp_name']) : @imagecreatefromjpeg($f['tmp_name']);
        if (!$src) return [false, 'That image could not be read.'];
        $w = imagesx($src); $h = imagesy($src); $s = min(1, 400 / max($w, $h));
        $nw = max(1, (int)round($w * $s)); $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $ok = imagejpeg($dst, $dest, 85); imagedestroy($src); imagedestroy($dst);
    } elseif ($info[2] === IMAGETYPE_JPEG) {
        $ok = move_uploaded_file($f['tmp_name'], $dest);
    } else return [false, 'This server can only accept JPG photos.'];
    return $ok ? [true, 'Photo saved.'] : [false, 'The photo could not be saved. Check folder permissions.'];
}
function ce_photo_remove(string $idnum): void { $p = ce_photo_path($idnum); if (is_file($p)) @unlink($p); }

// ---------- overdue notice block (used for single, batch and print-all) ----------
function ce_notice_text(): array {
    $read = function ($f) { $p = dirname(__DIR__) . '/data/lookups/' . $f; return is_file($p) ? trim(file_get_contents($p)) : ''; };
    $sign = trim(preg_split('/[;\r\n]+/', $read('Signatory.txt'))[0] ?? '');
    return [$read('Notice.txt'), $sign];
}
