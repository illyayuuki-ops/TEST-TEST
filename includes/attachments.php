<?php
/** Ticket file attachments: checked by content (not file name), stored behind a deny-all folder, served through file.php. */
const ATTACH_MAX_FILES = 3;
const ATTACH_MAX_BYTES = 3 * 1024 * 1024;   // per file (3 MB, same as the alumni card limit)

function attach_dir(): string {
    $d = __DIR__ . '/../uploads/ticket_files';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $h = $d . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    return $d;
}

/** Look at the first bytes of an uploaded file. Returns [extension, mime] for JPG, PNG, WEBP, PDF or DOCX, otherwise null. */
function attach_sniff(string $path, string $name): ?array {
    $fh = @fopen($path, 'rb'); if (!$fh) return null;
    $b = (string)fread($fh, 16); fclose($fh);
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return ['jpg', 'image/jpeg'];
    if (strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0) return ['png', 'image/png'];
    if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') return ['webp', 'image/webp'];
    if (strncmp($b, '%PDF-', 5) === 0) return ['pdf', 'application/pdf'];
    if (strncmp($b, "PK\x03\x04", 4) === 0 && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'docx')
        return ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    return null;
}

/** Validate the files[] input of a form. Returns [error message or '', list of accepted files]. Nothing is saved yet. */
function attach_collect(string $field = 'files'): array {
    $in = $_FILES[$field] ?? null; $ok = [];
    if (!$in || !is_array($in['name'])) return ['', []];
    foreach ($in['name'] as $i => $nm) {
        $err = $in['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $nm = (string)$nm;
        if (count($ok) >= ATTACH_MAX_FILES) return ['You can attach up to ' . ATTACH_MAX_FILES . ' files.', []];
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($in['tmp_name'][$i])) return ["\"$nm\" could not be uploaded. Try a smaller file.", []];
        if ($in['size'][$i] > ATTACH_MAX_BYTES) return ["\"$nm\" is over 3 MB.", []];
        $k = attach_sniff($in['tmp_name'][$i], $nm);
        if (!$k) return ["\"$nm\" is not allowed. Use JPG, PNG, WEBP, PDF or DOCX.", []];
        $clean = preg_replace('/[^\w .()\-]+/u', '_', basename($nm));
        if (!is_string($clean) || trim($clean) === '') $clean = 'file.' . $k[0];
        $ok[] = ['tmp' => $in['tmp_name'][$i], 'name' => substr($clean, 0, 120), 'ext' => $k[0], 'mime' => $k[1], 'size' => (int)$in['size'][$i]];
    }
    return ['', $ok];
}

/** Store accepted files for a ticket (and optionally one reply). Never throws: if the table is missing the ticket still saves. */
/** Returns how many files were actually stored. */
function attach_save(PDO $pdo, int $ticketId, ?int $replyId, int $userId, array $files): int {
    if (!$files) return 0;
    $saved = 0;
    $dir = attach_dir();
    foreach ($files as $f) {
        $stored = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        if (!@move_uploaded_file($f['tmp'], $dir . '/' . $stored)) continue;
        try {
            $pdo->prepare('INSERT INTO ticket_files (ticket_id,reply_id,user_id,original_name,stored_name,mime,size_bytes) VALUES (?,?,?,?,?,?,?)')
                ->execute([$ticketId, $replyId, $userId, $f['name'], $stored, $f['mime'], $f['size']]);
            audit('TICKET_FILE', 'Attached ' . $f['name'] . ' to ticket #' . $ticketId);
            $saved++;
        } catch (Throwable $e) {
            @unlink($dir . '/' . $stored);
            error_log('Attachment not saved (run database/upgrade_attachments.sql?): ' . $e->getMessage());
        }
    }
    return $saved;
}

/** Files of one ticket grouped by reply id (0 = the original ticket). Requesters never get files of internal notes. */
function attach_list(PDO $pdo, int $ticketId, bool $includeInternal): array {
    try {
        $s = $pdo->prepare('SELECT f.*, COALESCE(f.reply_id,0) AS rid FROM ticket_files f LEFT JOIN replies r ON r.id=f.reply_id WHERE f.ticket_id=?'
            . ($includeInternal ? '' : ' AND (r.id IS NULL OR r.is_internal=0)') . ' ORDER BY f.id');
        $s->execute([$ticketId]);
    } catch (Throwable $e) { return []; }
    $out = []; foreach ($s->fetchAll() as $f) $out[(int)$f['rid']][] = $f;
    return $out;
}

function attach_size(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB'; }

function attach_chips(array $files): string {
    if (!$files) return '';
    $h = '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">';
    foreach ($files as $f)
        $h .= '<a class="btn btn-sm" href="' . e(url('file.php?id=' . (int)$f['id'])) . '"><i class="fa-solid fa-paperclip"></i>' . e($f['original_name']) . ' (' . attach_size((int)$f['size_bytes']) . ')</a>';
    return $h . '</div>';
}

function attach_input_html(): string {
    return '<div class="field"><label>Attachments (optional)</label><input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.docx">'
         . '<div class="hint">Up to ' . ATTACH_MAX_FILES . ' files, 3 MB each. JPG, PNG, WEBP, PDF or DOCX.</div></div>';
}

/* ── Profile photos (students / alumni) ─────────────────────────────────────────────────────────── */
function avatar_dir(): string {
    $d = __DIR__ . '/../uploads/avatars';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    $h = $d . '/.htaccess';
    if (!is_file($h)) @file_put_contents($h, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    return $d;
}

/** Check and store an uploaded profile photo (cropped square 256 px when GD is available). Returns [stored file name or '', error message or '']. */
function avatar_store(?array $up, int $userId): array {
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', 'Choose a photo first.'];
    if ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) return ['', 'The upload failed. Try a smaller image.'];
    if ($up['size'] > CARD_MAX_BYTES) return ['', 'The photo must be 3 MB or smaller.'];
    $info = @getimagesize($up['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) return ['', 'The photo must be a JPG, PNG or WEBP image.'];
    if ($info[0] * $info[1] > 25000000) return ['', 'That image is too large. Choose a smaller one.'];
    $dir = avatar_dir(); $base = $userId . '_' . bin2hex(random_bytes(8));
    $loader = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
    if (function_exists('imagecreatetruecolor') && function_exists($loader) && ($src = @$loader($up['tmp_name']))) {
        $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
        $dst = imagecreatetruecolor(256, 256);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);
        $name = $base . '.jpg';
        $ok = @imagejpeg($dst, $dir . '/' . $name, 88);
        imagedestroy($src); imagedestroy($dst);
        return $ok ? [$name, ''] : ['', 'Could not save the photo.'];
    }
    $name = $base . '.' . $types[$info[2]];   // no GD on this host: keep the checked original
    return @move_uploaded_file($up['tmp_name'], $dir . '/' . $name) ? [$name, ''] : ['', 'Could not save the photo.'];
}

function avatar_delete(?string $name): void { if ($name) @unlink(avatar_dir() . '/' . basename($name)); }
