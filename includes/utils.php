<?php
// Small helpers shared by the Files, Search, Utilities and Reports pages.
if (!function_exists('csrf_field')) { function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; } }
// A valid Y-m-d string, or the default.
function valid_date($s, string $default = ''): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : $default;
}
function fdatetime($d): string { return $d ? date('M j, Y g:i A', strtotime((string)$d)) : ''; }
function table_exists(string $t): bool {
    static $seen = [];
    if (!isset($seen[$t])) { try { db()->query('SELECT 1 FROM `' . str_replace('`', '', $t) . '` LIMIT 1'); $seen[$t] = true; } catch (PDOException $e) { $seen[$t] = false; } }
    return $seen[$t];
}
function options(array $items, ?string $selected = null, bool $blank = true): string {
    $h = $blank ? '<option value=""></option>' : '';
    foreach ($items as $k => $l) { $v = is_int($k) ? $l : $k; $h .= '<option value="' . e($v) . '"' . ((string)$v === (string)$selected ? ' selected' : '') . '>' . e($l) . '</option>'; }
    return $h;
}
// Items of one of the old dropdown text files in data/lookups (items are separated by ; or new lines).
function lookup_list(string $name): array {
    $f = __DIR__ . '/../data/lookups/' . preg_replace('/[^A-Za-z0-9_-]/', '', $name) . '.txt';
    if (!is_file($f)) return [];
    return array_values(array_filter(array_map('trim', preg_split('/[;\r\n]+/', (string)file_get_contents($f))), 'strlen'));
}
// The heading printed on reports: Utilities > Settings > Report heading, else the library name.
function report_heading(): string {
    global $CONFIG;
    try { $h = trim((string)db()->query('SELECT LocalHeading FROM OverdueCharge LIMIT 1')->fetchColumn()); } catch (PDOException $e) { $h = ''; }
    return $h !== '' ? $h : (string)($CONFIG['library_name'] ?? 'Library');
}
// Library filter used on catalog locations and loans: "College Library - Circulation" starts with "College".
function lib_like(string $library): string { return $library . '%'; }

// ---- small key/value settings (table app_setting from 14_attendance_timeinout.sql) ----
function app_setting(string $key, string $default = ''): string {
    if (!table_exists('app_setting')) return $default;
    $st = db()->prepare('SELECT Setting_value FROM app_setting WHERE Setting_key = ?'); $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}
function app_setting_set(string $key, string $value): void {
    db()->prepare('INSERT INTO app_setting (Setting_key, Setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE Setting_value = VALUES(Setting_value)')->execute([$key, $value]);
}
// Attendance time in / time out is available once 14_attendance_timeinout.sql has been imported.
function att_has_inout(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool)db()->query("SHOW COLUMNS FROM attendance_log LIKE 'Time_out'")->fetch(); } catch (PDOException $e) { $has = false; } }
    return $has;
}
function att_closing_time(): string { $t = app_setting('attendance_closing_time', '17:00'); return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '17:00'; }
// Anyone still "in" after closing time (or from an earlier day) is timed out at closing time. Runs whenever the attendance page or report opens.
function att_autoclose(): void {
    if (!att_has_inout()) return;
    $close = att_closing_time(); $today = date('Y-m-d'); $now = date('Y-m-d H:i:s');
    db()->prepare("UPDATE attendance_log SET Time_out = GREATEST(Scanned_at, CONCAT(DATE(Scanned_at), ' ', ?, ':00')), Auto_closed = 1
                   WHERE Time_out IS NULL AND (DATE(Scanned_at) < ? OR (DATE(Scanned_at) = ? AND ? >= CONCAT(?, ' ', ?, ':00') AND Scanned_at < CONCAT(?, ' ', ?, ':00')))")
        ->execute([$close, $today, $today, $now, $today, $close, $today, $close]);
}
function att_stay(?string $in, ?string $out): string {
    if (!$in || !$out) return '';
    $m = max(0, (int)floor((strtotime($out) - strtotime($in)) / 60));
    return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
}
