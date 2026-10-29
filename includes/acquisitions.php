<?php
// Helpers for Acquisitions. Price is per copy. Gross = price x copies. The discount (a percent or a peso amount) comes off the
// gross; net = gross - discount. Balance on a record = the budget typed on it - its net cost.

const ACQ_MTYPES = ['Book' => 'Book', 'SM' => 'Audio-visual / non-book', 'EQ' => 'Equipment'];
const ACQ_MODES = ['Purchase', 'Donation', 'Gift', 'Exchange', 'Replacement', 'Deposit'];
const ACQ_STATUSES = ['Local-Order', 'Direct-Order', 'In', 'Acquired'];
const ACQ_FORMATS = ['DVD', 'CD', 'VCD', 'CD-ROM', 'Cassette', 'Chart', 'Other'];

function acq_gross_sql(string $a = 'a'): string { return "(COALESCE($a.Price, 0) * COALESCE(NULLIF($a.Quantity, 0), 1))"; }
function acq_disc_sql(string $a = 'a'): string {
    $g = acq_gross_sql($a);
    return "(CASE WHEN $a.Disc_type = 'percent' THEN $g * LEAST(COALESCE($a.Disc_value, 0), 100) / 100
                  WHEN $a.Disc_type = 'amount' THEN LEAST(COALESCE($a.Disc_value, 0), $g) ELSE 0 END)";
}
function acq_net_sql(string $a = 'a'): string { return '(' . acq_gross_sql($a) . ' - ' . acq_disc_sql($a) . ')'; }

function acq_money($n): string { global $CONFIG; return ($CONFIG['circulation']['currency'] ?? '₱') . number_format((float)$n, 2); }

// Departments already used on records (for the dropdowns).
function acq_depts(): array {
    return db()->query("SELECT DISTINCT Dept FROM acquitemp WHERE Dept IS NOT NULL AND Dept <> '' ORDER BY Dept")->fetchAll(PDO::FETCH_COLUMN);
}
function acq_dealers(): array {
    return db()->query("SELECT DISTINCT Dealer FROM acquitemp WHERE Dealer IS NOT NULL AND Dealer <> '' ORDER BY Dealer")->fetchAll(PDO::FETCH_COLUMN);
}

// Work out gross / discount / net / balance in PHP for one record (the same maths as the SQL above).
function acq_figures(array $r): array {
    $qty = (int)($r['Quantity'] ?? 0) ?: 1; $gross = (float)($r['Price'] ?? 0) * $qty;
    $dv = (float)($r['Disc_value'] ?? 0); $disc = 0.0;
    if (($r['Disc_type'] ?? '') === 'percent') $disc = $gross * min($dv, 100) / 100;
    elseif (($r['Disc_type'] ?? '') === 'amount') $disc = min($dv, $gross);
    $net = $gross - $disc;
    $bal = ($r['Budget'] ?? '') === '' || $r['Budget'] === null ? null : (float)$r['Budget'] - $net;
    return ['gross' => round($gross, 2), 'disc' => round($disc, 2), 'net' => round($net, 2), 'balance' => $bal === null ? null : round($bal, 2)];
}

// Validate and clean the form. Returns [values, error].
function acq_clean(array $in, bool $withPrices = true): array {
    $t = fn($k) => trim((string)($in[$k] ?? ''));
    $v = [];
    foreach (['Title' => 250, 'Author' => 80, 'Publisher' => 80, 'ISBN' => 50, 'Ye_ar' => 20, 'Mode' => 40, 'Dept' => 50, 'Dealer' => 80, 'Status' => 30, 'Format' => 30, 'Run_time' => 20, 'Producer' => 100] as $k => $max) {
        $v[$k] = $t($k);
        if (strlen($v[$k]) > $max) return [$v, "The field \"$k\" is too long (limit $max characters)."];
    }
    if ($v['Title'] === '') return [$v, 'Enter the title.'];
    $v['MType'] = in_array($t('MType'), ['SM', 'EQ'], true) ? $t('MType') : 'Book';
    $q = $t('Quantity'); if ($q === '') $q = '1';
    if (!ctype_digit($q) || (int)$q < 1 || (int)$q > 100000) return [$v, 'Quantity must be a whole number of 1 or more.'];
    $v['Quantity'] = (int)$q;
    if ($withPrices) {
    foreach (['Price' => 'Price', 'Budget' => 'Budget'] as $k => $l) {
        $x = $t($k); if ($x !== '' && (!is_numeric($x) || (float)$x < 0)) return [$v, "$l must be a number, 0 or more."];
        $v[$k] = $x === '' ? ($k === 'Price' ? 0 : null) : round((float)$x, 2);
    }
    }
    $d = $t('Da_te');
    if ($d !== '') { $dt = DateTime::createFromFormat('Y-m-d', $d); if (!$dt || $dt->format('Y-m-d') !== $d) return [$v, 'Use a valid date.']; }
    $v['Da_te'] = $d === '' ? null : $d;
    if ($withPrices) {
    $dtp = $t('Disc_type'); $dval = $t('Disc_value');
    if ($dval === '' || (float)$dval == 0) { $v['Disc_type'] = null; $v['Disc_value'] = null; }
    else {
        if (!in_array($dtp, ['percent', 'amount'], true)) return [$v, 'Choose whether the discount is a percent or a peso amount.'];
        if (!is_numeric($dval) || (float)$dval < 0) return [$v, 'The discount must be a number, 0 or more.'];
        if ($dtp === 'percent' && (float)$dval > 100) return [$v, 'A percent discount cannot be more than 100.'];
        $gross = (float)$v['Price'] * $v['Quantity'];
        if ($dtp === 'amount' && (float)$dval > $gross + 0.004) return [$v, 'The discount is more than the price of all copies (' . number_format($gross, 2) . ').'];
        $v['Disc_type'] = $dtp; $v['Disc_value'] = round((float)$dval, 2);
    }
    }
    if ($v['MType'] === 'Book') { $v['Format'] = ''; }
    if ($v['MType'] !== 'SM') { $v['Run_time'] = ''; $v['Producer'] = ''; }
    // Optional columns from 11_gap_fixes.sql: edition, who recommended it, where it was selected, and a foreign price with its exchange rate.
    if (!empty($GLOBALS['ACQ_EXTRA'])) {
        foreach (['Edition' => 40, 'Recom_by' => 100, 'Selected_where' => 100] as $k => $max) {
            $v[$k] = $t($k); if (strlen($v[$k]) > $max) return [$v, "The field \"$k\" is too long (limit $max characters)."];
            if ($v[$k] === '') $v[$k] = null;
        }
        if ($withPrices) {
            $ff = $t('Fx_foreign'); $fr = $t('Fx_rate');
            if ($ff !== '' && (!is_numeric($ff) || (float)$ff < 0)) return [$v, 'The foreign price must be a number, 0 or more.'];
            if ($fr !== '' && (!is_numeric($fr) || (float)$fr <= 0)) return [$v, 'The exchange rate must be a number greater than 0.'];
            if ($ff !== '' && (float)$ff > 0) {
                $rate = $fr === '' ? 1.0 : (float)$fr;
                $v['Fx_foreign'] = round((float)$ff, 2); $v['Fx_rate'] = round($rate, 4);
                $v['Price'] = round((float)$ff * $rate, 2);   // the peso price per copy comes from the foreign price
            } else { $v['Fx_foreign'] = null; $v['Fx_rate'] = null; }
        }
    }
    foreach (['Author', 'Publisher', 'ISBN', 'Ye_ar', 'Mode', 'Dept', 'Dealer', 'Status', 'Format', 'Run_time', 'Producer'] as $k) if ($v[$k] === '') $v[$k] = null;
    return [$v, ''];
}
