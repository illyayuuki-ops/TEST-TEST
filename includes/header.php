<?php
$navGroups = [
  'Main'        => ['index.php' => ['Dashboard', 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
                    'search.php' => ['Search', 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-4-4'],
                    'opac.php' => ['OPAC (public catalog)', 'M12 3a9 9 0 100 18 9 9 0 000-18zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18']],
  'Catalog'     => ['crud.php?e=books' => ['Books', 'M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2zM4 19a2 2 0 012-2h13'],
                    'acquisitions.php' => ['Acquisitions', 'M3 6h18l-2 12H5zM8 6a4 4 0 018 0'],
                    'crud.php?e=nonbook' => ['Non-book', 'M3 5h18v14H3zM10 9l5 3-5 3z'],
                    'files.php' => ['Files', 'M4 4h6l2 3h8v13H4z'],
                    'archives.php' => ['Archives (deleted)', 'M3 5h18v4H3zM5 9v10h14V9M10 13h4'],
                    'labels.php' => ['Cards & labels', 'M4 5h16v14H4zM4 10h16M8 14h5'],
                    'marc.php' => ['MARC export', 'M7 8l-4 4 4 4M17 8l4 4-4 4M14 5l-4 14'],
                    'inventory.php' => ['Inventory', 'M9 4h6v3H9zM6 5h12v16H6zM9 12l2 2 4-4']],
  'Circulation' => ['circulation.php' => ['Circulation', 'M4 12a8 8 0 0114-5l2-2v6h-6l2.5-2.5A5 5 0 007 12M20 12a8 8 0 01-14 5l-2 2v-6h6l-2.5 2.5A5 5 0 0017 12'],
                    'loans.php' => ['Borrowed items', 'M5 3h14v18l-7-4-7 4z'],
                    'reservations.php' => ['Reservations', 'M12 7v5l3 2M12 3a9 9 0 100 18 9 9 0 000-18'],
                    'holds.php' => ['Holds', 'M7 3h10v18l-5-4-5 4zM10 9l2 2 3-3'],
                    'online_requests.php' => ['Online requests', 'M4 6h16v12H4zM4 7l8 6 8-6'],
                    'clearance.php' => ['Clearance', 'M5 12l4 4 10-10M4 20h16'],
                    'referral.php' => ['Referral slip', 'M6 3h9l4 4v14H6zM9 12h7M9 16h5'],
                    'balance.php' => ['Balance & payments', 'M3 7h18v12H3zM3 11h18M7 15h4'],
                    'daily.php' => ['Due / out today', 'M5 4h14v16H5zM5 9h14M9 13h6M9 16h4'],
                    'history.php' => ['History', 'M12 3a9 9 0 11-8.5 6M3 4v5h5M12 8v5l3 2'],
                    'average.php' => ['Average borrowers', 'M4 20V10M10 20V4M16 20v-8M3 14h18'],
                    'media.php?e=venue' => ['Venue booking', 'M3 21V9l9-6 9 6v12zM9 21v-6h6v6'],
                    'media.php?e=equipment' => ['Equipment booking', 'M3 5h18v12H3zM8 21h8M12 17v4'],
                    'equipment_loans.php' => ['Equipment loans', 'M4 7h16v10H4zM9 21h6M12 17v4M8 12l2.5 2L16 10'],
                    'attendance.php' => ['Attendance', 'M4 20V10M10 20V4M16 20v-8M22 20H2'],
                    'transactions.php' => ['Transaction monitoring', 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 7v5l3 2'],
                    'serials.php' => ['Serials', 'M5 4h12a2 2 0 012 2v14H7a2 2 0 01-2-2zM9 8h6M9 12h6M9 16h3'],
                    'crud.php?e=clients' => ['Borrowers','M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0']],
  'Admin'       => ['reports.php' => ['Reports', 'M5 20V10M12 20V4M19 20v-7'],
                    'statistics.php' => ['Statistics', 'M4 20h16M7 20V11M12 20V5M17 20v-6'],
                    'utilities.php?tab=users' => ['Users', 'M16 11a4 4 0 10-8 0 4 4 0 008 0zM4 21a8 8 0 0116 0M18 8h4M20 6v4'],
                    'utilities.php' => ['Utilities', 'M14 7l3-3 3 3-3 3M3 21l9-9M9 5a4 4 0 00-5 5l2 2 5-5z'],
                    'lookups.php' => ['Lists', 'M8 6h13M8 12h13M8 18h13M3 6h1M3 12h1M3 18h1'],
                    'setup.php' => ['Setup', 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12l2-1-2-4-2 1-2-1V5h-4v2l-2 1-2-1-2 4 2 1v2l-2 1 2 4 2-1 2 1v2h4v-2l2-1 2 1 2-4-2-1z'],
                    'browse.php' => ['All tables', 'M3 4h18v16H3zM3 10h18M9 4v16']],
];
foreach ($navGroups as $g => $items) {
  foreach ($items as $href => $_) { $p = parse_url($href); parse_str($p['query'] ?? '', $qq); if (!can_view_page($p['path'], $qq['e'] ?? '')) unset($navGroups[$g][$href]); }
  if (!$navGroups[$g]) unset($navGroups[$g]);
}
$here = basename($_SERVER['SCRIPT_NAME']) . (isset($_GET['e']) ? '?e=' . $_GET['e'] : '');
if ($here === 'utilities.php' && in_array($_GET['tab'] ?? '', ['users', 'admin'], true)) $here = 'utilities.php?tab=users';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Library') ?> · La Consolacion College Tanauan</title>
<link rel="icon" type="image/png" href="assets/img/seal.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,650;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head><body>
<div class="app">
<input type="checkbox" id="navtoggle" class="navtoggle no-print" aria-label="Toggle menu">
<label for="navtoggle" class="scrim no-print"></label>
<aside class="sidebar no-print" aria-label="Main menu">
  <a class="brand" href="index.php">
    <img src="assets/img/seal.png" alt="La Consolacion College Tanauan seal">
    <span><b>LCCT <i>SALRC</i></b><small>La Consolacion College Tanauan</small></span>
  </a>
  <nav>
    <?php foreach ($navGroups as $group => $items): ?>
      <div class="nav-group"><div class="nav-title"><?= e($group) ?></div>
      <?php foreach ($items as $href => [$label, $icon]): ?>
        <a href="<?= e($href) ?>" class="<?= $href === $here ? 'active' : '' ?>"<?= $href === $here ? ' aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= e($icon) ?>"/></svg><span><?= e($label) ?></span></a>
      <?php endforeach; ?></div>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <div class="credit"><img src="assets/img/intel.png" alt="INTEL"><span>Built with<b>INTEL</b></span></div>
  </div>
</aside>
<div class="content">
<?php
  $pName = $_SESSION['name'] ?? 'User'; $pUser = $_SESSION['user'] ?? ''; $pRole = $_SESSION['role'] ?? '';
  $pInit = strtoupper(substr($pName, 0, 1) . (strpos($pName, ' ') !== false ? substr($pName, strpos($pName, ' ') + 1, 1) : ''));
  $roleInfo = ['admin' => 'Full access, including Setup, Lists and All tables.', 'librarian' => 'Manage the catalog, borrowers, circulation and reports.', 'staff' => 'Circulation, loans and reservations. Catalog is view-only.'];
?>
<header class="topbar no-print">
  <label for="navtoggle" class="burger" aria-label="Open menu"><span></span><span></span><span></span></label>
  <img class="tb-seal" src="assets/img/seal.png" alt="">
  <div class="tb-title"><?= e($pageTitle ?? 'Library') ?></div>
  <details class="profile">
    <summary aria-label="Your profile"><span class="avatar"><?= e($pInit) ?></span>
      <span class="who"><b><?= e($pName) ?></b><small><?= e(ucfirst($pRole)) ?></small></span>
      <svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>
    <div class="pmenu">
      <div class="phead"><span class="avatar lg"><?= e($pInit) ?></span>
        <div><b><?= e($pName) ?></b><small>@<?= e($pUser) ?></small></div></div>
      <span class="rolebadge"><?= e(ucfirst($pRole)) ?></span> <span class="rolebadge"><?= e(user_library()) ?></span>
      <p><?= e($roleInfo[$pRole] ?? '') ?></p>
      <a class="logout" href="logout.php">Log out</a>
    </div>
  </details>
</header>
<main class="wrap">
<?php if ($m = flash()): ?><div class="flash"><?= e($m) ?></div><?php endif; ?>
