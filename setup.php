<?php
// Other records (rooms, equipment, holidays, ...) and the circulation rules in use.
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/circulation.php';
$pageTitle = 'Setup';
$c = circ_cfg(); $oc = db()->query('SELECT * FROM OverdueCharge LIMIT 1')->fetch() ?: [];
include __DIR__ . '/includes/header.php'; ?>
<h1>Setup</h1>
<h2>Other records</h2>
<div class="cards">
  <?php foreach (['holidays' => 'Holidays (library closed)', 'rooms' => 'Rooms', 'equipment' => 'Equipment', 'authority' => 'Subject headings', 'cashier' => 'Cashier payments'] as $k => $label): ?>
    <a class="card stat" href="crud.php?e=<?= e($k) ?>"><span><?= e($label) ?></span></a><?php endforeach; ?>
</div>
<h2>Circulation rules in use</h2>
<div class="card"><table class="data"><tbody>
  <tr><td>Loan period, books</td><td><?= (int)$c['loan_days'] ?> days</td></tr>
  <tr><td>Loan period, items in a "<?= e($c['reserve_keyword']) ?>" location</td><td><?= (int)$c['overnight_days'] ?> day (overnight)</td></tr>
  <tr><td>Loan period, non-book materials</td><td><?= (int)$c['nonbook_days'] ?> days</td></tr>
  <tr><td>Items per borrower</td><td><?= (int)$c['max_loans'] ?></td></tr>
  <tr><td>Block new loans while overdue</td><td><?= $c['block_if_overdue'] ? 'Yes' : 'No' ?></td></tr>
  <tr><td>Cannot be borrowed (location contains)</td><td><?= e(implode(', ', $c['no_loan_keywords'])) ?></td></tr>
  <tr><td>Reservation is held for</td><td><?= (int)$c['reservation_days'] ?> days</td></tr>
  <tr><td>Closed on</td><td><?php $dn = [0 => 'Sundays', 1 => 'Mondays', 5 => 'Fridays', 6 => 'Saturdays']; $cl = array_map(fn($d) => $dn[$d], excluded_weekdays()); echo e(($cl ? implode(', ', $cl) : 'no weekday') . ' and the dates under Holidays'); ?> <?php if (is_role('admin')): ?><a href="utilities.php?tab=settings">(change)</a><?php endif; ?></td></tr>
</tbody></table></div>
<p class="muted">Change these in <code>config.php</code> under <code>'circulation'</code>.</p>
<h2>Fines per day (from the old OverdueCharge table)</h2>
<div class="card"><table class="data"><tbody>
  <?php foreach (['Student', 'Faculty', 'Grade_school', 'High_school', 'Supervisor', 'Admin', 'Staff', 'Personnel', 'OvedueCharge', 'Overnight'] as $k): if (isset($oc[$k])): ?>
    <tr><td><?= e($k === 'OvedueCharge' ? 'Other / unknown class' : ($k === 'Overnight' ? 'Overnight items' : ($k === 'Grade_school' ? 'Lower school (LS)' : ($k === 'High_school' ? 'High school (HS)' : $k)))) ?></td><td><?= e(money($oc[$k])) ?></td></tr>
  <?php endif; endforeach; ?></tbody></table></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
