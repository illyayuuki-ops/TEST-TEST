<?php
// Included only after the native account route has enforced authentication and CSRF.
if (!defined('GD_PASSWORD_PAGE')) { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/guidance-password.php';
require_once __DIR__.'/domain.php';
$account = gone('SELECT password,must_change_password FROM users WHERE id=?', [$u['id']]);
$forced = (bool)$account['must_change_password'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!g_rate('guidance-password:'.$u['id'], 10)) throw new RuntimeException('Too many password-change attempts. Please wait 15 minutes and try again.', 429);
        $current = gp_value($_POST, 'current');
        $next = gp_value($_POST, 'new');
        $confirmation = gp_value($_POST, 'new2');
        if (!password_verify($current, $account['password'])) throw new RuntimeException('Current password is incorrect.', 400);
        if ($message = gp_error($next)) throw new RuntimeException($message, 400);
        if ($next !== $confirmation) throw new RuntimeException('New passwords do not match.', 400);
        if ($current === $next) throw new RuntimeException('Choose a password different from the current one.', 400);
        $hash = gp_hash($next);
        g_transaction(function () use ($u, $account, $hash) {
            $changed = gq('UPDATE users SET password=?,must_change_password=0,reset_token=NULL,reset_expires=NULL WHERE id=? AND BINARY password=?', [$hash,$u['id'],$account['password']])->rowCount();
            if ($changed !== 1) throw new RuntimeException('Your account changed in another session. Sign in again before retrying.', 409);
            gq('DELETE FROM gd_sessions WHERE user_id=?', [$u['id']]);
            g_audit($u, 'PASSWORD_CHANGED', 'Changed own shared password; sessions revoked');
        });
        $_SESSION = [];
        session_regenerate_id(true);
        redirect('auth/login.php?pw=1');
    } catch (Throwable $e) {
        $expected = $e instanceof RuntimeException && !($e instanceof PDOException) && $e->getCode() >= 400 && $e->getCode() <= 599;
        $error = $expected ? $e->getMessage() : 'Unable to update your password. Please contact the application administrator.';
        if (!$expected) error_log('Guidance password change failed: '.get_class($e));
        http_response_code($expected ? (int)$e->getCode() : 500);
    }
}
page_start('Change password', 'Guidance · Secure your shared campus account');
?>
<link rel="stylesheet" href="<?= e(url('guidance/assets/password.css')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/password.css') ?>">
<section class="card gd-password-page">
  <div class="card-h"><h3><?= $forced ? 'Set your personal password' : 'Choose a new password' ?></h3></div>
  <?php if ($forced): ?><div class="alert warn">Change your temporary password before continuing to Guidance.</div><?php endif; ?>
  <p class="gd-password-intro">This updates the password for your shared campus account, not a separate Guidance account. After saving, you will be signed out on all devices and asked to sign in again.</p>
  <?php if ($error): ?><div class="alert err" role="alert"><?= e($error) ?> Password fields have been cleared for your security.</div><?php endif; ?>
  <form method="post" action="<?= e(url('auth/change_password.php?from=guidance')) ?>" data-gp-form>
    <?= csrf_field() ?>
    <?= gp_field('current', 'Current password') ?>
    <?= gp_field('new', 'New password', 'new') ?>
    <?= gp_field('new2', 'Confirm new password', 'confirm') ?>
    <div class="gd-password-actions"><button class="btn btn-primary" type="submit">Update password</button><?php if (!$forced): ?><a class="btn" href="<?= e(url('guidance/index.php?view=settings')) ?>">Back to Guidance settings</a><?php endif; ?></div>
  </form>
</section>
<script src="<?= e(url('guidance/assets/password.js')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/password.js') ?>" defer></script>
<?php page_end(); ?>
