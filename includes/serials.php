<?php
// Helpers for Serials. Titles: Serial_TitleTemp. Issues: SerialsTemp (linked to a title by Code). Loans: Borrower ("SER-<issue id>").
// SerialsTemp.Status: 0 = on shelf, 1 = out, 2 = lost. An issue with no Date_received has not arrived yet.

const SER_SOON_DAYS = 30;

function ser_label(array $r): string {
    $t = $r['Serial_Title'] ?? ($r['SerialTitle'] ?? '(no title)');
    $bits = [];
    if (($r['Volume'] ?? '') !== '') $bits[] = 'Vol. ' . $r['Volume'];
    if (($r['Number'] ?? '') !== '') $bits[] = 'No. ' . $r['Number'];
    return $t . ($bits ? ', ' . implode(' ', $bits) : '') . (($r['Date_Year'] ?? '') !== '' ? ' (' . $r['Date_Year'] . ')' : '');
}

// 'Not received' / 'On shelf' / 'Out' / 'Lost'
function ser_status(array $i): string {
    if ((int)$i['Status'] === 1) return 'Out';
    if ((int)$i['Status'] === 2) return 'Lost';
    return empty($i['Date_received']) ? 'Not received' : 'On shelf';
}

// Subscription state from the expiration date: 'expired', 'soon' (within SER_SOON_DAYS) or ''.
function ser_expiry(?string $exp): string {
    if (!$exp) return '';
    $d = strtotime(substr($exp, 0, 10)); $today = strtotime(date('Y-m-d'));
    if ($d < $today) return 'expired';
    return $d <= strtotime('+' . SER_SOON_DAYS . ' days', $today) ? 'soon' : '';
}

// Library restriction: a library-bound user only sees titles of that library (and never issues without a title).
function ser_lib_sql(string $col, array &$args, string $lf = ''): string {
    $libs = libraries();
    if (!lib_all()) { $args['lib'] = user_library(); return " AND $col = :lib"; }
    if (in_array($lf, $libs, true)) { $args['lib'] = $lf; return " AND $col = :lib"; }
    return '';
}

function ser_title(int $id): ?array {
    $st = db()->prepare('SELECT * FROM Serial_TitleTemp WHERE ID = ?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r || (!lib_all() && $r['Library'] !== user_library())) return null;
    return $r;
}

function ser_issue(int $idd): ?array {
    $st = db()->prepare('SELECT s.*, t.Serial_Title, t.Library, t.ID AS TitleID FROM SerialsTemp s LEFT JOIN Serial_TitleTemp t ON t.Code = s.Code WHERE s.IDD = ? LIMIT 1');
    $st->execute([$idd]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!lib_all() && ($r['Library'] ?? null) !== user_library()) return null;
    return $r;
}

// Issues that can be borrowed now: received, on the shelf, and in the user's library.
function ser_available_issues(): array {
    $args = [];
    $w = ser_lib_sql('t.Library', $args);
    $st = db()->prepare("SELECT s.*, t.Serial_Title, t.Library FROM SerialsTemp s JOIN Serial_TitleTemp t ON t.Code = s.Code
        WHERE s.Status = 0 AND s.Date_received IS NOT NULL$w ORDER BY t.Serial_Title, s.IDD DESC LIMIT 500");
    $st->execute($args);
    return $st->fetchAll();
}

// Borrow one issue. Same rules as non-book materials (loan days, limit, overdue block). Returns [ok, message].
function ser_checkout(string $idnum, int $idd): array {
    $c = circ_cfg(); $pdo = db();
    $client = client_by_id($idnum);
    if (!$client) return [false, "Borrower ID \"$idnum\" is not registered. Add the borrower first under Borrowers."];
    $iss = $idd ? ser_issue($idd) : null;
    if (!$iss) return [false, 'Pick an issue from the list.'];
    if (empty($iss['Date_received'])) return [false, 'This issue has not been received yet.'];
    if ((int)$iss['Status'] === 1) return [false, 'This issue is already out.'];
    if ((int)$iss['Status'] === 2) return [false, 'This issue is marked as lost.'];
    [$n, $late] = loan_counts($client['IDnum']);
    if ($n >= (int)$c['max_loans']) return [false, "Loan limit reached ({$c['max_loans']} items)."];
    if ($c['block_if_overdue'] && $late > 0) return [false, 'This borrower has overdue items. Return them first.'];
    $due = due_date_for((int)$c['nonbook_days']); $dueSql = $due->format('Y-m-d 00:00:00');
    // The status change only succeeds if the issue is still on the shelf, so two staff cannot lend it twice.
    $u = $pdo->prepare('UPDATE SerialsTemp SET Status = 1, ID = ?, DateOut = NOW(), DateDue = ?, DateIn = NULL WHERE IDD = ? AND Status = 0');
    $u->execute([$client['IDnum'], $dueSql, $idd]);
    if ($u->rowCount() !== 1) return [false, 'This issue was just taken by someone else.'];
    $label = ser_label($iss);
    try {
        $pdo->prepare("INSERT INTO Borrower (Accession_number, Title, Date_Borrowed, Due_date, Date_returned, Overdue, Library, Status, ID_Number, Type)
                       VALUES (?, ?, NOW(), ?, NULL, 0, ?, 'Borrowed', ?, 'Serial')")->execute(['SER-' . $idd, $label, $dueSql, $iss['Library'], $client['IDnum']]);
        $pdo->prepare('INSERT INTO TempSerialTransaction (Name, IDnumber, User_class, Degree_course, SerialTitle, Date_Year, Volume, Number, Code, DateOut, DateDue, DateIn, IDD) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,NULL,?)')
            ->execute([$client['Name'], $client['IDnum'], $client['User_class'], $client['Degree_Course'], $iss['Serial_Title'], $iss['Date_Year'], $iss['Volume'], $iss['Number'], $iss['Code'], $dueSql, $idd]);
        $pdo->prepare('UPDATE Serial_TitleTemp SET TimesOut = COALESCE(TimesOut, 0) + 1 WHERE Code = ?')->execute([$iss['Code']]);
    } catch (Throwable $ex) {
        $pdo->prepare('UPDATE SerialsTemp SET Status = 0, ID = NULL, DateOut = NULL, DateDue = NULL WHERE IDD = ?')->execute([$idd]);
        throw $ex;
    }
    refresh_client($client['IDnum']);
    return [true, "Borrowed: $label. Due " . $due->format('D, M j, Y') . '.'];
}
