<?php
require dirname(__DIR__).'/config/app.php';
require dirname(__DIR__).'/includes/layout.php';
require __DIR__.'/domain.php';
require __DIR__.'/workbench.php';
$u = g_actor();
// Capture before loading view data so concurrent updates cannot be hidden by a newer signature.
$initialSignature = g_signature($u);
$uid = (int)$u['id'];
$staff = g_staff($u);
$head = g_head($u);
if (isset($_GET['view']) && !is_string($_GET['view'])) g_denied('Invalid page.',400);
$view = (string)($_GET['view'] ?? 'overview');
$id = max(0, (int)($_GET['id'] ?? 0));
$titles = ['intake'=>'Guidance intake','review'=>'Request review','register'=>'Certificate register','cohort'=>'Cohort invitations','notifications'=>'Notifications','help'=>'Guidance help & services','operations'=>'Guidance operations','overview' => 'Guidance Office','requests' => 'Guidance requests','students' => 'Student directory','walkin' => 'Record a walk-in','appointments' => 'Appointments','events' => 'Activities','event' => 'Activity details','programs' => 'Programs','calendar' => 'Guidance calendar','history' => 'Certificates & credits','knowledge' => 'Resources','reports' => 'Aggregate reports','team' => 'Guidance team','categories' => 'Guidance services','privacy' => 'Privacy reviews','audit' => 'Guidance audit','settings' => 'Settings'];
if (!isset($titles[$view])) {
    g_denied('Page not found.', 404);
}if (in_array($view, ['reports','walkin'], true) && !$staff) {
    g_denied();
}if (in_array($view, ['team','categories','privacy','audit'], true) && !$head) {
    g_denied();
}if ($view === 'walkin' && $staff === 'administrator') {
    g_denied();
}
function gh(string $name, mixed $value): string
{
    return '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
}
function gf(string $name, string $label, string $type = 'text', mixed $value = '', string $attrs = ''): string
{
    return '<label class="field"><span class="lbl">'.e($label).'</span><input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" '.$attrs.'></label>';
}
function ga(string $name, string $label, string $value = '', string $attrs = ''): string
{
    return '<label class="field"><span class="lbl">'.e($label).'</span><textarea name="'.e($name).'" '.$attrs.'>'.e($value).'</textarea></label>';
}
function gs(string $name, string $label, array $items, mixed $value = '', string $attrs = ''): string
{
    $h = '<label class="field"><span class="lbl">'.e($label).'</span><select name="'.e($name).'" '.$attrs.'>';
    foreach ($items as $key => $text) {
        $h .= '<option value="'.e($key).'"'.((string)$key === (string)$value ? ' selected' : '').'>'.e($text).'</option>';
    }return $h.'</select></label>';
}
function gc(string $name, string $label, bool $checked = false): string
{
    return '<label class="gd-check"><input type="checkbox" name="'.e($name).'" value="1"'.($checked ? ' checked' : '').'>'.e($label).'</label>';
}
function gform(string $action, string $body, string $submit = 'Save'): string
{
    return '<form class="gd-form" method="post" action="'.e(url('guidance/action.php')).'" data-gform>'.csrf_field().gh('action', $action).'<div class="gu-form-body">'.$body.'</div><p class="gd-error" role="alert" tabindex="-1"></p><button class="btn btn-primary" type="submit">'.e($submit).'</button></form>';
}
function gl(string $view, string $text, int $id = 0, string $class = 'btn'): string
{
    return '<a class="'.e($class).'" href="'.e(url('guidance/index.php?view='.$view.($id ? '&id='.$id : ''))).'">'.e($text).'</a>';
}
function gcard(string $title, string $body): string
{
    return '<section class="card gd-card"><div class="card-h"><h3>'.e($title).'</h3></div><div class="gu-card-body">'.$body.'</div></section>';
}
function gempty(string $text = 'No records yet.'): string
{
    return '<div class="empty gd-empty">'.e($text).'</div>';
}
function gtable(array $headers, array $rows, string $empty = 'Nothing to show yet. New records will appear here when available.'): string
{
    if (!$rows) {
        return gempty($empty);
    }$h = '<div class="table-wrap gd-tablewrap"><table class="stack"><thead><tr>';
    foreach ($headers as $a) {
        $h .= '<th>'.e($a).'</th>';
    }$h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($row as $column => $cell) {
            $wide = str_contains($cell, '<form') || mb_strlen(strip_tags($cell)) > 120;
            $h .= '<td'.($wide?' class="gu-cell-wide"':'').' data-label="'.e($headers[$column] ?? '').'"><div class="gd-cell">'.$cell.'</div></td>';
        }$h .= '</tr>';
    }return $h.'</tbody></table></div>';
}
function gtag(string $t): string
{
    return '<span class="badge">'.e(ucwords(str_replace('_', ' ', $t))).'</span>';
}
function gdetails(string $text, string $body): string
{
    return '<details><summary>'.e($text).'</summary>'.$body.'</details>';
}
function gopts(array $values): array
{
    return array_combine($values, array_map(fn ($v) => ucwords(str_replace('_', ' ', $v)), $values));
}
function gtimeinput(string $s): string
{
    return $s ? str_replace(' ', 'T', substr($s, 0, 16)) : '';
}
function geform(array $e = []): string
{
    $programs = [0 => 'Standalone activity'] + array_column(gall('SELECT id,title FROM gd_programs ORDER BY title'), 'title', 'id');
    return gform('event.save', '<p class="gd-note">Save as Draft while planning. Publish when details are ready, then invite students from the activity page. Keep activity descriptions free of private counseling details.</p>'.gh('id', $e['id'] ?? 0).gf('title', 'Activity title', 'text', $e['title'] ?? '', 'required minlength="3" maxlength="160"').ga('description', 'Description', $e['description'] ?? '', 'maxlength="10000"').'<div class="gd-fields">'.gs('kind', 'Activity type', gopts(['seminar','workshop','assembly','orientation','testing','career_fair','recollection','wellbeing','other']), $e['kind'] ?? 'workshop').gs('status', 'Status', gopts(['draft','published','completed','cancelled']), $e['status'] ?? 'draft').gf('starts_at', 'Start · Philippine time', 'datetime-local', gtimeinput($e['starts_at'] ?? ''), 'required').gf('ends_at', 'End · Philippine time', 'datetime-local', gtimeinput($e['ends_at'] ?? ''), 'required').gf('venue', 'Venue', 'text', $e['venue'] ?? '', 'required maxlength="160"').gf('capacity', 'Capacity', 'number', $e['capacity'] ?? 30, 'min="1" max="100000" required').gs('program_id', 'Program', $programs, $e['program_id'] ?? 0).gf('session_no', 'Session number', 'number', $e['session_no'] ?? 1, 'min="1" max="10000"').gf('minutes_before', 'Check-in minutes before start', 'number', $e['minutes_before'] ?? 30, 'min="0" max="240"').gf('minutes_after', 'Check-in minutes after end', 'number', $e['minutes_after'] ?? 60, 'min="0" max="240"').'</div>'.gc('certificates_enabled', 'Certificates after completion and eligible attendance', (bool)($e['certificates_enabled'] ?? 0)).gf('credit_units', 'Participation credits', 'number', $e['credit_units'] ?? 0, 'min="0" max="1000" step="0.25"'), $e ? 'Save activity' : 'Create activity');
}
define('GUIDANCE_LAYOUT_SECTIONS',true);
require __DIR__.'/layout-sections.php';
$cases = gw_cases($u);
$events = $staff ? gall('SELECT * FROM gd_events ORDER BY starts_at DESC') : gall("SELECT e.* FROM gd_events e JOIN gd_invites i ON i.event_id=e.id WHERE i.user_id=? AND e.status<>'draft' ORDER BY e.starts_at DESC", [$uid]);
$appointments = gall('SELECT a.*,u.full_name AS requester,c.full_name AS counselor FROM gd_appointments a JOIN users u ON u.id=a.user_id JOIN users c ON c.id=a.counselor_id'.($head ? '' : ($staff === 'counselor' ? ' WHERE a.counselor_id='.$uid : ' WHERE a.user_id='.$uid)).' ORDER BY a.starts_at DESC');
$students = $staff ? gall("SELECT id,full_name,school_id FROM users WHERE role='student' AND status='active' AND verification='verified' ORDER BY full_name") : [];
$certs = gall('SELECT * FROM gd_certificates WHERE user_id=? ORDER BY issued_at DESC', [$uid]);
// Validate new GET routes before rendering headers; no write action is introduced here.
$workbenchFilters = [];
try {
    if ($view === 'requests') $workbenchFilters = gw_filters($_GET);
    if ($view === 'reports') $workbenchFilters = gw_report_filters($_GET);
    if ($view === 'students') {
        gx(in_array($staff, ['head','counselor'], true));
        $id = gw_id($_GET, 'id');
        $workbenchFilters = ['q'=>gw_text($_GET,'q'),'page'=>max(1,gw_id($_GET,'page')),'appointment_page'=>max(1,gw_id($_GET,'appointment_page'))];
        $workbenchDirectory = gw_directory($u);
        if ($id && !in_array($id, array_map('intval',array_column($workbenchDirectory,'id')),true)) g_denied('Student not found in your authorized directory.',404);
    }
} catch (RuntimeException $ex) {
    g_denied(in_array($ex->getCode(),[400,403],true)?$ex->getMessage():'Could not load this workspace.',in_array($ex->getCode(),[400,403],true)?$ex->getCode():500);
}
$grQuery=[];
try {
    if (gr_ready()) {
        $grQuery=gr_query($_GET);
        if (in_array($view,['register','cohort'],true)) gx($staff!==null);
        if ($view==='operations') gx($head);
        if (in_array($view,['intake','review'],true)) gx(is_filer($u)||in_array($staff,['head','counselor'],true));
        if ($view==='review'&&$id) gr_case($u,$id);
        if ($view==='cohort') g_event($u,$id);
    } elseif (in_array($view,['intake','review','register','cohort','notifications','help','operations'],true)) g_denied('The restoration extension needs its additive migration. Contact the Guidance head.',503);
} catch(RuntimeException $ex) { g_denied($ex->getMessage(),in_array($ex->getCode(),[400,403,404],true)?$ex->getCode():500); }
if ($view === 'event') {
    try { $layoutEvent=g_event($u,$id); } catch (RuntimeException $ex) { g_denied('Activity not found.',404); }
}
page_start(is_filer($u) && $view === 'overview' ? 'Guidance services' : $titles[$view], is_filer($u) ? 'Student portal · Guidance Office' : 'LCCT · Guidance Office');
?>
<link rel="stylesheet" href="<?= e(url('guidance/assets/guidance.css')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/guidance.css') ?>">
<?php if (in_array($view, ['requests','students','reports'],true)): ?><link rel="stylesheet" href="<?= e(url('guidance/assets/workbench.css')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/workbench.css') ?>"><?php endif; ?>
<?php if ($view === 'team'): ?><link rel="stylesheet" href="<?= e(url('guidance/assets/password.css')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/password.css') ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('guidance/assets/layout-pass.css')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/layout-pass.css') ?>">
<div id="guidance" data-nav-scope="<?= e(hash('sha256', $uid.':'.($staff ?? 'student'))) ?>" data-state="<?= e(url('guidance/state.php')) ?>" data-sig="<?= e($initialSignature) ?>" data-action="<?= e(url('guidance/action.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
<?php if (getenv('LCCT_SYNTHETIC_TEST') === '1'): ?><div class="alert warn">Synthetic local review database. No real school records or email delivery.</div><?php endif; ?>
<?php
if (is_filer($u)) { require __DIR__.'/student-pages.php'; }
if (is_filer($u) && $view === 'overview') {
    // The original student dashboard remains student/dashboard.php.
    // Guidance is a service directory, not another dashboard.
} elseif (gr_ready() && in_array($view,['intake','walkin','review','register','cohort','notifications','help','operations','knowledge','privacy','audit'],true)) {
    require __DIR__.'/restoration-view.php';
} elseif ($view === 'overview') {
    $lastCaseUpdate = $cases ? max(array_column($cases, 'updated_at')) : null;
    if ($staff === 'administrator') $lastCaseUpdate = $events ? max(array_column($events, 'updated_at')) : null;
    if (is_filer($u)) {
        $heroActions = '<a class="btn btn-primary" href="'.e(url(gr_ready()?'guidance/index.php?view=intake':'student/new_ticket.php?office=guide')).'">Request Guidance</a>'.gl('appointments', 'Book an appointment').gl('events', 'My activities');
    } elseif ($staff === 'administrator') {
        $heroActions = gl('events', 'Manage activities', 0, 'btn btn-primary').gl('programs', 'Manage programs').gl('reports', 'Aggregate reports');
    } else {
        $heroActions = gl('requests', $head ? 'Review requests' : 'Assigned requests', 0, 'btn btn-primary').gl('appointments', 'Appointments').gl('events', 'Activities');
    }
    $roleCaption = ['head'=>'Guidance Head','counselor'=>'Counselor','administrator'=>'Guidance Administrator'][$staff ?? ''] ?? 'Student Guidance';
    $welcome = is_filer($u) ? 'Request support, track your appointments, view your activities, and access helpful resources.' : ($staff === 'administrator' ? 'Plan activities, manage programs and follow participation.' : 'Review your authorized requests and upcoming appointments.');
    echo dash_hero($roleCaption, 'Hi '.e(explode(' ', $u['full_name'])[0]).', welcome back', $welcome, $lastCaseUpdate, $heroActions, $staff === 'administrator' ? 'No activities yet' : 'No requests yet');
    echo '<div class="stats">';
    $summary = [
        [count(array_filter($cases, fn ($t) => !in_array($t['status'], ['Closed','Cancelled','Resolved'], true))), 'Active requests', 'within your authorized access', 'blue', 'fa-inbox'],
        [count(array_filter($appointments, fn ($a) => in_array($a['status'], ['requested','confirmed'], true))), 'Bookings', 'requested or confirmed', 'gold', 'fa-calendar'],
        [count($events), 'Activities', 'available in your workspace', 'blue', 'fa-calendar-days'],
        [count($certs), 'My certificates', 'earned participation records', 'green', 'fa-award']
    ];
    if ($staff === 'administrator') {
        $summary = [
            [count(array_filter($events, fn($e)=>$e['status']==='published')), 'Published', 'activities ready for participants', 'blue', 'fa-calendar-days'],
            [count(array_filter($events, fn($e)=>$e['status']==='draft')), 'Drafts', 'activities being planned', 'gold', 'fa-pen'],
            [(int)gq('SELECT COUNT(*) FROM gd_programs WHERE active=1')->fetchColumn(), 'Programs', 'active Guidance programs', 'blue', 'fa-seedling'],
            [count(array_filter($events, fn($e)=>$e['status']==='completed')), 'Completed', 'finished Guidance activities', 'green', 'fa-circle-check']
        ];
    }
    foreach ($summary as [$n,$label,$hint,$color,$icon]) {
        echo '<div class="stat" style="--c:var(--'.e($color).')"><i class="fa-solid '.e($icon).' ic" aria-hidden="true"></i><div class="k">'.e($label).'</div><div class="v">'.$n.'</div><div class="s">'.e($hint).'</div></div>';
    }
    echo '</div>';
    require __DIR__.'/overview.php';
} elseif (in_array($view, ['requests','students'],true)) {
    require __DIR__.'/workbench-view.php';
} elseif ($view === 'walkin') {
    echo gcard('Record on behalf of a student', gform('walkin', gs('user_id', 'Student requester', array_column($students, 'full_name', 'id'), '', 'required').gs('category_id', 'Guidance service', array_column(gall("SELECT id,name FROM categories WHERE office_code='guide' AND is_hidden=0"), 'name', 'id'), '', 'required').gf('subject', 'Subject', 'text', '', 'required minlength="5" maxlength="150"').ga('details', 'Initial details', '', 'required minlength="10" maxlength="20000"').gs('priority', 'Priority', gopts(PRIORITIES), 'Normal').($head ? gs('assigned_to', 'Counselor', [0 => 'Unassigned'] + array_column(g_counselors(), 'full_name', 'id')) : '<p>This case will be assigned to you.</p>').'<div class="gd-note">The selected student owns this shared ticket. You are separately recorded as its creator.</div>', 'Create shared ticket'));
} elseif ($view === 'appointments') {
    gu_appointments($u,$appointments);
} elseif ($view === 'events') {
    if ($staff) {
        echo gcard('Plan an activity', gdetails('Create a Guidance activity', geform()));
    }echo '<div class="gd-grid gd-three">';
    foreach ($events as $e) {
        echo gcard($e['title'], '<span class="gd-eyebrow">'.e(str_replace('_', ' ', $e['kind'])).'</span><p>'.e(fmt_dt($e['starts_at'])).'<br>'.e($e['venue']).'</p><p>'.e(mb_strimwidth($e['description'], 0, 150, '…')).'</p><div class="gd-buttons">'.gtag($e['status']).gl('event', 'Open activity', (int)$e['id']).'</div>');
    }echo '</div>';
    if (!$events) {
        echo gempty($staff ? 'No activities yet. Open Create a Guidance activity above to plan one.' : 'No activity invitations yet. When Guidance invites you, open the activity to accept or decline.');
    }
} elseif ($view === 'event') {
    gu_event($u,$layoutEvent,$students);
} elseif ($view === 'programs') {
    if ($staff) {
        echo gcard('Add a Guidance program', gdetails('Create program', gform('program.save', '<p>Group related activities under a program, such as a study-skills series. Create and schedule each session separately.</p>'.gf('title', 'Program title', 'text', '', 'required minlength="3" maxlength="160" placeholder="For example: Study skills series"').ga('description', 'Description', '', 'maxlength="5000"').gc('active', 'Active program', true), 'Create program')));
    }
    $programRows = gall('SELECT * FROM gd_programs'.($staff ? '' : ' WHERE active=1').' ORDER BY title');
    if (!$programRows) echo gempty($staff ? 'No programs yet. Create one to group related activity sessions.' : 'No programs are available yet. You can still check Activities for your invitations.');
    foreach ($programRows as $p) {
        $body = '<p>'.e($p['description']).'</p>';
        foreach ($events as $e) {
            if ((int)$e['program_id'] === (int)$p['id']) {
                $body .= '<p>'.gl('event', 'Session '.$e['session_no'].' · '.$e['title'], (int)$e['id']).'</p>';
            }
        }if ($staff) {
            $body .= gdetails('Edit program', gform('program.save', gh('id', $p['id']).gf('title', 'Title', 'text', $p['title'], 'required').ga('description', 'Description', $p['description']).gc('active', 'Active', (bool)$p['active'])));
        }echo gcard($p['title'], $body);
    }
} elseif ($view === 'calendar') {
    $rows = [];
    foreach ($events as $e) {
        $rows[] = [$e['starts_at'],gl('event', $e['title'], (int)$e['id']),gtag($e['status'])];
    }foreach ($appointments as $a) {
        $rows[] = [$a['starts_at'],gl('appointments', 'Appointment · '.$a['counselor']),gtag($a['status'])];
    }usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));
    echo gcard('Activities and appointments', gtable(['When · Philippine time','Activity','Status'], array_map(fn ($r) => [e(fmt_dt($r[0])),$r[1],$r[2]], $rows), 'Your appointments and available activities will appear here. Check Appointments or Activities to get started.'));
} elseif ($view === 'history') {
    $inv = gall('SELECT i.*,e.title FROM gd_invites i JOIN gd_events e ON e.id=i.event_id WHERE i.user_id=? ORDER BY e.starts_at DESC', [$uid]);
    $rows = [];
    foreach ($inv as $i) {
        $cert = null;
        foreach ($certs as $c) {
            if ((int)$c['event_id'] === (int)$i['event_id']) {
                $cert = $c;
            }
        }$rows[] = [gl('event', $i['title'], (int)$i['event_id']),gtag($i['state']),gtag($i['attendance']),$cert ? '<a href="'.e(url('guidance/certificate.php?id='.$cert['id'])).'" target="_blank" rel="noopener">Print certificate · '.e($cert['credits']).' credits</a>' : 'Not issued'];
    }echo gcard('Your milestones', '<p class="gd-credit">'.e(array_sum(array_column($certs, 'credits'))).' credits earned</p>'.gtable(['Activity','RSVP','Attendance','Certificate'], $rows, 'No participation history yet. Accept an activity invitation to begin. Eligible certificates appear here after attendance and activity completion.'));
} elseif ($view === 'knowledge') {
    if ($staff) {
        echo gcard('Publish support information', gdetails('Write an article', gform('article.save', gf('title', 'Title', 'text', '', 'required minlength="3" maxlength="160"').ga('body', 'Article', '', 'required minlength="10" maxlength="30000"').gc('published', 'Publish for students'), 'Save article')));
    }
    $articles = gall('SELECT * FROM gd_articles'.($staff ? '' : ' WHERE published=1').' ORDER BY updated_at DESC');
    foreach ($articles as $a) {
        echo gcard($a['title'], ((int)$a['published'] ? '' : gtag('draft')).'<p class="gd-pre">'.e($a['body']).'</p>'.($staff ? gdetails('Edit article', gform('article.save', gh('id', $a['id']).gf('title', 'Title', 'text', $a['title'], 'required').ga('body', 'Article', $a['body'], 'required').gc('published', 'Published', (bool)$a['published']))) : ''));
    }if (!$articles) {
        echo gempty('No resources have been published yet. You can still request Guidance or book an appointment.');
    }
} elseif ($view === 'reports') {
    require __DIR__.'/workbench-view.php';
} elseif ($view === 'categories') {
    gu_services();
} elseif ($view === 'team') {
    gu_team($u);
} elseif ($view === 'privacy') {
    $rows = gall('SELECT p.*,u.full_name FROM gd_privacy p JOIN users u ON u.id=p.user_id ORDER BY p.updated_at DESC');
    foreach ($rows as $p) {
        echo gcard('Privacy review · '.$p['full_name'], gtag($p['status']).'<p class="gd-pre">'.e($p['reason']).'</p>'.gform('privacy.review', gh('id', $p['id']).ga('response', 'Review response', $p['response'] ?? '', 'required minlength="10" maxlength="4000"'), 'Record review'));
    }if (!$rows) {
        echo gempty('No privacy requests.');
    }
} elseif ($view === 'audit') {
    $rows = gall("SELECT created_at,username,action,description FROM audit_logs WHERE office_code='guide' ORDER BY id DESC LIMIT 500");
    echo gcard('Actions, not counseling content', gtable(['When','Actor','Action','Reference'], array_map(fn ($r) => array_map('e', array_values($r)), $rows)));
} elseif ($view === 'settings') {
    gu_settings($u);
}
// Context panels are now rendered inside their owning workspace, not appended twice.
?>
<script src="<?= e(url('guidance/assets/sidebar-state.js')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/sidebar-state.js') ?>" defer></script>
<p class="gd-foot">Use your campus account for Guidance requests, appointments and activities. All times shown are Philippine local time. <span id="gd-live">Live updates · draft protection enabled</span></p>
</div>
<dialog id="gd-camera" aria-labelledby="gd-camera-title"><h2 id="gd-camera-title">Guidance QR check-in</h2><video autoplay muted playsinline></video><p id="gd-camera-note">Camera needs HTTPS or localhost. Manual entry remains available.</p><button class="btn" type="button" data-camera-close>Close camera</button></dialog>
<script src="<?= e(url('guidance/assets/vendor/qrcode.js')) ?>"></script><script src="<?= e(url('guidance/assets/vendor/jsQR.js')) ?>"></script><script src="<?= e(url('guidance/assets/guidance.js')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/guidance.js') ?>" defer></script>
<?php if ($view === 'team'): ?><script src="<?= e(url('guidance/assets/password.js')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/password.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'overview' && !is_filer($u)): ?><script src="<?= e(url('guidance/assets/overview.js')) ?>?v=<?= (int)filemtime(__DIR__.'/assets/overview.js') ?>" defer></script><?php endif; ?>
<?php page_end(); ?>
