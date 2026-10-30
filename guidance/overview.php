<?php
// View partial only: the parent has already authenticated and scoped all records.
if (!isset($u, $view) || $view !== 'overview') { http_response_code(404); exit; }
$operational = $staff === 'administrator';
$queue = array_values(array_filter($operational ? $events : $cases, fn($row) => !in_array($row['status'], $operational ? ['completed','cancelled'] : HISTORY_STATUSES, true)));
$queueStatuses = array_values(array_unique(array_column($queue, 'status')));
$recent = notif_recent($pdo, $uid, 6);
?>
<div class="dash gd-overview">
  <div>
    <section class="card gd-card" data-overview-list>
      <div class="card-h"><h3><?= $operational ? 'Activity workspace' : 'Active requests' ?></h3><span class="mono" data-overview-count><?= count($queue) ?> shown</span></div>
      <div class="filters" role="search" aria-label="Filter your <?= $operational ? 'activities' : 'requests' ?>">
        <input type="search" data-overview-query aria-label="Search <?= $operational ? 'activities' : 'requests' ?>" placeholder="<?= $operational ? 'Search activity or venue…' : 'Search subject or request code…' ?>">
        <select data-overview-status aria-label="Filter by status"><option value="">All statuses</option><?php foreach ($queueStatuses as $status): ?><option value="<?= e($status) ?>"><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select>
        <button type="button" class="btn" data-overview-filter><i class="fa-solid fa-filter" aria-hidden="true"></i>Filter</button>
        <button type="button" class="btn" data-overview-clear hidden>Clear</button>
      </div>
      <?php if (!$queue): ?>
        <div class="empty"><i class="fa-solid <?= $operational ? 'fa-calendar-days' : 'fa-ticket' ?>" aria-hidden="true"></i><?= $operational ? 'No active activities. Select Manage activities above to plan one.' : (is_filer($u) ? 'No active Guidance requests. Select Request Guidance above to get started.' : 'No active requests in your queue. Assigned cases will appear here when available.') ?></div>
      <?php else: ?>
        <div class="tk-grid">
        <?php foreach ($queue as $row): ?>
          <article class="tk-card" data-overview-item data-status="<?= e($row['status']) ?>">
          <?php if ($operational): ?>
            <div class="tk-top"><?= gtag($row['status']) ?></div>
            <a class="tk-title" href="<?= e(url('guidance/index.php?view=event&id='.(int)$row['id'])) ?>"><?= e($row['title']) ?></a>
            <div class="tk-sub"><?= e($row['venue']) ?> · <?= e(fmt_dt($row['starts_at'])) ?></div>
            <div class="tk-foot"><span class="tk-last">Philippine local time</span><?= gl('event', 'View activity', (int)$row['id'], 'btn btn-sm') ?></div>
          <?php else: ?>
            <div class="tk-top"><?= badge($row['status']) ?><?= prio_badge($row['priority']) ?></div>
            <a class="tk-title" href="<?= e(url('ticket.php?id='.(int)$row['id'])) ?>"><?= e($row['subject']) ?></a>
            <div class="tk-sub"><?= e($row['code']) ?> · <?= e($row['category']) ?></div>
            <?= ticket_tracker($row) ?>
            <div class="tk-foot"><span class="tk-last"><i class="fa-regular fa-clock" aria-hidden="true"></i><span>Last update<b><?= e(fmt_dt($row['updated_at'])) ?></b></span></span><a class="btn btn-sm" href="<?= e(url('ticket.php?id='.(int)$row['id'])) ?>">View details<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div>
          <?php endif; ?>
          </article>
        <?php endforeach; ?>
        </div>
        <div class="empty" data-overview-empty hidden>No matches. Try another search or clear your filters.</div>
      <?php endif; ?>
    </section>
  </div>
  <aside>
    <section class="card panel gd-card">
      <div class="card-h"><h3>Recent updates</h3><i class="fa-regular fa-bell" aria-hidden="true"></i></div>
      <div class="upd-list"><?= notif_items_html($recent) ?></div>
    </section>
  </aside>
</div>
