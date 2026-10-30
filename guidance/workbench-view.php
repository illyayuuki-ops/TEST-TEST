<?php
if (!isset($u,$view) || !function_exists('gcard')) { http_response_code(404); exit; }
function gw_pager(array $p, string $view, array $f): string {
    $h='<nav class="gd-buttons gw-pagination" aria-label="Results pages"><span>'.e($p['total']).' results · Page '.e($p['page']).' of '.e($p['pages']).'</span>';
    if($p['page']>1)$h.='<a class="btn" href="'.e(gw_link($view,$f,['page'=>$p['page']-1])).'">Previous page</a>';
    if($p['page']<$p['pages'])$h.='<a class="btn" href="'.e(gw_link($view,$f,['page'=>$p['page']+1])).'">Next page</a>';
    return $h.'</nav>';
}
function gw_case_details(array $t, array $u): string {
    $h='<a href="'.e(url('ticket.php?id='.$t['id'])).'">'.e($t['subject']).'</a><small>'.e($t['code']).'</small>';
    if(is_filer($u)&&in_array($t['status'],['Resolved','Closed'],true))$h.=gdetails('Rate this service',gform('feedback',gh('id',$t['id']).gs('rating','Rating',[1=>'1 / 5',2=>'2 / 5',3=>'3 / 5',4=>'4 / 5',5=>'5 / 5'],5).ga('feedback','Feedback','','maxlength="2000"'),'Send feedback'));
    if (gr_ready()) $h.='<small>'.gl('review','Review / preferences',(int)$t['id'],'').'</small>';
    return $h;
}
$categories=[0=>'All services']+array_column(gall("SELECT id,name FROM categories WHERE office_code='guide' ORDER BY name"),'name','id');
$counselors=[0=>'All counselors']+array_column(gall("SELECT u.id,u.full_name FROM users u JOIN gd_staff s ON s.user_id=u.id WHERE u.role='admin' AND u.office_code='guide' AND s.guidance_role IN ('head','counselor') ORDER BY u.full_name"),'full_name','id');
if($view==='requests') {
    $f=$workbenchFilters;
    if($staff==='administrator') { echo gcard('Guidance requests',gempty('Clinical requests are not part of your activity-administrator access.')); return; }
    $rows=gw_filter_cases($cases,$f,$u); $p=gw_page($rows,$f['page']);
    echo '<div class="gd-buttons">'.(is_filer($u)?'<a class="btn btn-primary" href="'.e(url(gr_ready()?'guidance/index.php?view=intake':'student/new_ticket.php?office=guide')).'">New Guidance request</a>':gl('walkin','Record a walk-in')).'</div>';
    $advanced=$f['category']||$f['priority']||$f['assigned']!=='all'||$f['counselor']||$f['from']||$f['to']||$f['due']!=='all';
    $filters='<form method="get" data-gfilter class="gw-filters">'.gh('view','requests').'<div class="gd-fields">'.gf('q','Search requests','search',$f['q'],'maxlength="120" placeholder="Reference, subject, requester, service…"'.($f['layout']==='list'?' data-search':'')).gs('status','Status',[''=>'All statuses']+gopts(STATUSES),$f['status']).gs('sort','Sort',['recent'=>'Recently updated','oldest'=>'Oldest created','priority'=>'Highest priority first','due'=>'Earliest due target'],$f['sort']).gs('layout','View',['list'=>'List','board'=>'Status board'],$f['layout']).'</div><details class="gu-filter-details"'.($advanced?' open':'').'><summary>More filters'.($advanced?' · applied':'').'</summary><div class="gd-fields">'.gs('category','Service',$categories,$f['category']).gs('priority','Priority',[''=>'All priorities']+gopts(gr_priorities()),$f['priority']).gs('assigned','Assignment',['all'=>'All authorized requests','me'=>'Assigned to me','unassigned'=>'Unassigned','person'=>'Selected counselor'],$f['assigned']).gs('counselor','Counselor (use Selected counselor)',$counselors,$f['counselor']).gf('from','Created on or after','date',$f['from']).gf('to','Created on or before','date',$f['to']).gs('due','Due target',['all'=>'Any target','overdue'=>'Overdue working requests','today'=>'Working requests due today'],$f['due']).'</div></details><div class="gd-buttons"><button class="btn btn-primary" type="submit">Apply filters</button>'.gl('requests','Reset').'</div></form>';
    $body='<p>Only authorized requests are shown. Apply filters to search all results. Dates refer to when requests were created, inclusive, in Philippine time.</p>'.$filters;
    $body.=gdetails('How due targets and the board work','<p class="gd-note">Due targets use the current Guidance service policy when configured, otherwise shared defaults. High is Guidance-only; common screens retain Normal for compatibility. Waiting for User is not counted as overdue. A status board is a read-only queue, not a drag-and-drop status editor.</p>');
    if($staff)$body.='<p><a class="btn" href="'.e(url('guidance/workbench-export.php?'.http_build_query(['type'=>'cases']+$f))).'">Download authorized queue CSV</a> <small>Private case metadata only; no subjects, narratives, replies or internal notes. Not an aggregate report.</small></p>';
    if($f['layout']==='board') {
        $body.='<p>Board displays up to 25 requests per page across all columns. Use Status to focus a column.</p><div class="gw-board">';
        foreach(STATUSES as $status) {
            $cards=array_values(array_filter($p['rows'],fn($t)=>$t['status']===$status));
            $body.='<section class="gw-column"><h4>'.e($status).' <small>('.count($cards).' on this page)</small></h4>';
            foreach($cards as $t)$body.='<article class="gw-ticket">'.gw_case_details($t,$u).'<p>'.e($t['requester']).'</p><p>'.e($t['category']).' · '.e($t['counselor']?:'Awaiting assignment').'</p>'.prio_badge($t['priority']).'</article>';
            if(!$cards)$body.='<p class="muted">No requests on this page.</p>';
            $body.='</section>';
        }$body.='</div>';
    } else {
        $table=[];
        foreach($p['rows'] as $t)$table[]=[gw_case_details($t,$u),e($t['requester']),e($t['category']),badge($t['status']),prio_badge($t['priority']),e($t['counselor']?:'Awaiting assignment')];
        $body.=gtable(['Request','Requester','Service','Status','Priority','Counselor'],$table,'No authorized requests match these filters. Reset filters to see your available requests.');
    }
    echo gcard(is_filer($u)?'My Guidance requests':'Guidance requests',$body.gw_pager($p,'requests',$f));
} elseif($view==='students') {
    $f=$workbenchFilters;$directory=$workbenchDirectory;
    if($id) {
        $student=null;foreach($directory as $s)if((int)$s['id']===$id)$student=$s;
        if(!$student)g_denied('Student not found in your authorized directory.',404);
        g_audit($u,'directory_view',(string)$id);
        echo '<div class="gd-buttons">'.gl('students','Back to student directory').'</div>';
        echo gcard($student['full_name'],'<dl class="gw-profile"><dt>School ID</dt><dd>'.e($student['school_id']?:'Not recorded').'</dd><dt>Email</dt><dd>'.e($student['email']).'</dd><dt>Account status</dt><dd>'.e($student['status']).'</dd></dl>'.(gr_ready()?gu_academic($u,$id):'').'<p class="gd-note">Only independently authorized cases and appointments appear below.</p>');
        $rows=[];foreach($cases as $t)if((int)$t['user_id']===$id)$rows[]=[gw_case_details($t,$u),badge($t['status']),e($t['counselor']?:'Unassigned'),e(fmt_dt($t['created_at']))];
        $p=gw_page($rows,$f['page']);
        echo gcard('Authorized Guidance requests',gtable(['Request','Status','Counselor','Created'],$p['rows'],'No cases within your current assignment scope.').gw_pager($p,'students',$f+['id'=>$id]));
        $rows=[];foreach($appointments as $a)if((int)$a['user_id']===$id)$rows[]=[e(fmt_dt($a['starts_at'])),e(fmt_dt($a['ends_at'])),e($a['counselor']),gtag($a['status'])];
        $ap=gw_page($rows,$f['appointment_page']);
        $links='<nav class="gd-buttons" aria-label="Appointment pages"><span>'.$ap['total'].' appointments · Page '.$ap['page'].' of '.$ap['pages'].'</span>';
        foreach(['Previous page'=>-1,'Next page'=>1] as $label=>$delta)if($ap['page']+$delta>=1&&$ap['page']+$delta<=$ap['pages'])$links.='<a class="btn" href="'.e(gw_link('students',$f,['id'=>$id,'appointment_page'=>$ap['page']+$delta])).'">'.e($label).'</a>';
        echo gcard('Authorized appointments',gtable(['Start','End','Counselor','Status'],$ap['rows'],'No appointments within your current assignment scope.').$links.'</nav><p>'.gl('appointments','Open appointment workspace').'</p>');
    } else {
        if (gr_ready()) {
            foreach(['program','year_level','section'] as $key) $f[$key]=$grQuery[$key];
            $directory=array_values(array_filter($directory,function($s)use($f){$m=gone('SELECT program,year_level,section FROM gd_plus_students WHERE user_id=?',[$s['id']]);foreach(['program','year_level','section'] as $key)if($f[$key]!==''&&($m[$key]??'')!==$f[$key])return false;return true;}));
        }
        $directory=array_values(array_filter($directory,fn($s)=>$f['q']===''||mb_stripos(implode(' ',[$s['full_name'],$s['school_id'],$s['email']]),$f['q'])!==false));$p=gw_page($directory,$f['page']);$rows=[];
        $caseCounts=[];$apptCounts=[];
        foreach($cases as $t)$caseCounts[$t['user_id']]=($caseCounts[$t['user_id']]??0)+1;
        foreach($appointments as $a)$apptCounts[$a['user_id']]=($apptCounts[$a['user_id']]??0)+1;
        foreach($p['rows'] as $s)$rows[]=[gl('students',$s['full_name'],(int)$s['id'],'gw-student-link'),e($s['school_id']?:'Not recorded'),e($s['status']),e($caseCounts[$s['id']]??0),e($apptCounts[$s['id']]??0)];
        $body='<p>'.($head?'Verified student accounts in the shared application.':'Only students with a Guidance case assigned to you or an appointment with you. Other counselors’ cases and appointments remain hidden.').'</p><p class="gd-note">Program / year / section are Guidance-scoped and must be verified against a named school source. Case and appointment totals below reflect your authorization, not a student’s complete school record.</p><form method="get" data-gfilter class="gw-filters">'.gh('view','students').gf('q','Search name, school ID or email','search',$f['q'],'maxlength="120"').(gr_ready()?gf('program','Program','text',$f['program']).gf('year_level','Year level','text',$f['year_level']).gf('section','Section','text',$f['section']):'').'<div class="gd-buttons"><button class="btn btn-primary" type="submit">Search students</button>'.gl('students','Reset').'</div></form>';
        echo gcard('Student directory',$body.gtable(['Student','School ID','Account','Authorized cases','Authorized appointments'],$rows,'No students match your search and current access.').gw_pager($p,'students',$f));
    }
} elseif($view==='reports') {
    $f=$workbenchFilters;$report=gw_analytics($u,$f);
    $form='<form method="get" data-gfilter class="gw-filters">'.gh('view','reports').'<div class="gd-fields">'.gf('from','Intake from · inclusive','date',$f['from']).gf('to','Intake to · inclusive','date',$f['to']).gs('category','Case service',$categories,$f['category']).gs('counselor','Case counselor',$counselors,$f['counselor']).(gr_ready()?gf('program','Current academic program','text',$f['program']).gf('year_level','Current year level','text',$f['year_level']).gf('section','Current section','text',$f['section']):'').'</div><div class="gd-buttons"><button class="btn btn-primary" type="submit">Apply report filters</button>'.gl('reports','All time / reset').'<a class="btn" href="'.e(gw_link('reports',$f,['from'=>date('Y-m-d',strtotime('-29 days')),'to'=>date('Y-m-d')])).'">Last 30 days</a></div></form>';
    echo gcard('Privacy-preserving totals','<p>Aggregate counts only. Cells below five are suppressed. No student identities or counseling text are included in charts or this export. Filtered reports are not anonymous data for public release: repeated comparisons may allow inference.</p>'.$form.'<p class="gd-note">Case charts follow an intake cohort: requests created in the selected date range (Philippine time), grouped by their current status, service and counselor. Response and resolution times describe that same cohort, not work completed during the period. Activity charts use the activity start date only, independent of case service / counselor / academic filters. Academic filters use current verified metadata, not historical enrollment snapshots.</p><p><a class="btn" href="'.e(url('guidance/workbench-export.php?'.http_build_query(['type'=>'reports']+$f))).'">Download filtered aggregate CSV</a></p>');
    echo '<div class="gw-reports">';
    foreach($report as $name=>$rows) {
        $table=[];$max=1;foreach($rows as $r)if(is_numeric($r[1]))$max=max($max,(float)$r[1]);
        foreach($rows as [$label,$value]) {
            $bar=$name==='Overview'?'':'<div class="gw-bar-track" aria-hidden="true"><span class="gw-bar" style="width:'.(is_numeric($value)?round(100*(float)$value/$max,2):0).'%"></span></div>';
            $table[]=[e($label),'<strong>'.e($value).'</strong>'.$bar];
        }
        echo gcard($name,gtable(['Group','Value'],$table,'No data in this intake cohort.').($name==='Overview'?'<p class="muted">Means require at least five eligible records. Target percentage requires at least five records in both outcome groups.</p>':'<p class="muted">Bars are relative to the largest displayed count. Suppressed cells have no bar.</p>'));
    }echo '</div>';
}
