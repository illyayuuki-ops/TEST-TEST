<?php
// Helpers for Inventory. A "count" covers one kind of material (Books, Audio-Visual, Researches, Serials) in one library.
// Scanning an accession number records what the system says about that item: On-shelf, Out, Lost, Missing or Not listed.
// Closing a count marks every item that should have been on the shelf but was never scanned as Missing.

const INV_KINDS = ['Books', 'Audio-Visual', 'Researches', 'Serials'];
const INV_STATUSES = ['On-shelf', 'Out', 'Lost', 'Lost/Paid', 'I-Loan', 'Missing', 'Not listed'];
// Non-book rows whose Type matches this are treated as Researches; every other non-book row is Audio-Visual.
const INV_RESEARCH_RX = 'thesis|theses|special problem|research|dissertation|feasibility|capstone';

// Description of where each kind lives. 'from' is the FROM clause, columns are aliased the same way for every kind.
function inv_src(string $kind): array {
    switch ($kind) {
        case 'Books':
            return ['from' => 'AcList i', 'acc' => 'i.Accession', 'title' => 'i.Title', 'call' => 'i.Call_Number', 'id' => 'i.ID',
                    'stat' => 'i.Status', 'where' => '1=1', 'libcol' => 'i.Location', 'libmode' => 'prefix'];
        case 'Audio-Visual':
            return ['from' => 'AccSM i', 'acc' => 'i.Accession', 'title' => 'i.Title', 'call' => 'i.Call_Number', 'id' => 'i.ID',
                    'stat' => 'i.Status', 'where' => '(i.Type IS NULL OR i.Type NOT REGEXP \'' . INV_RESEARCH_RX . '\')', 'libcol' => null, 'libmode' => ''];
        case 'Researches':   // academic research records of the Files module (older databases without that table fall back to non-book rows)
            if (function_exists('table_exists') && table_exists('file_record'))
                return ['from' => 'file_record i', 'acc' => 'i.Accession', 'title' => 'i.Title', 'call' => 'i.Call_Number', 'id' => 'i.ID',
                        'stat' => 'i.Status', 'where' => "i.Type = 'Academic research'", 'libcol' => 'i.Library', 'libmode' => 'exact'];
            return ['from' => 'AccSM i', 'acc' => 'i.Accession', 'title' => 'i.Title', 'call' => 'i.Call_Number', 'id' => 'i.ID',
                    'stat' => 'i.Status', 'where' => 'i.Type REGEXP \'' . INV_RESEARCH_RX . '\'', 'libcol' => null, 'libmode' => ''];
        default: // Serials: one row per received issue; accession is SER-<issue id>
            return ['from' => 'SerialsTemp i JOIN Serial_TitleTemp t ON t.Code = i.Code', 'acc' => "CONCAT('SER-', i.IDD)",
                    'title' => "CONCAT(t.Serial_Title, IF(i.Volume IS NULL OR i.Volume = '', '', CONCAT(', Vol. ', i.Volume)), IF(i.Number IS NULL OR i.Number = '', '', CONCAT(' No. ', i.Number)))",
                    'call' => 'NULL', 'id' => 'i.IDD', 'stat' => 'i.Status', 'where' => 'i.Date_received IS NOT NULL', 'libcol' => 't.Library', 'libmode' => 'exact'];
    }
}

// Library part of the WHERE for one kind. Audio-Visual and Researches have no library column, so every library counts them together.
function inv_lib_sql(string $kind, string $library, array &$args, string $p = 'lb'): string {
    $s = inv_src($kind);
    if ($library === '' || !$s['libcol']) return '';
    if ($s['libmode'] === 'prefix') { $args[$p] = $library . '%'; return " AND {$s['libcol']} LIKE :$p"; }
    $args[$p] = $library; return " AND {$s['libcol']} = :$p";
}

// Libraries a user may count: their own, or any when they belong to All libraries. '' means "the whole collection".
function inv_allowed_library(string $library): bool {
    if (!lib_all()) return $library === user_library();
    return $library === '' || in_array($library, libraries(), true);
}

function inv_count(int $id): ?array {
    $st = db()->prepare('SELECT * FROM inv_count WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && $r['Library'] !== user_library()) return null;
    return $r;
}

// Counts the user may see, newest first.
function inv_counts(bool $openOnly = false): array {
    $where = ['1=1']; $args = [];
    if (!lib_all()) { $where[] = 'Library = :l'; $args['l'] = user_library(); }
    if ($openOnly) $where[] = 'Closed_at IS NULL';
    $st = db()->prepare('SELECT * FROM inv_count WHERE ' . implode(' AND ', $where) . ' ORDER BY ID DESC LIMIT 200');
    $st->execute($args);
    return $st->fetchAll();
}

function inv_summary(int $countId): array {
    $out = array_fill_keys(INV_STATUSES, 0);
    $st = db()->prepare('SELECT Status, COUNT(*) n FROM inv_scan WHERE Count_ID = ? GROUP BY Status'); $st->execute([$countId]);
    foreach ($st->fetchAll() as $r) $out[$r['Status']] = (int)$r['n'];
    return $out;
}

// Cut text to a length (works with or without the mbstring extension).
function inv_cut(?string $v, int $n): ?string {
    if ($v === null) return null;
    if (function_exists('mb_substr')) return mb_substr($v, 0, $n);
    return strlen($v) > $n ? rtrim(substr($v, 0, $n), "\x80..\xBF") : $v;
}

// Find the scanned accession number in the catalog. Returns the item row (acc, title, call, stat, id) or null.
function inv_find(string $kind, string $library, string $acc): ?array {
    $s = inv_src($kind); $args = ['acc' => $acc];
    $sql = "SELECT {$s['acc']} AS acc, {$s['title']} AS title, {$s['call']} AS callno, {$s['id']} AS item_id, {$s['stat']} AS stat
            FROM {$s['from']} WHERE {$s['where']} AND {$s['acc']} = :acc" . inv_lib_sql($kind, $library, $args) . ' LIMIT 1';
    $st = db()->prepare($sql); $st->execute($args);
    return $st->fetch() ?: null;
}

// Does the accession exist in another kind (or outside this library)? Used to explain a scan that does not belong in this count.
function inv_elsewhere(string $kind, string $library, string $acc): string {
    foreach (INV_KINDS as $k) {
        $s = inv_src($k); $args = ['acc' => $acc];
        $st = db()->prepare("SELECT 1 FROM {$s['from']} WHERE {$s['where']} AND {$s['acc']} = :acc LIMIT 1"); $st->execute($args);
        if (!$st->fetchColumn()) continue;
        if ($k !== $kind) return "That accession number belongs to $k, not $kind. Record it in a count for $k instead.";
        return 'That item belongs to another library, so it is not part of this count.';
    }
    return '';
}

// What the system says about the item: [status, note]. Loans (Borrower) win over the catalog status.
function inv_status(string $kind, array $item): array {
    if ($kind === 'Serials') {
        $st = (int)$item['stat'];
        if ($st === 2) return ['Lost', 'Marked lost'];
        if ($st === 1) return ['Out', 'Scanned on the shelf but recorded as on loan'];
        return ['On-shelf', ''];
    }
    $q = db()->prepare('SELECT Status FROM Borrower WHERE Accession_number = ? AND Date_returned IS NULL LIMIT 1'); $q->execute([$item['acc']]);
    $loan = $q->fetchColumn();
    if ($loan !== false) return $loan === 'Lost' ? ['Lost', 'Loan marked lost'] : ['Out', 'Scanned on the shelf but recorded as on loan'];
    $lp = db()->prepare("SELECT 1 FROM Borrower WHERE Accession_number = ? AND Status = 'Lost/Paid' LIMIT 1"); $lp->execute([$item['acc']]);
    if ($lp->fetchColumn()) return ['Lost/Paid', 'Lost item was paid for'];
    $cat = strtolower(trim((string)$item['stat']));
    if ($cat === 'i-loan' || $cat === 'inter-library loan') return ['I-Loan', 'On inter-library loan'];
    if ($cat === 'lost/paid') return ['Lost/Paid', 'Catalog says lost and paid'];
    if ($cat === 'lost') return ['Lost', 'Catalog says lost'];
    if ($cat === 'missing') return ['Missing', 'Catalog says missing'];
    if ($cat === 'out') return ['Out', 'Catalog says out'];
    return ['On-shelf', ''];
}

// Record one scan. Returns [ok, message, status|null].
function inv_scan(array $count, string $acc): array {
    if ($count['Closed_at'] !== null) return [false, 'This count is closed.', null];
    $acc = trim($acc);
    if ($acc === '') return [false, 'Scan or type an accession number.', null];
    if (strlen($acc) > 60) return [false, 'That accession number is too long.', null];
    $kind = $count['Kind']; $lib = $count['Library'];
    $dup = db()->prepare('SELECT Scanned_at, Status FROM inv_scan WHERE Count_ID = ? AND Accession = ? LIMIT 1'); $dup->execute([$count['ID'], $acc]);
    if ($d = $dup->fetch()) return [false, "Already scanned at " . date('g:i A', strtotime($d['Scanned_at'])) . " ($acc: {$d['Status']}).", null];
    $item = inv_find($kind, $lib, $acc);
    if ($item) {
        $acc = (string)$item['acc'];   // use the catalog spelling (case, spaces)
        [$status, $note] = inv_status($kind, $item);
        $title = (string)$item['title']; $call = (string)$item['callno'];
    } else {
        $why = inv_elsewhere($kind, $lib, $acc);
        if ($why !== '') return [false, $why, null];
        [$status, $note, $title, $call] = ['Not listed', 'Not in the catalog', null, null];
    }
    try {
        db()->prepare('INSERT INTO inv_scan (Count_ID, Accession, Title, Call_Number, Status, Note, Scanned_at, Scanned_by) VALUES (?,?,?,?,?,?,NOW(),?)')
            ->execute([$count['ID'], $acc, inv_cut($title, 255), inv_cut($call, 80), $status, $note ?: null, $_SESSION['user'] ?? '']);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') return [false, 'Already scanned in this count.', null];   // two scanners hit the same item together
        throw $ex;
    }
    return [true, ($title !== null && $title !== '' ? shorten($title, 70) . ' - ' : '') . "$acc: $status" . ($note ? " ($note)" : ''), $status];
}

// SQL that selects the items of a count that have no scan yet (used for "Not yet scanned" and for closing).
function inv_unscanned_sql(array $count, array &$args, bool $shouldBeHere): string {
    $kind = $count['Kind']; $s = inv_src($kind);
    $args['cid'] = $count['ID'];
    $sql = "FROM {$s['from']} WHERE {$s['where']}" . inv_lib_sql($kind, $count['Library'], $args) . "
            AND NOT EXISTS (SELECT 1 FROM inv_scan x WHERE x.Count_ID = :cid AND x.Accession = {$s['acc']})";
    if ($shouldBeHere) {   // skip what is legitimately away: on loan, lost, or already recorded as lost / missing
        if ($kind === 'Serials') $sql .= ' AND i.Status = 0';
        else $sql .= " AND NOT EXISTS (SELECT 1 FROM Borrower b WHERE b.Accession_number = {$s['acc']} AND b.Date_returned IS NULL)
                       AND (i.Status IS NULL OR LOWER(TRIM(i.Status)) NOT IN ('lost', 'out', 'withdrawn', 'i-loan', 'inter-library loan', 'lost/paid'))
                       AND NOT EXISTS (SELECT 1 FROM Borrower p WHERE p.Accession_number = {$s['acc']} AND p.Status = 'Lost/Paid')";
    }
    return $sql;
}

// Close the count: everything that should be on the shelf and was not scanned becomes Missing. Returns how many.
function inv_close(array $count): int {
    $args = []; $s = inv_src($count['Kind']);
    $from = inv_unscanned_sql($count, $args, true);
    $args['by'] = $_SESSION['user'] ?? '';
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO inv_scan (Count_ID, Accession, Title, Call_Number, Status, Note, Scanned_at, Scanned_by)
            SELECT :cid2, {$s['acc']}, LEFT({$s['title']}, 255), LEFT({$s['call']}, 80), 'Missing', 'Not found during the count', NOW(), :by $from");
        $ins->execute($args + ['cid2' => $count['ID']]);
        $n = $ins->rowCount();
        $pdo->prepare('UPDATE inv_count SET Closed_at = NOW() WHERE ID = ? AND Closed_at IS NULL')->execute([$count['ID']]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); throw $ex; }
    return $n;
}
