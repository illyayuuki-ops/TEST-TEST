<?php
/** Shared page shell: page_start() / page_end(). */

function tint(string $hex, float $a = .14): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return "rgba(0,212,255,$a)";
    return sprintf('rgba(%d,%d,%d,%s)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $a);
}
function office_icon(array $o, int $size = 22): string {
    return '<i class="fa-solid ' . e($o['brand_icon']) . '" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:7px;display:inline-grid;place-items:center;font-size:' . round($size * .45) . 'px;background:'
        . tint($o['brand_color']) . ';color:' . e($o['brand_color']) . '"></i>';
}
function office_chip(array $o): string {
    return '<span class="off-chip">' . office_icon($o) . e($o['office_name']) . '</span>';
}

/** Round profile picture (or the first letter of the name when there is none). */
function avatar_html(string $name, ?string $path, int $size = 38): string {
    $st = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .42) . 'px;flex-shrink:0';
    if ($path) return '<img class="avatar" style="' . $st . ';object-fit:cover" src="' . e(url('avatar.php?v=' . rawurlencode($path))) . '" alt="">';
    return '<div class="avatar" style="' . $st . '">' . e(strtoupper(substr($name, 0, 1))) . '</div>';
}

/** Delivery-app style progress tracker for a ticket. $full = bigger version with dates (ticket page); compact = for cards. */
function ticket_tracker(array $t, bool $full = false): string {
    $s = $t['status'];
    $steps = [['Received', 'fa-inbox'], ['In progress', 'fa-screwdriver-wrench'], ['Resolved', 'fa-circle-check'], ['Closed', 'fa-flag-checkered']];
    $cur = match ($s) { 'Open', 'Reopened' => 0, 'In Progress', 'Waiting for User', 'Escalated' => 1, 'Resolved' => 2, 'Closed' => 3, default => -1 };
    [$head, $note] = match ($s) {
        'Open'             => ['Ticket received', 'The office has your request and will pick it up soon.'],
        'Reopened'         => ['Ticket reopened', 'Back in the office\'s queue.'],
        'In Progress'      => ['Being handled', 'The office is working on your ticket.'],
        'Escalated'        => ['Being prioritized', 'It took longer than the target time, so it was escalated.'],
        'Waiting for User' => ['Waiting for your reply', 'The office needs an answer from you to continue.'],
        'Resolved'         => ['Resolved', 'Not fixed? Reply and it will be reopened.'],
        'Closed'           => ['Closed', 'This ticket is finished.'],
        default            => ['Cancelled', 'This ticket was cancelled.'],
    };
    $h = '<div class="trk' . ($full ? ' full' : '') . ($cur < 0 ? ' off' : '') . '"><div class="trk-head"><b>' . e($head) . '</b>' . ($full ? '<span>' . e($note) . '</span>' : '') . '</div><ol class="trk-steps">';
    foreach ($steps as $i => [$label, $icon]) {
        $state = $cur < 0 ? 'todo' : ($i < $cur || ($i === $cur && in_array($s, ['Resolved', 'Closed'], true)) ? 'done' : ($i === $cur ? 'now' : 'todo'));
        if ($state === 'now' && $s === 'Waiting for User') $state .= ' st-warn';
        if ($state === 'now' && $s === 'Escalated') $state .= ' st-alert';
        $sub = '';
        if ($full) {
            if ($i === 0) $sub = date('M j, g:i A', strtotime($t['created_at']));
            elseif ($i === 2 && !empty($t['resolved_at'])) $sub = date('M j, g:i A', strtotime($t['resolved_at']));
            elseif ($state !== 'todo' && str_starts_with($state, 'now')) $sub = $s === 'Waiting for User' ? 'Needs your reply' : ($s === 'Escalated' ? 'Prioritized' : 'Now');
        }
        $h .= '<li class="' . $state . '"><span class="dot"><i class="fa-solid ' . ($state === 'now st-warn' ? 'fa-comment-dots' : $icon) . '"></i></span><span class="lb">' . e($label) . '</span>' . ($sub !== '' ? '<span class="sb">' . e($sub) . '</span>' : '') . '</li>';
    }
    return $h . '</ol></div>';
}

function nav_for(array $u): array {
    if ($u['role']==='admin' && ($u['office_code']??'')==='guide') return g_nav($u);
    if ($u['role'] === 'admin') {
        $nav = [
            ['Tickets', null, null],
            ['Queue', 'admin/dashboard.php', 'fa-inbox'],
            ['Ticket History', 'admin/records.php', 'fa-clock-rotate-left'],
            ['Reports', 'admin/reports.php', 'fa-chart-column'],
            ['Office', null, null],
            ['Categories', 'admin/categories.php', 'fa-tags'],
            ['Team', 'admin/team.php', 'fa-user-shield'],
            ['Audit Trail', 'admin/audit.php', 'fa-clipboard-list'],
        ];
        return array_merge($nav, [['Account', null, null], ['Password', 'auth/change_password.php', 'fa-key']]);
    }
    return [
        ['Tickets', null, null],
        ['My Tickets', 'student/dashboard.php', 'fa-ticket'],
        ['Ticket History', 'student/history.php', 'fa-clock-rotate-left'],
        ['New Ticket', 'student/new_ticket.php', 'fa-plus'],
        ['Account', null, null],
        ['Profile', 'student/profile.php', 'fa-id-badge'],
        ['Password', 'auth/change_password.php', 'fa-key'],
        ['Guidance Office', null, null],
        ['Guidance services','guidance/index.php','fa-people-group'],
    ];
}

/** Exact date and time, e.g. "Oct 27, 2026 4:15 PM". */
function fmt_dt(?string $ts): string { return $ts ? date('M j, Y g:i A', strtotime($ts)) : '—'; }

/** Campus-photo banner at the top of a dashboard: greeting, short line, the last update time and action buttons. */
function dash_hero(string $eyebrow, string $title, string $text, ?string $lastTs, string $actions = '', string $emptyLabel = 'No tickets yet'): string {
    return '<section class="hero" style="--hero:url(\'' . e(url('assets/img/campus-banner.jpg')) . '\')">'
        . '<div class="hero-in"><div class="hero-txt"><span class="hero-eye">' . $eyebrow . '</span><h2>' . $title . '</h2><p>' . $text . '</p>'
        . ($actions ? '<div class="hero-act">' . $actions . '</div>' : '') . '</div>'
        . '<div class="hero-last"><span class="hl-k"><i class="fa-regular fa-clock"></i>Last update</span><b>' . e(fmt_dt($lastTs)) . '</b>'
        . ($lastTs ? '<small>' . e(time_ago($lastTs)) . '</small>' : '<small>'.e($emptyLabel).'</small>') . '</div></div></section>';
}

/** Guidance uses query-string views; leave other shared routes' matching unchanged. */
function nav_is_active(string $currentPath, string $href): bool {
    // Students keep the original campus sidebar on every Guidance page.
    if (is_filer($_SESSION['user']) && $currentPath === 'guidance/index.php' && $href === 'guidance/index.php') return true;
    // Staff account subpages live under a single Settings entry.
    if ($href === 'student/settings.php' && in_array($currentPath, ['student/profile.php','auth/change_password.php'], true)) return true;
    if ($href === 'guidance/index.php?view=settings' && $currentPath === 'auth/change_password.php') return true;
    if ($currentPath !== 'guidance/index.php' || parse_url($href, PHP_URL_PATH) !== 'guidance/index.php') {
        return $currentPath === $href;
    }
    parse_str((string)(parse_url($href, PHP_URL_QUERY) ?? ''), $linkQuery);
    $view = (string)($_GET['view'] ?? 'overview');
    $view = ['event' => 'events', 'walkin' => 'requests', 'intake'=>'requests', 'review'=>'requests', 'cohort'=>'events', 'team' => 'settings', 'categories' => 'settings'][$view] ?? $view;
    return ($linkQuery['view'] ?? 'overview') === $view;
}

function page_start(string $title, string $sub = '', string $actions = ''): void {
    global $pdo;
    $u = $_SESSION['user'];
    $cur = ltrim(substr($_SERVER['SCRIPT_NAME'], strlen(APP_BASE)), '/');
    $roleLabel = ['admin' => 'Office Admin', 'student' => 'Student'][$u['role']];
    $guidanceRole = g_staff($u);
    $accountMenu = is_filer($u) || $guidanceRole !== null;
    if ($guidanceRole) $roleLabel = ['head'=>'Guidance Head','counselor'=>'Counselor','administrator'=>'Guidance Administrator'][$guidanceRole];
    $settingsHref = ($guidanceRole || defined('GD_PASSWORD_PAGE') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/guidance/')) ? 'guidance/index.php?view=settings' : 'student/settings.php';
    $navigation = nav_for($u);
    $hasNavGroups = (bool)array_filter($navigation, fn($item) => isset($item[3]) && is_array($item[3]));
    $nUnread = notif_unread($pdo, (int)$u['id']); $nItems = notif_recent($pdo, (int)$u['id'], 8);
    $avatar = null;
    if (is_filer($u)) { try { $av = $pdo->prepare('SELECT avatar_path FROM users WHERE id=?'); $av->execute([$u['id']]); $avatar = $av->fetchColumn() ?: null; } catch (Throwable $e) { /* photo column not added yet */ } }
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= APP_NAME_A . APP_NAME_B ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= url('assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
<?php if ($hasNavGroups): ?><link rel="stylesheet" href="<?= e(url('guidance/assets/sidebar.css')) ?>?v=<?= (int)filemtime(__DIR__.'/../guidance/assets/sidebar.css') ?>"><?php endif; ?>
</head><body>
<aside class="sidebar" id="sidebar">
  <div class="sb-head"><img width="48" height="48" src="<?= url('assets/img/lcct-seal.png') ?>" alt="LCCT seal"><div><div class="brand"><?= APP_NAME_A ?><span><?= APP_NAME_B ?></span></div><small>College Helpdesk</small></div></div>

  <?php if ($u['role'] === 'admin'): $o = get_office($u['office_code']); ?>
    <div class="sb-ctx"><div class="k">Your office</div><div class="v"><span class="oi" style="background:<?= tint($o['brand_color']) ?>;color:<?= e($o['brand_color']) ?>"><i class="fa-solid <?= e($o['brand_icon']) ?>"></i></span><?= e($o['office_name']) ?></div></div>
  <?php endif; ?>

  <nav class="nav">
    <?php foreach ($navigation as $item): [$label, $href, $icon] = $item; ?>
      <?php if (isset($item[3]) && is_array($item[3])):
          $children = $item[3];
          $groupActive = (bool)array_filter($children, fn($child) => nav_is_active($cur, $child[1])); ?>
        <details class="gd-nav-group<?= $groupActive ? ' has-active' : '' ?>"<?= $groupActive ? ' open' : '' ?>>
          <summary><i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span><svg class="gd-nav-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></summary>
          <div class="gd-nav-group-links">
          <?php foreach ($children as [$childLabel, $childHref, $childIcon]): $isActive = nav_is_active($cur, $childHref); ?>
            <a href="<?= e(url($childHref)) ?>" class="<?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= e($childIcon) ?>" aria-hidden="true"></i><?= e($childLabel) ?></a>
          <?php endforeach; ?>
          </div>
        </details>
      <?php elseif ($href === null): ?><div class="nav-sec"><?= e($label) ?></div>
      <?php else: $isActive = nav_is_active($cur, $href); ?><a href="<?= url($href) ?>" class="<?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= $icon ?>"></i><?= e($label) ?></a><?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="sb-credit"><img height="30" src="<?= url('assets/img/intel-logo.png') ?>" alt="INTEL"><div>Powered by<b>INTEL</b></div></div>
  <?php if (!$accountMenu): ?>
  <div class="sb-user">
    <div class="avatar"><?= e(strtoupper(substr($u['full_name'], 0, 1))) ?></div>
    <div class="who"><div class="n"><?= e($u['full_name']) ?></div><div class="r"><?= $roleLabel ?></div></div>
    <a href="<?= url('auth/logout.php') ?>" title="Sign out"><i class="fa-solid fa-right-from-bracket"></i></a>
  </div>
  <?php endif; ?>
</aside>
<main class="main">
  <div class="topbar">
    <button class="menu-btn" aria-label="Open navigation" onclick="document.getElementById('sidebar').classList.toggle('open')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <div><div class="page-title"><?= e($title) ?></div><?php if ($sub): ?><div class="page-sub"><?= $sub ?></div><?php endif; ?></div>
    <div class="spacer"></div><?= $actions ?>
    <div class="bell" id="bell" data-ep="<?= e(url('notif.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
      <button type="button" class="bell-btn" id="bellBtn" aria-haspopup="true" aria-expanded="false" title="Notifications"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="bell-n" id="bellN"<?= $nUnread ? '' : ' hidden' ?>><?= $nUnread > 99 ? '99+' : $nUnread ?></span></button>
      <div class="bell-menu" id="bellMenu" role="menu">
        <div class="bell-h"><b>Notifications</b><button type="button" id="bellAll" class="bell-all"<?= $nUnread ? '' : ' hidden' ?>>Mark all as read</button></div>
        <div class="bell-list" id="bellList"><?= notif_items_html($nItems) ?></div>
      </div>
    </div>
    <?php if ($accountMenu): ?><div class="tb-user" id="tbUser">
      <button type="button" class="who" id="tbBtn" aria-haspopup="true" aria-expanded="false" title="Account"><?= avatar_html($u['full_name'], $avatar, 40) ?><span class="nm"><?= e($u['full_name']) ?><small><?= $roleLabel ?></small></span><i class="fa-solid fa-chevron-down chev"></i></button>
      <div class="tb-menu" id="tbMenu" role="menu">
        <?php if (is_filer($u)): ?>
        <a class="tb-head" href="<?= url('student/profile.php') ?>"><?= avatar_html($u['full_name'], $avatar, 46) ?><span><b><?= e($u['full_name']) ?></b><small>See your profile</small></span></a>
        <div class="tb-sep"></div>
        <a role="menuitem" href="<?= url('student/profile.php') ?>"><i class="fa-solid fa-user"></i>My profile</a>
        <a role="menuitem" href="<?= url('auth/change_password.php') ?>"><i class="fa-solid fa-key"></i>Change password</a>
        <div class="tb-sep"></div>
        <?php else: ?>
        <a class="tb-head" href="<?= e(url($settingsHref)) ?>"><?= avatar_html($u['full_name'], $avatar, 46) ?><span><b><?= e($u['full_name']) ?></b><small>Account settings</small></span></a>
        <div class="tb-sep"></div>
        <a role="menuitem" href="<?= e(url($settingsHref)) ?>"><i class="fa-solid fa-gear"></i>Settings</a>
        <div class="tb-sep"></div>
        <?php endif; ?>
        <a role="menuitem" class="tb-out" href="<?= url('auth/logout.php') ?>"><i class="fa-solid fa-right-from-bracket"></i>Sign out</a>
      </div>
    </div><?php endif; ?>
  </div>
  <div class="content">
  <?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?>
    <div class="alert <?= $t === 'err' ? 'err' : ($t === 'warn' ? 'warn' : 'ok') ?>"><i class="fa-solid <?= $t === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i><span><?= e($m) ?></span></div>
  <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

/** Fingerprint of what the person is looking at: one ticket ($ticketId > 0) or their ticket list. Changes whenever status, replies or assignment change. */
function live_signature(PDO $pdo, array $u, int $ticketId = 0): string {
    $isAdmin = $u['role'] === 'admin';
    if ($ticketId > 0) {
        $q = $pdo->prepare('SELECT t.status, t.updated_at, t.assigned_to, t.priority, t.office_code, t.user_id, (SELECT COUNT(*) FROM replies r WHERE r.ticket_id=t.id' . ($isAdmin ? '' : ' AND r.is_internal=0') . ') AS n FROM tickets t WHERE t.id=?');
        $q->execute([$ticketId]); $t = $q->fetch();
        if (!$t || ($isAdmin ? $t['office_code'] !== ($u['office_code'] ?? null) : (int)$t['user_id'] !== (int)$u['id'])) return '';
        if ($t['office_code']==='guide'&&!g_case($u,$t)) return '';
        return md5(implode('|', [$t['status'], $t['updated_at'], $t['assigned_to'], $t['priority'], $t['n']]));
    }
    if ($isAdmin && ($u['office_code']??'')==='guide') return g_signature($u);
    $q = $pdo->prepare('SELECT COUNT(*) n, COALESCE(MAX(updated_at),0) m, COALESCE(GROUP_CONCAT(status ORDER BY id SEPARATOR \',\'),\'\') s FROM tickets WHERE ' . ($isAdmin ? 'office_code=?' : 'user_id=?'));
    $q->execute([$isAdmin ? ($u['office_code'] ?? '') : (int)$u['id']]); $r = $q->fetch();
    return md5($r['n'] . '|' . $r['m'] . '|' . $r['s']);
}

/** Script that quietly checks for changes every few seconds and reloads the page. It never reloads while the person is typing or has a pop-up open; then it shows a "tap to refresh" bar instead. */
function live_refresh_script(): void {
    global $pdo;
    $u = $_SESSION['user'] ?? null; if (!$u) return;
    $page = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $tid = 0;
    if (str_ends_with($page, '/ticket.php')) $tid = (int)($_GET['id'] ?? 0);
    elseif (!preg_match('#/(student/(dashboard|history)|admin/(dashboard|records))\.php$#', $page)) return;   // only the ticket page and the two dashboards
    $sig = live_signature($pdo, $u, $tid);
    ?>
<script>
(function(){
  var sig=<?= json_encode($sig) ?>, ep=<?= json_encode(url('live.php') . ($tid ? '?t=' . $tid : '')) ?>, EVERY=3000, busy=false, bar=null, dirty=false;
  document.addEventListener('input',function(e){if(e.target.closest('form')&&e.target.name!=='csrf')dirty=true});
  document.addEventListener('change',function(e){if(e.target.closest('form'))dirty=true});
  function typing(){
    if(dirty)return true;
    var a=document.activeElement; if(a&&/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)&&a.type!=='checkbox'&&a.type!=='button'&&a.type!=='submit') return true;
    var f=document.querySelectorAll('#replybox textarea, form textarea'); for(var i=0;i<f.length;i++) if(f[i].value.trim()) return true;
    return !!document.querySelector('.modal-bg.open');
  }
  function notice(){
    if(bar) return; bar=document.createElement('div');
    bar.style.cssText='position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:9999;background:#1B3355;color:#fff;padding:10px 16px;border-radius:999px;font:600 13px/1.2 sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer';
    bar.innerHTML='<i class="fa-solid fa-rotate"></i>&nbsp; This page has new updates. Tap to refresh.';
    bar.onclick=function(){if(!typing()||confirm('Discard your unsaved changes and refresh?'))location.reload()}; document.body.appendChild(bar);
  }
  function tick(){
    if(busy||document.hidden||!sig) return; busy=true;
    fetch(ep,{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null}).then(function(j){
      busy=false; if(!j||!j.sig||j.sig===sig) return;
      if(typing()) notice(); else location.reload();
    }).catch(function(){busy=false});
  }
  setInterval(tick,EVERY);window.addEventListener('focus',tick);window.addEventListener('online',tick);
  document.addEventListener('visibilitychange',function(){if(!document.hidden) tick()});
})();
</script>
<?php }

function page_end(): void { ?>
  </div>
</main>
<script src="<?= url('assets/js/pwtoggle.js') ?>"></script>
<script>
(function(){var w=document.getElementById('tbUser'),b=document.getElementById('tbBtn');if(!w||!b)return;
function set(o){w.classList.toggle('open',o);b.setAttribute('aria-expanded',o?'true':'false')}
b.addEventListener('click',function(e){e.stopPropagation();set(!w.classList.contains('open'))});
document.addEventListener('click',function(e){if(!w.contains(e.target))set(false)});
document.addEventListener('keydown',function(e){if(e.key==='Escape')set(false)});})();
function openModal(id){document.getElementById(id).classList.add('open')}
function closeModal(id){document.getElementById(id).classList.remove('open')}
document.querySelectorAll('.modal-bg').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open')}));
</script>
<script>
(function(){
  var b=document.getElementById('bell'); if(!b) return;
  var btn=document.getElementById('bellBtn'), n=document.getElementById('bellN'), list=document.getElementById('bellList'), all=document.getElementById('bellAll'), ep=b.dataset.ep, last=0, busy=false;
  function open(o){b.classList.toggle('open',o);btn.setAttribute('aria-expanded',o?'true':'false')}
  btn.addEventListener('click',function(e){e.stopPropagation();open(!b.classList.contains('open'))});
  document.addEventListener('click',function(e){if(!b.contains(e.target))open(false)});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')open(false)});
  function apply(j){
    if(!j||typeof j.count==='undefined')return;
    n.textContent=j.count>99?'99+':j.count; n.hidden=!j.count; all.hidden=!j.count; list.innerHTML=j.html;
    if(last&&j.last>last&&j.count){btn.classList.remove('ring');void btn.offsetWidth;btn.classList.add('ring')}
    last=j.last;
  }
  function poll(){ if(busy||document.hidden)return; busy=true;
    fetch(ep+'?action=poll',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null}).then(function(j){busy=false;apply(j)}).catch(function(){busy=false}); }
  all.addEventListener('click',function(){
    var f=new FormData(); f.append('action','readall'); f.append('csrf',b.dataset.csrf);
    fetch(ep,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()}).then(apply).catch(function(){});
  });
  var first=list.querySelector('.bell-item'); last=first?parseInt((first.getAttribute('href').match(/go=(\d+)/)||[0,0])[1],10):0;
  setInterval(poll,20000); document.addEventListener('visibilitychange',function(){if(!document.hidden)poll()});
})();
</script>
<?php live_refresh_script(); ?>
</body></html>
<?php }

/** Simple pager. $params = current GET filters (without 'page'). */
function pager(int $total, int $per, int $page, array $params = []): void {
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return;
    $link = fn(int $p) => '?' . http_build_query(array_merge($params, ['page' => $p]));
    echo '<div class="pager">';
    if ($page > 1) echo '<a href="' . e($link($page - 1)) . '">‹ Prev</a>';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++)
        echo $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . e($link($i)) . '">' . $i . '</a>';
    if ($page < $pages) echo '<a href="' . e($link($page + 1)) . '">Next ›</a>';
    echo '</div>';
}
