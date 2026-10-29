<?php
// Helpers for Media booking (venue + equipment reservations).

// Dropdown suggestions come from the old text files in data/lookups (Time, Professors, Subjects, Purpose ...).
// lookup_list() lives in includes/utils.php (loaded by every page).

// "09:00AM-10:30AM" or "1:30PM-3:00PM" -> [start, end] in minutes after midnight.
// The old time list has entries like "13:30PM" (hour already 24-hour), so hours of 13+ are taken as they are.
function slot_minutes(string $slot): ?array {
    if (!preg_match('/(\d{1,2}):(\d{2})\s*([AP]M)?\s*-\s*(\d{1,2}):(\d{2})\s*([AP]M)?/i', $slot, $m)) return null;
    $t = function ($h, $mi, $ap) {
        $h = (int)$h; $ap = strtoupper((string)$ap);
        if ($h < 13) {
            if ($ap === 'PM' && $h < 12) $h += 12;
            if ($ap === 'AM' && $h === 12) $h = 0;
        }
        return $h * 60 + (int)$mi;
    };
    return [$t($m[1], $m[2], $m[3] ?? ''), $t($m[4], $m[5], $m[6] ?? '')];
}

// What can be booked: rooms come from the room_reservation table, equipment from the Equipment table.
function bookable_items(string $kind): array {
    if ($kind === 'Venue') {
        return db()->query("SELECT DISTINCT Rooms FROM room_reservation WHERE Rooms IS NOT NULL AND Rooms <> '' ORDER BY Rooms")->fetchAll(PDO::FETCH_COLUMN);
    }
    $rows = db()->query("SELECT equipment, SN FROM Equipment WHERE equipment IS NOT NULL AND equipment <> '' AND (status IS NULL OR status NOT IN ('Lost','Disposed')) ORDER BY equipment, SN")->fetchAll();
    return array_map(fn($r) => $r['equipment'] . (($r['SN'] ?? '') !== '' ? ' (' . $r['SN'] . ')' : ''), $rows);
}

// Another booking of the same room/equipment on the same date whose time overlaps? Returns it, or null.
function booking_conflict(string $kind, string $item, string $date, int $start, int $end, int $ignoreId = 0): ?array {
    $st = db()->prepare('SELECT * FROM media_booking WHERE Kind = ? AND Item = ? AND Booking_date = ? AND ID <> ? AND Start_min < ? AND End_min > ? ORDER BY Start_min LIMIT 1');
    $st->execute([$kind, $item, $date, $ignoreId, $end, $start]);
    return $st->fetch() ?: null;
}
