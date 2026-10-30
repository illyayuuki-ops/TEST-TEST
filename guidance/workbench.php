<?php
// Read/query helpers for the Guidance restoration. No new shared tables or writes.
if (!function_exists('g_actor')) { http_response_code(404); exit; }
function gw_text(array $p, string $key, int $max = 120): string {
    $v = $p[$key] ?? '';
    gx(is_string($v) && mb_check_encoding($v, 'UTF-8') && mb_strlen($v) <= $max, 'Invalid filter: '.$key, 400);
    return trim($v);
}
function gw_choice(array $p, string $key, array $allowed, string $default = ''): string {
    $v = gw_text($p, $key, 60);
    if ($v === '') return $default;
    gx(in_array($v, $allowed, true), 'Choose a valid '.$key.' filter.', 400);
    return $v;
}
function gw_id(array $p, string $key): int {
    $v = gw_text($p, $key, 12);
    gx($v === '' || (ctype_digit($v) && (int)$v <= 2147483647), 'Invalid '.$key.' filter.', 400);
    return (int)$v;
}
function gw_dates(array $p): array {
    $out = [];
    foreach (['from','to'] as $key) {
        $s = gw_text($p, $key, 10);
        $date = $s === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        gx($s === '' || ($date && $date->format('Y-m-d') === $s && $s >= '1970-01-01' && $s <= '9998-12-31'), 'Choose a valid '.$key.' date.', 400);
        $out[$key] = $s;
    }
    gx(!$out['from'] || !$out['to'] || $out['from'] <= $out['to'], 'The start date must be on or before the end date.', 400);
    return $out;
}
function gw_filters(array $p): array {
    return gw_dates($p) + [
        'q'=>gw_text($p,'q'), 'status'=>gw_choice($p,'status',STATUSES),
        'priority'=>gw_choice($p,'priority',gr_priorities()), 'category'=>gw_id($p,'category'),
        'assigned'=>gw_choice($p,'assigned',['all','me','unassigned','person'],'all'), 'counselor'=>gw_id($p,'counselor'),
        'due'=>gw_choice($p,'due',['all','overdue','today'],'all'),
        'sort'=>gw_choice($p,'sort',['recent','oldest','priority','due'],'recent'),
        'layout'=>gw_choice($p,'layout',['list','board'],'list'),
        'page'=>max(1,gw_id($p,'page'))
    ];
}
function gw_cases(array $u): array {
    $role = g_staff($u);
    if ($role === 'head') { $where = '1=1'; $args = []; }
    elseif ($role === 'counselor') { $where = 't.assigned_to=?'; $args = [$u['id']]; }
    elseif (is_filer($u)) { $where = 't.user_id=?'; $args = [$u['id']]; }
    else return [];
    return array_map('gr_enrich', gall("SELECT t.*,c.name AS category,u.full_name AS requester,u.school_id AS requester_school_id,a.full_name AS counselor FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN users a ON a.id=t.assigned_to LEFT JOIN categories c ON c.id=t.category_id WHERE t.office_code='guide' AND $where ORDER BY t.updated_at DESC,t.id DESC", $args));
}
function gw_due(array $t): int { return strtotime($t['created_at']) + ($t['sla_hours'] ?? SLA_HOURS[$t['priority']] ?? SLA_HOURS['Normal']) * 3600; }
function gw_open(array $t): bool { return in_array($t['status'], ['Open','Reopened','In Progress','Waiting for User','Escalated'], true); }
function gw_filter_cases(array $cases, array $f, array $u): array {
    $rows = array_values(array_filter($cases, function($t) use($f,$u) {
        if ($f['q'] !== '' && mb_stripos(implode(' ', [$t['code'],$t['subject'],$t['requester'],$t['category'] ?? '',$t['counselor'] ?? '',$t['requester_school_id'] ?? '']), $f['q']) === false) return false;
        if ($f['status'] && $t['status'] !== $f['status']) return false;
        if ($f['priority'] && $t['priority'] !== $f['priority']) return false;
        if ($f['category'] && (int)$t['category_id'] !== $f['category']) return false;
        if ($f['assigned'] === 'me' && (int)$t['assigned_to'] !== (int)$u['id']) return false;
        if ($f['assigned'] === 'unassigned' && $t['assigned_to']) return false;
        if ($f['assigned'] === 'person' && (int)$t['assigned_to'] !== $f['counselor']) return false;
        $day = substr($t['created_at'],0,10);
        if (($f['from'] && $day < $f['from']) || ($f['to'] && $day > $f['to'])) return false;
        if ($f['due'] !== 'all') {
            if (!in_array($t['status'], WORKING_STATUSES, true)) return false;
            if ($f['due'] === 'overdue' && gw_due($t) > time()) return false;
            if ($f['due'] === 'today' && date('Y-m-d',gw_due($t)) !== date('Y-m-d')) return false;
        }
        return true;
    }));
    $weights = ['Urgent'=>0,'High'=>1,'Normal'=>2,'Low'=>3];
    usort($rows, function($a,$b) use($f,$weights) {
        $v = match($f['sort']) {
            'oldest'=>strcmp($a['created_at'],$b['created_at']),
            'priority'=>($weights[$a['priority']] ?? 9) <=> ($weights[$b['priority']] ?? 9),
            'due'=>gw_due($a) <=> gw_due($b),
            default=>strcmp($b['updated_at'],$a['updated_at'])
        };
        return $v ?: (int)$b['id'] <=> (int)$a['id'];
    });
    return $rows;
}
function gw_link(string $view, array $filters = [], array $changes = []): string {
    $p = array_merge($filters,$changes); unset($p['view']);
    return url('guidance/index.php?'.http_build_query(['view'=>$view]+$p));
}
function gw_page(array $rows, int $page, int $size = 25): array {
    $pages = max(1,(int)ceil(count($rows)/$size)); $page = min($page,$pages);
    return ['rows'=>array_slice($rows,($page-1)*$size,$size),'page'=>$page,'pages'=>$pages,'total'=>count($rows)];
}
function gw_directory(array $u): array {
    $role = g_staff($u); gx(in_array($role,['head','counselor'],true));
    if ($role === 'head') return gall("SELECT id,full_name,school_id,email,status FROM users WHERE role='student' AND verification='verified' ORDER BY full_name,id");
    return gall("SELECT u.id,u.full_name,u.school_id,u.email,u.status FROM users u WHERE u.role='student' AND u.verification='verified' AND (EXISTS(SELECT 1 FROM tickets t WHERE t.user_id=u.id AND t.office_code='guide' AND t.assigned_to=?) OR EXISTS(SELECT 1 FROM gd_appointments a WHERE a.user_id=u.id AND a.counselor_id=?)) ORDER BY u.full_name,u.id",[$u['id'],$u['id']]);
}
function gw_report_filters(array $p): array {
    return gw_dates($p) + ['category'=>gw_id($p,'category'),'counselor'=>gw_id($p,'counselor')] + (gr_ready()?['program'=>gw_text($p,'program'),'year_level'=>gw_text($p,'year_level',40),'section'=>gw_text($p,'section',80)]:[]);
}
function gw_suppressed(int $n): string|int { return $n < 5 ? 'Suppressed (<5)' : $n; }
function gw_average(array $values, int $digits = 1): string|float {
    return count($values) < 5 ? 'Suppressed (<5)' : round(array_sum($values)/count($values),$digits);
}
/** Aggregate-only output: raw case/student IDs, text and rating comments never leave this function. */
function gw_analytics(array $u, array $f): array {
    gx(g_staff($u) !== null);
    $where = ["t.office_code='guide'"]; $args = [];
    if ($f['from']) { $where[] = 't.created_at>=?'; $args[]=$f['from'].' 00:00:00'; }
    if ($f['to']) { $where[] = 't.created_at<?'; $args[]=(new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d'); }
    if ($f['category']) { $where[]='t.category_id=?'; $args[]=$f['category']; }
    if ($f['counselor']) { $where[]='t.assigned_to=?'; $args[]=$f['counselor']; }
    if (gr_ready()) foreach (['program','year_level','section'] as $field) if (($f[$field]??'')!=='') { $where[]='EXISTS(SELECT 1 FROM gd_plus_students m WHERE m.user_id=t.user_id AND m.'.$field.'=?)'; $args[]=$f[$field]; }
    $cases = gall("SELECT t.id,t.category_id,t.created_at,t.resolved_at,t.status,t.priority,COALESCE(c.name,'Uncategorized') AS category,COALESCE(u.full_name,'Unassigned') AS counselor,f.rating,(SELECT MIN(r.created_at) FROM replies r JOIN users staff ON staff.id=r.user_id WHERE r.ticket_id=t.id AND r.is_internal=0 AND staff.role='admin') AS first_reply FROM tickets t LEFT JOIN categories c ON c.id=t.category_id LEFT JOIN users u ON u.id=t.assigned_to LEFT JOIN gd_feedback f ON f.ticket_id=t.id WHERE ".implode(' AND ',$where),$args);
    $groups = ['Status'=>[],'Priority'=>[],'Service demand'=>[],'Assigned workload'=>[],'Backlog age'=>[],'Intake month'=>[],'Resolution time'=>[],'Satisfaction rating'=>[],'Resolved within target'=>[]];
    $response=[]; $resolution=[]; $ratings=[]; $open=0; $overdue=0; $met=0; $finished=0;
    foreach ($cases as $t) {
        $t=gr_enrich($t);
        foreach (['Status'=>'status','Priority'=>'priority','Service demand'=>'category'] as $name=>$key) $groups[$name][$t[$key]]=($groups[$name][$t[$key]] ?? 0)+1;
        $month=substr($t['created_at'],0,7); $groups['Intake month'][$month]=($groups['Intake month'][$month] ?? 0)+1;
        if (gw_open($t)) {
            $open++; if (in_array($t['status'], WORKING_STATUSES, true) && gw_due($t)<=time()) $overdue++;
            $key=$t['counselor']; $groups['Assigned workload'][$key]=($groups['Assigned workload'][$key] ?? 0)+1;
            $days=max(0,(time()-strtotime($t['created_at']))/86400); $bucket=$days<2?'Under 2 days':($days<7?'2–6 days':($days<30?'7–29 days':'30+ days'));
            $groups['Backlog age'][$bucket]=($groups['Backlog age'][$bucket] ?? 0)+1;
        }
        if ($t['first_reply']) $response[]=max(0,(strtotime($t['first_reply'])-strtotime($t['created_at']))/3600);
        if ($t['resolved_at']) {
            $hours=max(0,(strtotime($t['resolved_at'])-strtotime($t['created_at']))/3600); $resolution[]=$hours; $finished++;
            $within=strtotime($t['resolved_at'])<=gw_due($t); if($within)$met++;
            $bucket=$hours<24?'Under 24 hours':($hours<72?'24–71 hours':($hours<168?'72–167 hours':'168+ hours'));
            $groups['Resolution time'][$bucket]=($groups['Resolution time'][$bucket] ?? 0)+1;
            $key=$within?'Within target':'Outside target'; $groups['Resolved within target'][$key]=($groups['Resolved within target'][$key] ?? 0)+1;
        }
        if ($t['rating'] !== null) { $ratings[]=(int)$t['rating'];$key=$t['rating'].' / 5';$groups['Satisfaction rating'][$key]=($groups['Satisfaction rating'][$key] ?? 0)+1; }
    }
    ksort($groups['Intake month']);
    // Every exposed count/mean uses the same suppression rule, including chart data and CSV.
    $out = ['Overview'=>[
        ['Requests in intake cohort',gw_suppressed(count($cases))],['Open requests (including waiting)',gw_suppressed($open)],['Overdue working requests',gw_suppressed($overdue)],
        ['Mean first response hours',gw_average($response)],['Mean resolution hours',gw_average($resolution)],['Mean satisfaction / 5',gw_average($ratings)]
    ]];
    // A rate is withheld if either underlying cell is small; otherwise it could disclose a suppressed complement.
    $out['Overview'][]=['Resolved within target %',($met<5||($finished-$met)<5)?'Suppressed (<5)':round(100*$met/$finished,1)];
    foreach ($groups as $name=>$buckets) { $out[$name]=[];foreach($buckets as $label=>$n)$out[$name][]=[$label,gw_suppressed($n)]; }
    $ew=[];$ea=[];
    if($f['from']){$ew[]='e.starts_at>=?';$ea[]=$f['from'].' 00:00:00';}
    if($f['to']){$ew[]='e.starts_at<?';$ea[]=(new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d');}
    $filter=$ew?' WHERE '.implode(' AND ',$ew):'';
    $out['Activity attendance (date filter only)']=array_map(fn($r)=>[$r['attendance'],gw_suppressed((int)$r['n'])],gall('SELECT i.attendance,COUNT(*) n FROM gd_invites i JOIN gd_events e ON e.id=i.event_id'.$filter.' GROUP BY i.attendance',$ea));
    $out['Activity RSVP (date filter only)']=array_map(fn($r)=>[$r['state'],gw_suppressed((int)$r['n'])],gall('SELECT i.state,COUNT(*) n FROM gd_invites i JOIN gd_events e ON e.id=i.event_id'.$filter.' GROUP BY i.state',$ea));
    return $out;
}
