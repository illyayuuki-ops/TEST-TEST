<?php
require dirname(__DIR__).'/config/app.php';
require dirname(__DIR__).'/includes/layout.php';
require __DIR__.'/domain.php';
$u = g_actor();
// Capture before loading view data so concurrent updates cannot be hidden by a newer signature.
$initialSignature = g_signature($u);
$uid = (int)$u['id'];
$staff = g_staff($u);
$head = g_head($u);
$view = (string)($_GET['view'] ?? 'overview');
$id = max(0, (int)($_GET['id'] ?? 0));
$titles = ['overview' => 'Guidance Office','requests' => 'Guidance requests','walkin' => 'Record a walk-in','appointments' => 'Appointments','events' => 'Activities & events','event' => 'Activity details','programs' => 'Recurring programs','calendar' => 'Guidance calendar','history' => 'Participation & credits','knowledge' => 'Knowledge & support','reports' => 'Aggregate reports','team' => 'Guidance team','categories' => 'Guidance services','privacy' => 'Privacy reviews','audit' => 'Guidance audit','settings' => 'My Guidance settings'];
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
    return '<label class="field"><span>'.e($label).'</span><input name="'.e($name).'" type="'.e($type).'" value="'.e($value).'" '.$attrs.'></label>';
}
function ga(string $name, string $label, string $value = '', string $attrs = ''): string
{
    return '<label class="field"><span>'.e($label).'</span><textarea name="'.e($name).'" '.$attrs.'>'.e($value).'</textarea></label>';
}
function gs(string $name, string $label, array $items, mixed $value = '', string $attrs = ''): string
{
    $h = '<label class="field"><span>'.e($label).'</span><select name="'.e($name).'" '.$attrs.'>';
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
    return '<form class="gd-form" method="post" action="'.e(url('guidance/action.php')).'" data-gform>'.csrf_field().gh('action', $action).$body.'<p class="gd-error" role="alert"></p><button class="btn btn-primary" type="submit">'.e($submit).'</button></form>';
}
function gl(string $view, string $text, int $id = 0, string $class = 'btn'): string
{
    return '<a class="'.e($class).'" href="'.e(url('guidance/index.php?view='.$view.($id ? '&id='.$id : ''))).'">'.e($text).'</a>';
}
function gcard(string $title, string $body): string
{
    return '<section class="card gd-card"><h2>'.e($title).'</h2>'.$body.'</section>';
}
function gempty(string $text = 'No records yet.'): string
{
    return '<div class="gd-empty">'.e($text).'</div>';
}
function gtable(array $headers, array $rows): string
{
    if (!$rows) {
        return gempty();
    }$h = '<div class="gd-tablewrap"><table><thead><tr>';
    foreach ($headers as $a) {
        $h .= '<th>'.e($a).'</th>';
    }$h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($row as $cell) {
            $h .= '<td>'.$cell.'</td>';
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
    return gform('event.save', gh('id', $e['id'] ?? 0).gf('title', 'Activity title', 'text', $e['title'] ?? '', 'required minlength="3" maxlength="160"').ga('description', 'Description', $e['description'] ?? '', 'maxlength="10000"').'<div class="gd-fields">'.gs('kind', 'Activity type', gopts(['seminar','workshop','assembly','orientation','testing','career_fair','recollection','wellbeing','other']), $e['kind'] ?? 'workshop').gs('status', 'Status', gopts(['draft','published','completed','cancelled']), $e['status'] ?? 'draft').gf('starts_at', 'Start · Philippine time', 'datetime-local', gtimeinput($e['starts_at'] ?? ''), 'required').gf('ends_at', 'End · Philippine time', 'datetime-local', gtimeinput($e['ends_at'] ?? ''), 'required').gf('venue', 'Venue', 'text', $e['venue'] ?? '', 'required maxlength="160"').gf('capacity', 'Capacity', 'number', $e['capacity'] ?? 30, 'min="1" max="100000" required').gs('program_id', 'Program', $programs, $e['program_id'] ?? 0).gf('session_no', 'Session number', 'number', $e['session_no'] ?? 1, 'min="1" max="10000"').gf('minutes_before', 'Check-in minutes before start', 'number', $e['minutes_before'] ?? 30, 'min="0" max="240"').gf('minutes_after', 'Check-in minutes after end', 'number', $e['minutes_after'] ?? 60, 'min="0" max="240"').'</div>'.gc('certificates_enabled', 'Certificates after completion and eligible attendance', (bool)($e['certificates_enabled'] ?? 0)).gf('credit_units', 'Participation credits', 'number', $e['credit_units'] ?? 0, 'min="0" max="1000" step="0.25"'), $e ? 'Save activity' : 'Create activity');
}
$caseWhere = $head ? '1=1' : ($staff === 'counselor' ? 't.assigned_to='.$uid : ($staff ? '0=1' : 't.user_id='.$uid));
$cases = gall("SELECT t.*,c.name AS category,u.full_name AS requester,a.full_name AS counselor FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN users a ON a.id=t.assigned_to LEFT JOIN categories c ON c.id=t.category_id WHERE t.office_code='guide' AND $caseWhere ORDER BY t.updated_at DESC,t.id DESC");
$events = $staff ? gall('SELECT * FROM gd_events ORDER BY starts_at DESC') : gall("SELECT e.* FROM gd_events e JOIN gd_invites i ON i.event_id=e.id WHERE i.user_id=? AND e.status<>'draft' ORDER BY e.starts_at DESC", [$uid]);
$appointments = gall('SELECT a.*,u.full_name AS requester,c.full_name AS counselor FROM gd_appointments a JOIN users u ON u.id=a.user_id JOIN users c ON c.id=a.counselor_id'.($head ? '' : ($staff === 'counselor' ? ' WHERE a.counselor_id='.$uid : ' WHERE a.user_id='.$uid)).' ORDER BY a.starts_at DESC');
$students = $staff ? gall("SELECT id,full_name,school_id FROM users WHERE role='student' AND status='active' AND verification='verified' ORDER BY full_name") : [];
$certs = gall('SELECT * FROM gd_certificates WHERE user_id=? ORDER BY issued_at DESC', [$uid]);
page_start($titles[$view], 'LCCT · Shared campus application · Guidance Office');
?>
<link rel="stylesheet" href="<?= e(url('guidance/assets/guidance.css')) ?>">
<div id="guidance" data-state="<?= e(url('guidance/state.php')) ?>" data-sig="<?= e($initialSignature) ?>" data-action="<?= e(url('guidance/action.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
<?php if (getenv('LCCT_SYNTHETIC_TEST') === '1'): ?><div class="alert warn">Synthetic local review database. No real school records or email delivery.</div><?php endif; ?>
<div class="gd-tabs"><?php foreach (['overview' => 'Overview','requests' => 'Requests','appointments' => 'Appointments','events' => 'Activities','history' => 'My participation','settings' => 'Settings'] as $v => $label) {
    echo gl($v, $label, 0, 'btn'.($view === $v ? ' btn-primary' : ''));
} ?></div>
<?php
if ($view === 'overview') {
    echo '<div class="gd-hero"><div><span class="gd-eyebrow">YOUR SPACE FOR SUPPORT AND GROWTH</span><h1>Welcome to Guidance.</h1><p>Ask for support, make time for a conversation, and take your next step—with the same campus account.</p><div class="gd-buttons">'.(is_filer($u) ? '<a class="btn" href="'.e(url('student/new_ticket.php?office=guide')).'">New Guidance request</a>' : gl('requests', 'Open my queue')).gl('events', 'Explore activities').'</div></div><img src="'.e(url('assets/img/lcct-seal.png')).'" alt="Official LCCT seal"></div>';
    echo '<div class="gd-stats">';
    foreach ([[count(array_filter($cases, fn ($t) => !in_array($t['status'], ['Closed','Cancelled','Resolved'], true))),'Active authorized cases'],[count(array_filter($appointments, fn ($a) => in_array($a['status'], ['requested','confirmed'], true))),'Upcoming / requested appointments'],[count($events),'Visible activities'],[count($certs),'My certificates']] as [$n,$label]) {
        echo '<div class="card"><span>'.e($label).'</span><b>'.$n.'</b></div>';
    }echo '</div>';
    echo '<div class="gd-grid">'.gcard('One connected service', '<p>Requests are stored in the existing <b>tickets</b> table. Replies, files, status tracking and the notification bell are the shared system’s own features.</p>'.gl('requests', 'View requests')).gcard('Private by design', '<p>Guidance cases are visible to their student owner, assigned counselor and Guidance head—not unrelated office staff or every administrator.</p><div class="gd-note">This is not an emergency service. For immediate danger, contact emergency services and a trusted adult or campus authority.</div>').'</div>';
} elseif ($view === 'requests') {
    echo '<div class="gd-buttons">'.(is_filer($u) ? '<a class="btn btn-primary" href="'.e(url('student/new_ticket.php?office=guide')).'">New Guidance request</a>' : (in_array($staff, ['head','counselor'], true) ? gl('walkin', 'Record a walk-in') : '')).'</div>';
    $rows = [];
    foreach ($cases as $t) {
        $details = '<a href="'.e(url('ticket.php?id='.$t['id'])).'">'.e($t['subject']).'</a><small>'.e($t['code']).'</small>';
        if (is_filer($u) && in_array($t['status'], ['Resolved','Closed'], true)) {
            $details .= gdetails('Rate this service', gform('feedback', gh('id', $t['id']).gs('rating', 'Rating', [1 => '1 / 5',2 => '2 / 5',3 => '3 / 5',4 => '4 / 5',5 => '5 / 5'], 5).ga('feedback', 'Feedback', '', 'maxlength="2000"'), 'Send feedback'));
        }$rows[] = [$details,e($t['requester']),e($t['category']),badge($t['status']),prio_badge($t['priority']),e($t['counselor'] ?: 'Awaiting assignment')];
    }
    echo gcard('Shared Guidance tickets', '<label class="field"><span>Search this list</span><input data-search type="search" placeholder="Reference, subject or status…"></label>'.gtable(['Request','Requester','Service','Status','Priority','Counselor'], $rows));
    if (!$cases) {
        echo '<p class="muted">If you are a counselor, the head must assign a confidential case before it appears here.</p>';
    }
} elseif ($view === 'walkin') {
    echo gcard('Record on behalf of a student', gform('walkin', gs('user_id', 'Student requester', array_column($students, 'full_name', 'id'), '', 'required').gs('category_id', 'Guidance service', array_column(gall("SELECT id,name FROM categories WHERE office_code='guide' AND is_hidden=0"), 'name', 'id'), '', 'required').gf('subject', 'Subject', 'text', '', 'required minlength="5" maxlength="150"').ga('details', 'Initial details', '', 'required minlength="10" maxlength="20000"').gs('priority', 'Priority', gopts(PRIORITIES), 'Normal').($head ? gs('assigned_to', 'Counselor', [0 => 'Unassigned'] + array_column(g_counselors(), 'full_name', 'id')) : '<p>This case will be assigned to you.</p>').'<div class="gd-note">The selected student owns this shared ticket. You are separately recorded as its creator.</div>', 'Create shared ticket'));
} elseif ($view === 'appointments') {
    echo '<div class="gd-grid"><div>';
    foreach ($appointments as $a) {
        $body = '<p><b>'.e($a['requester']).'</b> · '.e($a['counselor']).'</p><p>'.e(fmt_dt($a['starts_at'])).' — '.e(fmt_dt($a['ends_at'])).'</p><p class="gd-pre">'.e($a['concern']).'</p>'.gtag($a['status']);
        if (in_array($a['status'], ['requested','confirmed'], true)) {
            $body .= gform('appointment.update', gh('id', $a['id']).gs('status', 'Update', ($head || $staff === 'counselor') ? gopts(['confirmed','completed','cancelled']) : ['cancelled' => 'Cancel appointment']), 'Update appointment');
        }echo gcard('Appointment', $body);
    }if (!$appointments) {
        echo gempty('No appointments in your authorized schedule.');
    }echo '</div>';
    if (is_filer($u)) {
        echo gcard('Request a time', gform('appointment.create', gs('counselor_id', 'Counselor', array_column(g_counselors(), 'full_name', 'id'), '', 'required').gf('starts_at', 'Start · Philippine time', 'datetime-local', '', 'required').gf('ends_at', 'End · Philippine time', 'datetime-local', '', 'required').ga('concern', 'Brief reason', '', 'required minlength="5" maxlength="4000"').'<div class="gd-note">Maximum two hours. Requested and confirmed appointments reserve the time.</div>', 'Request appointment'));
    }echo '</div>';
} elseif ($view === 'events') {
    if ($staff) {
        echo gcard('Plan an activity', gdetails('Create a Guidance activity', geform()));
    }echo '<div class="gd-grid gd-three">';
    foreach ($events as $e) {
        echo gcard($e['title'], '<span class="gd-eyebrow">'.e(str_replace('_', ' ', $e['kind'])).'</span><p>'.e(fmt_dt($e['starts_at'])).'<br>'.e($e['venue']).'</p><p>'.e(mb_strimwidth($e['description'], 0, 150, '…')).'</p><div class="gd-buttons">'.gtag($e['status']).gl('event', 'Open activity', (int)$e['id']).'</div>');
    }echo '</div>';
    if (!$events) {
        echo gempty('Published invitations will appear here.');
    }
} elseif ($view === 'event') {
    try {
        $e = g_event($u, $id);
    } catch (Throwable $ex) {
        g_denied('Activity not found.', 404);
    } $inv = gall('SELECT i.*,u.full_name FROM gd_invites i JOIN users u ON u.id=i.user_id WHERE i.event_id=?'.($staff ? '' : ' AND i.user_id='.$uid).' ORDER BY u.full_name', [$id]);
    $mine = null;
    foreach ($inv as $i) {
        if ((int)$i['user_id'] === $uid) {
            $mine = $i;
        }
    }
    echo '<div class="gd-grid"><div>'.gcard($e['title'], gtag($e['status']).'<p class="gd-pre">'.e($e['description']).'</p><dl><dt>Venue</dt><dd>'.e($e['venue']).'</dd><dt>Starts · Philippine time</dt><dd>'.e(fmt_dt($e['starts_at'])).'</dd><dt>Ends</dt><dd>'.e(fmt_dt($e['ends_at'])).'</dd><dt>Capacity</dt><dd>'.(int)$e['capacity'].'</dd><dt>Recognition</dt><dd>'.((int)$e['certificates_enabled'] ? 'Certificate · '.e($e['credit_units']).' credits' : 'Participation history').'</dd></dl>');
    if ($mine) {
        $body = gtag($mine['state']).' '.gtag($mine['attendance']);
        if ($e['status'] === 'published' && !in_array($mine['attendance'], ['present','late'], true)) {
            $body .= gform('rsvp', gh('id', $id).gs('state', 'Your response', ['registered' => 'Accept invitation','declined' => 'Decline invitation'], $mine['state']), 'Save RSVP');
        }if ($mine['state'] === 'registered') {
            $qr = json_encode(['type' => 'guidance-pass','event' => $id,'pass' => $mine['pass_code']]);
            $body .= '<p>Show your personal pass to authorized Guidance staff.</p><div class="gd-qr" data-qr="'.e($qr).'"></div><code class="gd-code">'.e($mine['pass_code']).'</code>';
            if (!in_array($mine['attendance'], ['present','late'], true)) {
                $body .= gform('checkin', gh('id', $id).gf('code', 'Event code supplied by Guidance', 'text', '', 'required autocomplete="off"'), 'Check in').'<button class="btn" type="button" data-scan data-event="'.$id.'">Scan event QR</button>';
            }
        }echo gcard('Your invitation', $body);
    }
    if ($staff) {
        echo gcard('Activity settings', gone('SELECT id FROM gd_certificates WHERE event_id=?', [$id]) ? '<p>Locked after certificate issuance. Contact the Guidance head for a documented correction process.</p>' : gdetails('Edit activity', geform($e)));
    }echo '</div><div>';
    if ($staff) {
        echo gcard('Invite students', gform('invite', gh('id', $id).gs('user_ids[]', 'Select students', array_column($students, 'full_name', 'id'), '', 'multiple size="6" required').'<p class="muted">Hold Ctrl/Command to select several students. Invitations are idempotent.</p>', 'Send invitations'));
        $registered = [];
        foreach ($inv as $i) {
            if ($i['state'] === 'registered') {
                $registered[$i['user_id']] = $i['full_name'];
            }
        }
        echo gcard('Attendance desk', '<p>Share this activity code only with participants who are present.</p><code class="gd-code">'.e($e['checkin_code']).'</code><div class="gd-qr" data-qr="'.e(json_encode(['type' => 'guidance-event','event' => $id,'code' => $e['checkin_code']])).'"></div><button class="btn" type="button" data-scan data-event="'.$id.'" data-code="'.e($e['checkin_code']).'">Scan personal pass</button><p class="muted">Camera requires HTTPS or localhost and permission. Manual fallback is below.</p>'.gform('checkin', gh('id', $id).gh('code', $e['checkin_code']).gs('user_id', 'Registered participant', $registered, '', 'required'), 'Mark present').gdetails('Manual attendance adjustment', gform('attendance', gs('id', 'Participant', array_column($inv, 'full_name', 'id'), '', 'required').gs('attendance', 'Attendance', gopts(['pending','present','late','absent','excused']), 'present'), 'Record adjustment')).'<hr>'.gform('certificates', gh('id', $id), 'Issue eligible certificates'));
    }
    echo gcard('Participation', gtable($staff ? ['Participant','RSVP','Attendance'] : ['RSVP','Attendance'], array_map(fn ($i) => $staff ? [e($i['full_name']),gtag($i['state']),gtag($i['attendance'])] : [gtag($i['state']),gtag($i['attendance'])], $inv))).'</div></div>';
} elseif ($view === 'programs') {
    if ($staff) {
        echo gcard('Add a recurring program', gdetails('Create program', gform('program.save', gf('title', 'Program title', 'text', '', 'required minlength="3" maxlength="160"').ga('description', 'Description', '', 'maxlength="5000"').gc('active', 'Active program', true), 'Create program')));
    }
    foreach (gall('SELECT * FROM gd_programs'.($staff ? '' : ' WHERE active=1').' ORDER BY title') as $p) {
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
    echo gcard('Activities and appointments', gtable(['When · Philippine time','Activity','Status'], array_map(fn ($r) => [e(fmt_dt($r[0])),$r[1],$r[2]], $rows)));
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
    }echo gcard('Your milestones', '<p class="gd-credit">'.e(array_sum(array_column($certs, 'credits'))).' credits earned</p>'.gtable(['Activity','RSVP','Attendance','Certificate'], $rows));
} elseif ($view === 'knowledge') {
    if ($staff) {
        echo gcard('Publish support information', gdetails('Write an article', gform('article.save', gf('title', 'Title', 'text', '', 'required minlength="3" maxlength="160"').ga('body', 'Article', '', 'required minlength="10" maxlength="30000"').gc('published', 'Publish for students'), 'Save article')));
    }
    $articles = gall('SELECT * FROM gd_articles'.($staff ? '' : ' WHERE published=1').' ORDER BY updated_at DESC');
    foreach ($articles as $a) {
        echo gcard($a['title'], ((int)$a['published'] ? '' : gtag('draft')).'<p class="gd-pre">'.e($a['body']).'</p>'.($staff ? gdetails('Edit article', gform('article.save', gh('id', $a['id']).gf('title', 'Title', 'text', $a['title'], 'required').ga('body', 'Article', $a['body'], 'required').gc('published', 'Published', (bool)$a['published']))) : ''));
    }if (!$articles) {
        echo gempty('No published articles yet.');
    }
} elseif ($view === 'reports') {
    echo gcard('Privacy-preserving totals', '<p>Aggregate counts only. Cells below five are suppressed. No student identities or counseling text are included.</p><p><a class="btn" href="'.e(url('guidance/export.php?type=reports')).'">Download aggregate CSV</a></p>'.gtable(['Dimension','Group','Value'], array_map(fn ($r) => array_map('e', $r), g_reports())));
} elseif ($view === 'categories') {
    echo gcard('Add a shared Guidance category', gform('category.save', gf('name', 'Service name', 'text', '', 'required minlength="2" maxlength="60"').gc('hidden', 'Hide from new requests'), 'Add service'));
    foreach (gall("SELECT * FROM categories WHERE office_code='guide' ORDER BY sort_order,name") as $c) {
        echo gcard($c['name'], gdetails('Edit service', gform('category.save', gh('id', $c['id']).gf('name', 'Service name', 'text', $c['name'], 'required minlength="2" maxlength="60"').gc('hidden', 'Hidden from new requests', (bool)$c['is_hidden']))));
    }
} elseif ($view === 'team') {
    echo gcard('Add a shared Guidance staff account', gdetails('Create staff account', gform('staff.create', gf('full_name', 'Full name', 'text', '', 'required minlength="3" maxlength="120"').gf('username', 'Username', 'text', '', 'required minlength="3" maxlength="30"').gf('email', 'Email', 'email', '', 'required maxlength="120"').gf('password', 'Temporary password · 12+ characters', 'password', '', 'required minlength="12" maxlength="128" autocomplete="new-password"').gs('guidance_role', 'Guidance role', gopts(['counselor','administrator','head']), 'counselor').'<p>Uses the existing users table. Password change is required on first sign-in.</p>', 'Create account')));
    foreach (gall("SELECT u.id,u.full_name,u.username,u.status,s.guidance_role,s.active FROM users u LEFT JOIN gd_staff s ON s.user_id=u.id WHERE u.role='admin' AND u.office_code='guide' ORDER BY u.full_name") as $m) {
        echo gcard($m['full_name'], '<p>'.e($m['username']).' · shared account '.e($m['status']).'</p>'.gform('staff.save', gh('user_id', $m['id']).gs('guidance_role', 'Guidance role', gopts(['head','counselor','administrator']), $m['guidance_role'] ?? 'counselor').gc('active', 'Guidance access enabled', (bool)($m['active'] ?? 0))));
    }
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
    $pref = gone('SELECT * FROM gd_preferences WHERE user_id=?', [$uid]);
    echo '<div class="gd-grid">'.gcard('Your shared identity', '<h3>'.e($u['full_name']).'</h3><p>'.e($u['username']).' · '.e($staff ?: 'student').'</p><p><a class="btn" href="'.e(url('auth/change_password.php')).'">Change shared password</a></p>'.gform('preferences', gc('ticket_updates', 'Receive in-app Guidance ticket updates', !$pref || (bool)$pref['ticket_updates'])).'<hr>'.gform('sessions', '<p>Revoke every shared session, including this browser.</p>', 'Sign out all sessions')).gcard('Your data and privacy', '<p><a class="btn" href="'.e(url('guidance/export.php')).'">Download my Guidance records</a></p><div class="gd-note">Your export excludes other people’s records and internal notes. A privacy request starts a review, not automatic deletion.</div>'.gform('privacy.submit', ga('reason', 'Privacy review request', '', 'required minlength="10" maxlength="4000"'), 'Submit request')).'</div>';
    foreach (gall('SELECT * FROM gd_privacy WHERE user_id=? ORDER BY updated_at DESC', [$uid]) as $p) {
        echo gcard('Your privacy request', gtag($p['status']).'<p class="gd-pre">'.e($p['reason']).'</p><p class="gd-pre">'.e($p['response'] ?? 'Awaiting review.').'</p>');
    }
}
?>
<p class="gd-foot">Guidance uses the shared campus account and ticket records. Dates are Philippine local time. <span id="gd-live">Live updates · draft protection enabled</span></p>
</div>
<dialog id="gd-camera"><h2>Guidance QR check-in</h2><video autoplay muted playsinline></video><p id="gd-camera-note">Camera needs HTTPS or localhost. Manual entry remains available.</p><button class="btn" type="button" data-camera-close>Close camera</button></dialog>
<script src="<?= e(url('guidance/assets/vendor/qrcode.js')) ?>"></script><script src="<?= e(url('guidance/assets/vendor/jsQR.js')) ?>"></script><script src="<?= e(url('guidance/assets/guidance.js')) ?>" defer></script>
<?php page_end(); ?>
