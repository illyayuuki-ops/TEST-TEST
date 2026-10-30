<?php
require_once dirname(__DIR__).'/includes/guidance-password.php';

function gx(bool $ok, string $msg = 'Not allowed.', int $status = 403): void
{
    if (!$ok) {
        throw new RuntimeException($msg, $status);
    }
}
function gt(array $p, string $k, int $min = 0, int $max = 2000, string $default = ''): string
{
    $s = $p[$k] ?? $default;
    gx(is_string($s), 'Invalid '.$k, 400);
    $s = trim($s);
    gx(mb_strlen($s) >= $min && mb_strlen($s) <= $max, "$k must be {$min}–{$max} characters.", 400);
    return $s;
}
function gi(array $p, string $k, int $min = 0, int $max = 2147483647, int $default = 0): int
{
    $v = $p[$k] ?? $default;
    gx(filter_var($v, FILTER_VALIDATE_INT) !== false && (int)$v >= $min && (int)$v <= $max, 'Invalid '.$k, 400);
    return (int)$v;
}
function ge(array $p, string $k, array $values, string $default = ''): string
{
    $v = gt($p, $k, 0, 80, $default);
    gx(in_array($v, $values, true), 'Invalid '.$k, 400);
    return $v;
}
function gb(array $p, string $k): int
{
    return isset($p[$k]) && in_array($p[$k], ['1','on',1,true], true) ? 1 : 0;
}
function gdtime(array $p, string $k): string
{
    $s = gt($p, $k, 16, 32);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $s, new DateTimeZone('Asia/Manila'));
    gx($d !== false && $d->format('Y-m-d\TH:i') === $s, 'Invalid '.$k.' (Philippine local time).', 400);
    return $d->format('Y-m-d H:i:s');
}
function g_counselors(): array
{
    return gall("SELECT u.id,u.full_name FROM users u JOIN gd_staff s ON s.user_id=u.id WHERE u.role='admin' AND u.office_code='guide' AND u.status='active' AND s.active=1 AND s.guidance_role IN ('head','counselor') ORDER BY u.full_name");
}
function g_event(array $u, int $id): array
{
    $e = gone('SELECT * FROM gd_events WHERE id=?', [$id]);
    gx($e !== null, 'Activity not found.', 404);
    if (!g_staff($u)) {
        gx($e['status'] !== 'draft' && gone('SELECT id FROM gd_invites WHERE event_id=? AND user_id=?', [$id,$u['id']]) !== null, 'Activity not found.', 404);
    }return $e;
}
function g_transaction(callable $f): mixed
{
    global $pdo;
    gx((int)gq('SELECT GET_LOCK(?,10)', ['guidance-write:'.DB_NAME])->fetchColumn() === 1, 'Guidance is busy; retry.', 503);
    try {
        $pdo->beginTransaction();
        $r = $f();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }throw $e;
    } finally {
        gq('SELECT RELEASE_LOCK(?)', ['guidance-write:'.DB_NAME]);
    }
}
function g_save(string $table, int $id, array $values): int
{
    global $pdo;
    $allowed = ['gd_programs','gd_events','gd_articles'];
    gx(in_array($table, $allowed, true));
    if ($id) {
        gx(gone("SELECT id FROM $table WHERE id=?", [$id]) !== null, 'Record not found.', 404);
        gq("UPDATE $table SET ".implode(',', array_map(fn ($k) => "$k=?", array_keys($values))).' WHERE id=?', array_merge(array_values($values), [$id]));
        return $id;
    }gq("INSERT INTO $table (".implode(',', array_keys($values)).') VALUES('.implode(',', array_fill(0, count($values), '?')).')', array_values($values));
    return (int)$pdo->lastInsertId();
}
function g_action(array $u, string $action, array $p): array
{
    global $pdo;
    if (str_starts_with($action, 'x.')) return gr_action($u,$action,$p);
    $uid = (int)$u['id'];
    $id = gi($p, 'id');
    $staff = g_staff($u);
    $head = g_head($u);
    $next = 'guidance/index.php';
    $msg = 'Saved successfully.';
    switch ($action) {
        case 'walkin':
            gx(in_array($staff, ['head','counselor'], true));
            $owner = gi($p, 'user_id', 1);
            gx(gone("SELECT id FROM users WHERE id=? AND role='student' AND status='active' AND verification='verified'", [$owner]) !== null, 'Choose a verified active student.', 400);
            $cat = gi($p, 'category_id', 1);
            gx(gone("SELECT id FROM categories WHERE id=? AND office_code='guide' AND is_hidden=0", [$cat]) !== null, 'Invalid Guidance category.', 400);
            $to = gi($p, 'assigned_to', 0);
            if ($to) {
                gx(in_array($to, array_map('intval', array_column(g_counselors(), 'id')), true), 'Choose a counselor.', 400);
            }if (!$head) {
                $to = $uid;
            }
            gq("INSERT INTO tickets(office_code,user_id,category_id,subject,details,priority,assigned_to) VALUES('guide',?,?,?,?,?,?)", [$owner,$cat,gt($p, 'subject', 5, 150),gt($p, 'details', 10, 20000),ge($p, 'priority', PRIORITIES, 'Normal'),$to ?: null]);
            $id = (int)$pdo->lastInsertId();
            gq('UPDATE tickets SET code=? WHERE id=?', ['GUIDE-'.date('y').'-'.str_pad((string)$id, 4, '0', STR_PAD_LEFT),$id]);
            gq('INSERT INTO gd_case_meta(ticket_id,created_by) VALUES(?,?)', [$id,$uid]);
            g_notice($id);
            $next = 'ticket.php?id='.$id;
            break;
        case 'feedback':
            $t = gone("SELECT * FROM tickets WHERE id=? AND office_code='guide'", [$id]);
            gx($t && is_filer($u) && (int)$t['user_id'] === $uid && in_array($t['status'], ['Resolved','Closed'], true), 'Only the requester may rate a resolved Guidance request.');
            gq('INSERT INTO gd_feedback(ticket_id,rating,feedback,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE rating=VALUES(rating),feedback=VALUES(feedback),updated_at=NOW()', [$id,gi($p, 'rating', 1, 5),gt($p, 'feedback', 0, 2000)]);
            $next .= '?view=requests';
            break;
        case 'appointment.create':
            gx(is_filer($u), 'Students book appointments; staff confirm them.');
            $to = gi($p, 'counselor_id', 1);
            gx(in_array($to, array_map('intval', array_column(g_counselors(), 'id')), true), 'Choose an active counselor.', 400);
            $start = gdtime($p, 'starts_at');
            $end = gdtime($p, 'ends_at');
            gx($start > date('Y-m-d H:i:s') && $end > $start && strtotime($end) - strtotime($start) <= 7200, 'Choose a future appointment lasting at most two hours.', 400);
            gx(!gone("SELECT id FROM gd_appointments WHERE status IN ('requested','confirmed') AND (counselor_id=? OR user_id=?) AND starts_at<? AND ends_at>?", [$to,$uid,$end,$start]), 'This time overlaps an appointment.', 409);
            gq('INSERT INTO gd_appointments(user_id,counselor_id,starts_at,ends_at,concern,updated_at) VALUES(?,?,?,?,?,NOW())', [$uid,$to,$start,$end,gt($p, 'concern', 5, 4000)]);
            $id = (int)$pdo->lastInsertId();
            if (gr_ready()) gr_notice($to,'Guidance appointment requested','appointments'); else notif_add($pdo, $to, null, 'Guidance appointment requested', 'Review your appointment schedule.');
            $next .= '?view=appointments';
            break;
        case 'appointment.update':
            $a = gone('SELECT * FROM gd_appointments WHERE id=?', [$id]);
            gx($a && ($head || (int)$a['user_id'] === $uid || ($staff === 'counselor' && (int)$a['counselor_id'] === $uid)), 'Appointment not found.', 404);
            $st = ge($p, 'status', ['confirmed','completed','cancelled']);
            if (!$head && (int)$a['counselor_id'] !== $uid) {
                gx($st === 'cancelled', 'Only the counselor can confirm or complete.');
            }gx(in_array($a['status'], ['requested','confirmed'], true), 'Appointment is finished.', 409);
            if ($st === 'completed') {
                gx($a['starts_at'] <= date('Y-m-d H:i:s'), 'Cannot complete a future appointment.', 409);
            }gq('UPDATE gd_appointments SET status=?,updated_at=NOW() WHERE id=?', [$st,$id]);
            if (gr_ready()) gr_notice((int)$a['user_id'],'Guidance appointment updated','appointments'); else notif_add($pdo, (int)$a['user_id'], null, 'Appointment updated', 'Open Guidance appointments for the current status.');
            $next .= '?view=appointments';
            break;
        case 'program.save':
            gx($staff !== null);
            $id = g_save('gd_programs', $id, ['title' => gt($p, 'title', 3, 160),'description' => gt($p, 'description', 0, 5000),'active' => gb($p, 'active')]);
            $next .= '?view=programs';
            break;
        case 'event.save':
            gx($staff !== null);
            $old = $id ? g_event($u, $id) : null;
            if ($old) {
                gx(!gone('SELECT id FROM gd_certificates WHERE event_id=?', [$id]), 'Activity is locked after certificate issuance.', 409);
            }
            $start = gdtime($p, 'starts_at');
            $end = gdtime($p, 'ends_at');
            gx($end > $start, 'Activity end must follow start.', 400);
            $st = ge($p, 'status', ['draft','published','completed','cancelled'], 'draft');
            if ($st === 'completed') {
                gx($end <= date('Y-m-d H:i:s'), 'Cannot complete an activity before its end.', 409);
            }
            $capacity = gi($p, 'capacity', 1, 100000);
            if ($id) {
                gx($capacity >= (int)gq("SELECT COUNT(*) FROM gd_invites WHERE event_id=? AND state='registered'", [$id])->fetchColumn(), 'Capacity is below registrations.', 409);
                gx($st !== 'draft' || !(int)gq('SELECT COUNT(*) FROM gd_invites WHERE event_id=?', [$id])->fetchColumn(), 'An invited activity cannot return to draft.', 409);
            }
            $program = gi($p, 'program_id');
            gx(!$program || gone('SELECT id FROM gd_programs WHERE id=?', [$program]) !== null, 'Program not found.', 400);
            $credit = gt($p, 'credit_units', 1, 10, '0');
            gx(is_numeric($credit) && (float)$credit >= 0 && (float)$credit <= 1000, 'Invalid credit units.', 400);
            $values = ['title' => gt($p, 'title', 3, 160),'description' => gt($p, 'description', 0, 10000),'kind' => ge($p, 'kind', ['seminar','workshop','assembly','orientation','testing','career_fair','recollection','wellbeing','other'], 'workshop'),'status' => $st,'program_id' => $program ?: null,'session_no' => gi($p, 'session_no', 1, 10000, 1),'starts_at' => $start,'ends_at' => $end,'venue' => gt($p, 'venue', 1, 160),'capacity' => $capacity,'certificates_enabled' => gb($p, 'certificates_enabled'),'credit_units' => (float)$credit,'minutes_before' => gi($p, 'minutes_before', 0, 240, 30),'minutes_after' => gi($p, 'minutes_after', 0, 240, 60),'updated_at' => date('Y-m-d H:i:s')];
            if (!$old) {
                $values += ['checkin_code' => strtoupper(bin2hex(random_bytes(8))),'created_by' => $uid];
            }$id = g_save('gd_events', $id, $values);
            $next .= '?view=event&id='.$id;
            break;
        case 'invite':
            gx($staff !== null);
            $e = g_event($u, $id);
            gx($e['status'] === 'published' && $e['ends_at'] > date('Y-m-d H:i:s'), 'Publish a current/upcoming activity before inviting.', 409);
            $ids = $p['user_ids'] ?? [];
            gx(is_array($ids) && count($ids) > 0 && count($ids) <= 500, 'Choose 1–500 students.', 400);
            $n = 0;
            foreach (array_unique($ids) as $v) {
                $student = gi(['v' => $v], 'v', 1);
                gx(gone("SELECT id FROM users WHERE id=? AND role='student' AND status='active' AND verification='verified'", [$student]) !== null, 'Unknown or unverified student.', 400);
                if (gone('SELECT id FROM gd_invites WHERE event_id=? AND user_id=?', [$id,$student])) {
                    continue;
                }gq('INSERT INTO gd_invites(event_id,user_id,pass_code) VALUES(?,?,?)', [$id,$student,bin2hex(random_bytes(24))]);
                if (gr_ready()) gr_notice($student,'Guidance activity invitation','event',$id); else notif_add($pdo, $student, null, 'Guidance invitation: '.$e['title'], 'Open Guidance activities to accept or decline.');
                $n++;
            }$msg = "$n invitation(s) sent.";
            $next .= '?view=event&id='.$id;
            break;
        case 'rsvp':
            gx(is_filer($u));
            $e = g_event($u, $id);
            $i = gone('SELECT * FROM gd_invites WHERE event_id=? AND user_id=?', [$id,$uid]);
            gx($i !== null, 'Invitation not found.', 404);
            gx($e['status'] === 'published' && $e['ends_at'] > date('Y-m-d H:i:s'), 'Registration is closed.', 409);
            gx(!in_array($i['attendance'], ['present','late'], true), 'Attendance is already recorded.', 409);
            $state = ge($p, 'state', ['registered','declined']);
            if ($state === 'registered' && $i['state'] !== 'registered') {
                gx((int)gq("SELECT COUNT(*) FROM gd_invites WHERE event_id=? AND state='registered'", [$id])->fetchColumn() < (int)$e['capacity'], 'Activity is full.', 409);
            }gq('UPDATE gd_invites SET state=? WHERE id=?', [$state,$i['id']]);
            $next .= '?view=event&id='.$id;
            break;
        case 'checkin':
            $e = g_event($u, $id);
            $i = $staff ? (isset($p['pass']) ? gone('SELECT * FROM gd_invites WHERE event_id=? AND pass_code=?', [$id,gt($p, 'pass', 48, 48)]) : gone('SELECT * FROM gd_invites WHERE event_id=? AND user_id=?', [$id,gi($p, 'user_id', 1)])) : gone('SELECT * FROM gd_invites WHERE event_id=? AND user_id=?', [$id,$uid]);
            gx($i && $e['status'] === 'published' && $i['state'] === 'registered', 'Accepted registration and a published activity are required.', 409);
            gx(hash_equals($e['checkin_code'], strtoupper(gt($p, 'code', 1, 32))), 'Incorrect event code.', 400);
            gx(time() >= strtotime($e['starts_at']) - (int)$e['minutes_before'] * 60 && time() <= strtotime($e['ends_at']) + (int)$e['minutes_after'] * 60, 'Outside the check-in window.', 409);
            if (!in_array($i['attendance'], ['present','late'], true)) {
                gq("UPDATE gd_invites SET attendance='present',checked_at=NOW() WHERE id=?", [$i['id']]);
            }$next .= '?view=event&id='.$id;
            break;
        case 'attendance':
            gx($staff !== null);
            $i = gone('SELECT * FROM gd_invites WHERE id=?', [$id]);
            gx($i !== null, 'Invitation not found.', 404);
            $e = g_event($u, (int)$i['event_id']);
            gx(in_array($e['status'], ['published','completed'], true) && $e['starts_at'] <= date('Y-m-d H:i:s'), 'Activity has not started or is cancelled.', 409);
            gx(!gone('SELECT id FROM gd_certificates WHERE event_id=? AND user_id=?', [$e['id'],$i['user_id']]), 'Attendance is locked after certificate issuance.', 409);
            $st = ge($p, 'attendance', ['pending','present','late','absent','excused']);
            if (in_array($st, ['present','late'], true)) {
                gx($i['state'] === 'registered', 'Accept registration before recording attendance.', 409);
            }gq('UPDATE gd_invites SET attendance=?,checked_at=? WHERE id=?', [$st,in_array($st, ['present','late'], true) ? date('Y-m-d H:i:s') : null,$id]);
            $next .= '?view=event&id='.$e['id'];
            break;
        case 'certificates':
            gx($staff !== null);
            $e = g_event($u, $id);
            gx($e['status'] === 'completed' && (int)$e['certificates_enabled'], 'Complete an eligible activity before issuing certificates.', 409);
            $n = 0;
            foreach (gall("SELECT i.user_id,u.full_name FROM gd_invites i JOIN users u ON u.id=i.user_id WHERE i.event_id=? AND i.state='registered' AND i.attendance IN ('present','late')", [$id]) as $i) {
                if (gone('SELECT id FROM gd_certificates WHERE event_id=? AND user_id=?', [$id,$i['user_id']])) {
                    continue;
                }gq('INSERT INTO gd_certificates(event_id,user_id,verify_code,recipient_name,event_title,event_date,credits,issued_by,issued_at) VALUES(?,?,?,?,?,?,?,?,NOW())', [$id,$i['user_id'],bin2hex(random_bytes(24)),$i['full_name'],$e['title'],$e['starts_at'],$e['credit_units'],$uid]);
                $n++;
            }$msg = "$n certificate(s) issued.";
            $next .= '?view=event&id='.$id;
            break;
        case 'article.save':
            gx($staff !== null);
            $id = g_save('gd_articles', $id, ['title' => gt($p, 'title', 3, 160),'body' => gt($p, 'body', 10, 30000),'published' => gb($p, 'published'),'updated_by' => $uid,'updated_at' => date('Y-m-d H:i:s')]);
            $next .= '?view=knowledge';
            break;
        case 'category.save':
            gx($head);
            $name = gt($p, 'name', 2, 60);
            if ($id) {
                gx(gone("SELECT id FROM categories WHERE id=? AND office_code='guide'", [$id]) !== null, 'Guidance category not found.', 404);
                gq("UPDATE categories SET name=?,is_hidden=? WHERE id=? AND office_code='guide'", [$name,gb($p, 'hidden'),$id]);
            } else {
                gq("INSERT INTO categories(office_code,name,is_hidden) VALUES('guide',?,?)", [$name,gb($p, 'hidden')]);
                $id = (int)$pdo->lastInsertId();
            }$next .= '?view=categories';
            break;
        case 'staff.save':
            gx($head);
            $member = gi($p, 'user_id', 1);
            $a = gone("SELECT * FROM users WHERE id=? AND role='admin' AND office_code='guide'", [$member]);
            gx($a !== null, 'Link an existing shared Guidance-office admin account.', 400);
            $role = ge($p, 'guidance_role', ['head','counselor','administrator']);
            $active = gb($p, 'active');
            $old = gone('SELECT * FROM gd_staff WHERE user_id=?', [$member]);
            if ($old && $old['guidance_role'] === 'head' && (int)$old['active'] && (!$active || $role !== 'head')) {
                gx((int)gq("SELECT COUNT(*) FROM gd_staff s JOIN users u ON u.id=s.user_id WHERE s.guidance_role='head' AND s.active=1 AND u.status='active' AND u.role='admin' AND u.office_code='guide'")->fetchColumn() > 1, 'Keep at least one active Guidance head.', 409);
            }
            if (!$active || $role === 'administrator') {
                gx(!(int)gq("SELECT COUNT(*) FROM tickets WHERE office_code='guide' AND assigned_to=? AND status NOT IN ('Closed','Cancelled')", [$member])->fetchColumn() && !gone("SELECT id FROM gd_appointments WHERE counselor_id=? AND status IN ('requested','confirmed')", [$member]), 'Reassign active cases and finish/cancel appointments first.', 409);
            }
            gq('INSERT INTO gd_staff(user_id,guidance_role,active) VALUES(?,?,?) ON DUPLICATE KEY UPDATE guidance_role=VALUES(guidance_role),active=VALUES(active)', [$member,$role,$active]);
            gq('DELETE FROM gd_sessions WHERE user_id=?', [$member]);
            $id = $member;
            $next .= '?view=team';
            break;
        case 'staff.create':
            gx($head);
            $name = gt($p, 'full_name', 3, 120);
            $username = gt($p, 'username', 3, 30);
            gx((bool)preg_match('/^[a-zA-Z0-9_.]+$/', $username), 'Invalid username.', 400);
            $email = gt($p, 'email', 3, 120);
            gx((bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Invalid email.', 400);
            $pw = gp_value($p, 'password');
            $passwordError = gp_error($pw);
            gx($passwordError === null, $passwordError ?? '', 400);
            gx($pw === gp_value($p, 'password_confirm'), 'Temporary passwords do not match.', 400);
            gx(!gone('SELECT id FROM users WHERE username=? OR email=?', [$username,$email]), 'Username or email already exists.', 409);
            gq("INSERT INTO users(username,email,full_name,password,role,office_code,must_change_password,verification) VALUES(?,?,?,?,'admin','guide',1,'verified')", [$username,$email,$name,gp_hash($pw)]);
            $id = (int)$pdo->lastInsertId();
            gq('INSERT INTO gd_staff(user_id,guidance_role) VALUES(?,?)', [$id,ge($p, 'guidance_role', ['head','counselor','administrator'], 'counselor')]);
            $next .= '?view=team';
            break;
        case 'preferences':gq('INSERT INTO gd_preferences(user_id,ticket_updates) VALUES(?,?) ON DUPLICATE KEY UPDATE ticket_updates=VALUES(ticket_updates)', [$uid,gb($p, 'ticket_updates')]);
            $next .= '?view=settings';
            break;
        case 'sessions':gq('DELETE FROM gd_sessions WHERE user_id=?', [$uid]);
            $next = 'auth/login.php';
            break;
        case 'privacy.submit':gx(!gone("SELECT id FROM gd_privacy WHERE user_id=? AND status='submitted'", [$uid]), 'A request is already awaiting review.', 409);
            gq('INSERT INTO gd_privacy(user_id,reason,updated_at) VALUES(?,?,NOW())', [$uid,gt($p, 'reason', 10, 4000)]);
            $id = (int)$pdo->lastInsertId();
            $next .= '?view=settings';
            break;
        case 'privacy.review':gx($head);
            gx(gone('SELECT id FROM gd_privacy WHERE id=?', [$id]) !== null, 'Privacy request not found.', 404);
            gq("UPDATE gd_privacy SET status='reviewed',response=?,reviewed_by=?,updated_at=NOW() WHERE id=?", [gt($p, 'response', 10, 4000),$uid,$id]);
            $next .= '?view=privacy';
            break;
        default:gx(false, 'Unknown action.', 404);
    }
    if ($msg === 'Saved successfully.') {
        $messages = [
            'walkin'=>'Request created. You can now review it and add updates.',
            'feedback'=>'Thank you. Your service feedback has been recorded.',
            'appointment.create'=>'Appointment requested. Check this page for confirmation.',
            'appointment.update'=>'Appointment updated. Please review its current status.',
            'program.save'=>'Program saved.', 'event.save'=>'Activity saved.',
            'rsvp'=>'Your activity response has been saved.', 'checkin'=>'Attendance recorded.',
            'attendance'=>'Attendance updated.', 'article.save'=>'Resource saved.',
            'category.save'=>'Guidance service saved.', 'staff.create'=>'Staff account created. Share the temporary password privately; a change is required at first sign-in.',
            'staff.save'=>'Guidance access updated.', 'preferences'=>'Your notification preference has been saved.',
            'privacy.submit'=>'Your privacy review request has been sent to the Guidance head.',
            'privacy.review'=>'Privacy review response saved.'
        ];
        $msg = $messages[$action] ?? $msg;
    }
    gr_after($u,$action,$p,isset($i)&&is_array($i)&&isset($i['event_id'])?$i:null);
    g_audit($u, $action, (string)$id);
    return ['message' => $msg,'redirect' => url($next),'id' => $id];
}
function g_csv(array $rows): string
{
    $f = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        $row = array_map(function ($v) {
            $s = (string)$v;
            return preg_match('/^[\s\x{FEFF}]*[=+@-]|^[\t\r\n]/u', $s) ? "'".$s : $s;
        }, $row);
        fputcsv($f, $row, ',', '"', '');
    }rewind($f);
    return stream_get_contents($f);
}
function g_reports(): array
{
    $r = [];
    foreach (['status', 'priority'] as $col) {
        foreach (gall("SELECT $col AS label,COUNT(*) n FROM tickets WHERE office_code='guide' GROUP BY $col") as $row) {
            $r[] = [$col, $row['label'], (int)$row['n'] < 5 ? '<5' : $row['n']];
        }
    }
    foreach (gall('SELECT attendance AS label,COUNT(*) n FROM gd_invites GROUP BY attendance') as $row) {
        $r[] = ['attendance', $row['label'], (int)$row['n'] < 5 ? '<5' : $row['n']];
    }
    $n = (int)gq("SELECT COUNT(*) FROM tickets t WHERE office_code='guide' AND ".sla_overdue_sql('t'))->fetchColumn();
    $r[] = ['SLA', 'Overdue working cases', $n < 5 ? '<5' : $n];
    $resolution = gone("SELECT COUNT(*) n,AVG(TIMESTAMPDIFF(SECOND,created_at,resolved_at))/3600 hours FROM tickets WHERE office_code='guide' AND resolved_at IS NOT NULL");
    $r[] = ['Hours', 'Mean resolution (at least 5 completed cases)', (int)$resolution['n'] < 5 ? 'Suppressed' : round((float)$resolution['hours'], 1)];
    $response = gone("SELECT COUNT(*) n,AVG(TIMESTAMPDIFF(SECOND,x.created_at,x.first_reply))/3600 hours FROM (SELECT t.created_at,(SELECT MIN(r.created_at) FROM replies r JOIN users u ON u.id=r.user_id WHERE r.ticket_id=t.id AND r.is_internal=0 AND u.role='admin') first_reply FROM tickets t WHERE t.office_code='guide') x WHERE x.first_reply IS NOT NULL");
    $r[] = ['Hours', 'Mean first public staff reply (at least 5 cases)', (int)$response['n'] < 5 ? 'Suppressed' : round((float)$response['hours'],1)];
    return $r;
}
