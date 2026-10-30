<?php
/** Guidance password forms only. Shared login continues to use password_verify(). */
function gp_value(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8')) {
        throw new RuntimeException('Enter a valid password value.', 400);
    }
    return $value; // Never trim, log, or persist plaintext passwords.
}
function gp_error(string $value): ?string
{
    $length = mb_strlen($value, 'UTF-8');
    if ($length < 12 || $length > 128) return 'Use 12–128 characters for the new password.';
    if (str_contains($value, "\0")) return 'Passwords cannot contain null characters.';
    if (!preg_match('/[A-Z]/', $value) || !preg_match('/[a-z]/', $value) || !preg_match('/[0-9]/', $value)) {
        return 'Include an uppercase letter, a lowercase letter and a number.';
    }
    return null;
}
function gp_hash(string $value): string
{
    // bcrypt truncates beyond 72 bytes; Argon2id preserves the full 128-character policy.
    if (!in_array('argon2id', password_algos(), true)) {
        throw new RuntimeException('The server needs Argon2id password support. No password was changed. Contact the application administrator.', 503);
    }
    return password_hash($value, 'argon2id');
}
function gp_field(string $name, string $label, string $kind = 'current'): string
{
    static $sequence = 0;
    $id = 'gd-password-'.(++$sequence);
    $new = $kind === 'new';
    $confirm = $kind === 'confirm';
    $data = $new ? ' data-gp-new' : ($confirm ? ' data-gp-confirm' : '');
    $description = $new ? ' aria-describedby="'.$id.'-rules '.$id.'-strength"' : ($confirm ? ' aria-describedby="'.$id.'-match"' : '');
    $html = '<div class="field gd-password-field"><label class="lbl" for="'.$id.'">'.e($label).'</label><div class="gd-password-control"><input type="password" id="'.$id.'" name="'.e($name).'" data-pw="1"'.$data.$description.' autocomplete="'.($kind === 'current' ? 'current-password' : 'new-password').'" autocapitalize="none" spellcheck="false" required><button type="button" class="gd-password-toggle" aria-controls="'.$id.'" aria-label="Show '.e(strtolower($label)).'" aria-pressed="false" data-gp-label="'.e(strtolower($label)).'">Show</button></div>';
    if ($new) {
        $html .= '<p class="hint">12–128 characters; include uppercase, lowercase and a number. Symbols are optional.</p><ul class="gd-password-rules" id="'.$id.'-rules"><li data-rule="length">12–128 characters</li><li data-rule="upper">Uppercase letter (A–Z)</li><li data-rule="lower">Lowercase letter (a–z)</li><li data-rule="number">Number (0–9)</li></ul><div class="gd-password-meter" aria-hidden="true"><span></span><span></span><span></span><span></span></div><p class="hint" id="'.$id.'-strength" data-gp-strength aria-live="polite">Use a unique password or passphrase.</p>';
    }
    if ($confirm) $html .= '<p class="hint" id="'.$id.'-match" data-gp-match aria-live="polite">Re-enter the new password.</p>';
    return $html.'</div>';
}
