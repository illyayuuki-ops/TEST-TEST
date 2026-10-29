<?php
// Helpers for Transaction Monitoring: lockers, internet computers and discussion rooms.
// Time in / time due / penalty. Items live in txn_item, rates in txn_rate, every use in txn_log.

const TXN_TYPES = ['Locker' => 'Locker', 'Internet' => 'Internet', 'Discussion' => 'Discussion room'];
const TXN_MAX_HOURS = 24;

function txn_rates(): array {
    $out = [];
    foreach (db()->query('SELECT * FROM txn_rate')->fetchAll() as $r) $out[$r['Type']] = $r;
    foreach (array_keys(TXN_TYPES) as $t) $out[$t] = $out[$t] ?? ['Type' => $t, 'Rate_per_hour' => '5.00', 'Default_hours' => 1];
    return $out;
}

// Late hours are rounded up: 1 minute late = 1 hour. Penalty = late hours x the type's rate.
function txn_late_hours(string $due, ?string $at = null): int {
    $diff = strtotime($at ?? date('Y-m-d H:i:s')) - strtotime($due);
    return $diff > 0 ? (int)ceil($diff / 3600) : 0;
}

// "Locker 1" stays "Locker 1"; "Room A" becomes "Discussion room Room A".
function txn_title(string $type, string $name): string {
    return (stripos($name, $type) === 0 || stripos($name, TXN_TYPES[$type]) === 0) ? $name : TXN_TYPES[$type] . ' ' . $name;
}

function txn_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM txn_log WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && $r['Library'] !== user_library()) return null;
    return $r;
}

function txn_item_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM txn_item WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && $r['Library'] !== user_library()) return null;
    return $r;
}

// Active items that are free right now, for the check-out dropdown, grouped by type.
function txn_free_items(): array {
    $sql = "SELECT i.* FROM txn_item i WHERE i.Active = 1 AND NOT EXISTS (SELECT 1 FROM txn_log l WHERE l.ItemID = i.ID AND l.Status = 'In use')";
    $args = [];
    if (!lib_all()) { $sql .= ' AND i.Library = ?'; $args[] = user_library(); }
    $st = db()->prepare($sql . ' ORDER BY i.Type, LENGTH(i.Name), i.Name'); $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['Type']][] = $r;
    return $out;
}

// Check out. The item row is locked so two staff cannot give the same locker to two people.
function txn_checkout(string $idnum, int $itemId, int $hours): array {
    $pdo = db();
    $client = client_by_id($idnum);
    if (!$client) return [false, "Borrower ID \"$idnum\" is not registered. Add the borrower first under Borrowers."];
    if ($hours < 1 || $hours > TXN_MAX_HOURS) return [false, 'Number of hours must be between 1 and ' . TXN_MAX_HOURS . '.'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM txn_item WHERE ID = ? FOR UPDATE'); $st->execute([$itemId]); $item = $st->fetch();
        if (!$item || !$item['Active'] || (!lib_all() && $item['Library'] !== user_library())) { $pdo->rollBack(); return [false, 'Pick an item from the list.']; }
        $st = $pdo->prepare("SELECT Borrower_name, Time_in FROM txn_log WHERE ItemID = ? AND Status = 'In use' LIMIT 1"); $st->execute([$itemId]); $busy = $st->fetch();
        if ($busy) { $pdo->rollBack(); return [false, txn_title($item['Type'], $item['Name']) . " is already in use by {$busy['Borrower_name']} (since " . date('g:i A', strtotime($busy['Time_in'])) . ').']; }
        $now = date('Y-m-d H:i:s'); $due = date('Y-m-d H:i:s', strtotime("+$hours hours"));
        $pdo->prepare('INSERT INTO txn_log (Type, ItemID, Item_name, ID_Number, Borrower_name, Library, Hours, Time_in, Due_at, Status, Encoded_by) VALUES (?,?,?,?,?,?,?,?,?,\'In use\',?)')
            ->execute([$item['Type'], $item['ID'], $item['Name'], $idnum, $client['Name'], $item['Library'], $hours, $now, $due, $_SESSION['user'] ?? '']);
        $pdo->commit();
    } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
    return [true, txn_title($item['Type'], $item['Name']) . " given to {$client['Name']} for $hours hour" . ($hours === 1 ? '' : 's') . ', due ' . date('g:i A', strtotime($due)) . '.'];
}

// Check in: works out late hours and the penalty at the type's current rate.
function txn_checkin(array $row, bool $paid): array {
    if ($row['Status'] !== 'In use') return [false, 'This one is already checked in.'];
    $rate = (float)(txn_rates()[$row['Type']]['Rate_per_hour'] ?? 0);
    $now = date('Y-m-d H:i:s'); $late = txn_late_hours($row['Due_at'], $now); $pen = round($late * $rate, 2);
    db()->prepare("UPDATE txn_log SET Status = 'Returned', Time_out = ?, Late_hours = ?, Penalty = ?, Paid = ?, Received_by = ? WHERE ID = ? AND Status = 'In use'")
        ->execute([$now, $late, $pen, $pen > 0 && $paid ? 1 : 0, $_SESSION['user'] ?? '', $row['ID']]);
    $msg = txn_title($row['Type'], $row['Item_name']) . ' checked in';
    if ($late) $msg .= ": $late hour" . ($late === 1 ? '' : 's') . ' late, penalty ' . money($pen) . ($paid ? ' (paid)' : ' (not yet paid)');
    return [true, $msg . '.'];
}

function txn_time_note(array $row): string {
    if ($row['Status'] !== 'In use') return '';
    $diff = strtotime($row['Due_at']) - time(); $abs = abs($diff);
    $txt = (intdiv($abs, 3600) ? intdiv($abs, 3600) . ' h ' : '') . intdiv($abs % 3600, 60) . ' min';
    return $diff < 0 ? "$txt late" : "due in $txt";
}
