<?php
require __DIR__.'/../config/app.php';
require __DIR__.'/../includes/layout.php';
$u = require_role('student');
page_start('Settings', 'Your campus account and preferences');
?>
<div class="pf-grid">
  <section class="card">
    <div class="card-h"><h3>Your campus account</h3></div>
    <p><strong><?= e($u['full_name']) ?></strong><br><?= e($u['username']) ?></p>
    <div class="field"><p>Update your profile photo and account details.</p><a class="btn" href="<?= e(url('student/profile.php')) ?>"><i class="fa-solid fa-user" aria-hidden="true"></i>Edit profile</a></div>
    <div class="field"><p>Change the password used for your shared campus account.</p><a class="btn" href="<?= e(url('auth/change_password.php')) ?>"><i class="fa-solid fa-key" aria-hidden="true"></i>Change password</a></div>
  </section>
  <?php if (g_ready()): ?>
  <section class="card">
    <div class="card-h"><h3>Guidance preferences</h3></div>
    <p>Manage Guidance notifications, request a privacy review, download your Guidance records, or sign out all sessions.</p>
    <a class="btn" href="<?= e(url('guidance/index.php?view=settings')) ?>"><i class="fa-solid fa-gear" aria-hidden="true"></i>Open Guidance settings</a>
  </section>
  <?php endif; ?>
</div>
<?php page_end(); ?>
