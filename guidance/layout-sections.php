<?php
// Presentation-only helpers. Parent authenticates/scopes routes before rendering.
if (!defined('GUIDANCE_LAYOUT_SECTIONS')) { http_response_code(404); exit; }
require_once __DIR__.'/restoration-context.php';
function gu_attachments(): string {
    return str_replace(['<label>','<input type="file"','<div class="hint">'],['<label for="gu-request-files">','<input id="gu-request-files" aria-describedby="gu-request-files-help" type="file"','<div class="hint" id="gu-request-files-help">'],attach_input_html());
}
function gu_intro(string $text): string { return '<p class="gu-intro">'.e($text).'</p>'; }
function gu_group(string $key,string $title,string $description,string $body): string {
    return '<section class="gu-group" id="gu-'.e($key).'" aria-labelledby="gu-'.e($key).'-title"><header class="gu-group-head"><h2 id="gu-'.e($key).'-title">'.e($title).'</h2>'.($description?'<p>'.e($description).'</p>':'').'</header>'.$body.'</section>';
}
function gu_jumps(array $items): string {
    $h='<div class="gu-jumps" role="navigation" aria-label="On this page"><span>On this page</span>';
    foreach($items as $id=>$label)$h.='<a href="#gu-'.e($id).'">'.e($label).'</a>';
    return $h.'</div>';
}
function gu_privacy_history(int $id): string {
    $rows=[];
    foreach(gall('SELECT stage,response,created_at FROM gd_plus_privacy_log WHERE privacy_id=? ORDER BY id',[$id]) as $l)$rows[]=[gtag($l['stage']),e($l['response']),e(fmt_dt($l['created_at']))];
    return $rows?gdetails('Review history',gtable(['Stage','Response','When'],$rows)):'';
}
function gu_team(array $u): void {
    $fields=gf('full_name','Full name','text','','required minlength="3" maxlength="120"').gf('username','Username','text','','required minlength="3" maxlength="30"').gf('email','Email','email','','required maxlength="120"').gs('guidance_role','Guidance role',gopts(['counselor','administrator','head']),'counselor').gp_field('password','Temporary password','new').gp_field('password_confirm','Confirm temporary password','confirm').'<p class="gd-note">Share the temporary password privately. A password change is required at first sign-in.</p>';
    echo '<div class="gd-buttons">'.gl('settings','Back to Settings').'</div>'.gu_intro('Manage each staff member in one place. Profile, access and password controls save separately.');
    echo gcard('Add Guidance staff',gdetails('Create staff account',gform('staff.create',$fields,'Create account')));
    echo '<div class="gu-record-list">';
    foreach(gall("SELECT u.id,u.full_name,u.username,u.email,u.status,s.guidance_role,s.active FROM users u LEFT JOIN gd_staff s ON s.user_id=u.id WHERE u.role='admin' AND u.office_code='guide' ORDER BY u.full_name") as $m){
        $body='<div class="gu-record-meta"><span>'.e($m['username']).'</span>'.gtag($m['status']).gtag($m['guidance_role']??'Not linked').'</div><div class="gu-pair">';
        if(gr_ready()&&$m['guidance_role']!==null)$body.=gdetails('Profile details',gform('x.staff.edit',gh('id',$m['id']).gf('full_name','Full name','text',$m['full_name'],'required maxlength="120"').gf('email','Email','email',$m['email'],'required maxlength="120"'),'Save profile'));
        $body.=gdetails('Access and role',gform('staff.save',gh('user_id',$m['id']).gs('guidance_role','Guidance role',gopts(['head','counselor','administrator']),$m['guidance_role']??'counselor').gc('active','Guidance access enabled',(bool)($m['active']??0)),'Save access')).'</div>';
        if(gr_ready()&&$m['guidance_role']!==null&&(int)$m['id']!==(int)$u['id'])$body.='<div class="gu-sensitive">'.gdetails('Assisted password reset',gform('x.staff.reset',gh('id',$m['id']).gp_field('current_password','Your current head password').gp_field('password','Temporary password','new').gp_field('password_confirm','Confirm temporary password','confirm').'<p class="gd-note">Verify the staff member’s identity first. This revokes their sessions and requires a password change at sign-in. Share the temporary password privately; no recovery email is sent.</p>','Reset staff password')).'</div>';
        echo '<div data-staff-record="'.(int)$m['id'].'">'.gcard($m['full_name'],$body).'</div>';
    }
    echo '</div>';
}
function gu_services(): void {
    $counselors=[0=>'No automatic assignment']+array_column(g_counselors(),'full_name','id');
    $parents=[0=>'No parent']+array_column(gall("SELECT id,name FROM categories WHERE office_code='guide' ORDER BY name"),'name','id');
    echo '<div class="gd-buttons">'.gl('settings','Back to Settings').'</div>'.gu_intro('Each service has one card. Manage its name, availability, description, routing and targets together.');
    echo gcard('Add a Guidance service',gdetails('Create service',gform('category.save',gf('name','Service name','text','','required minlength="2" maxlength="60"').gc('hidden','Hide from new requests'),'Add service')));
    foreach(gall("SELECT * FROM categories WHERE office_code='guide' ORDER BY sort_order,name") as $c){
        $body='<div class="gu-record-meta">'.gtag((int)$c['is_hidden']?'Hidden from new requests':'Available for requests').'</div>';
        $body.=gdetails('Name and availability',gform('category.save',gh('id',$c['id']).gf('name','Service name','text',$c['name'],'required minlength="2" maxlength="60"').gc('hidden','Hidden from new requests',(bool)$c['is_hidden']),'Save service'));
        if(gr_ready()){
            $s=gone('SELECT * FROM gd_plus_services WHERE category_id=?',[$c['id']])??[];
            if(!empty($s['description']))$body.='<p class="gd-pre">'.e($s['description']).'</p>';
            $fields=gh('id',$c['id']).ga('description','Service description',$s['description']??'','maxlength="5000"').gs('parent_id','Parent service',$parents,$s['parent_id']??0).gs('counselor_id','Default routing counselor',$counselors,$s['counselor_id']??0).'<fieldset class="gu-fieldset"><legend>Guidance response targets</legend><div class="gd-fields">';
            foreach(['low'=>120,'normal'=>72,'high'=>48,'urgent'=>24] as $k=>$default)$fields.=gf($k.'_hours',ucfirst($k).' · hours','number',$s[$k.'_hours']??$default,'required min="1" max="8760"');
            $fields.='</div></fieldset><p class="gd-note">Guidance-only targets use each case’s creation time. Changing them can affect existing cases. Shared screens keep the common policy; High is a Guidance-specific priority.</p>';
            $body.=gdetails('Description, routing and targets',gform('x.service',$fields,'Save Guidance policy'));
        }
        echo '<div data-service-record="'.(int)$c['id'].'">'.gcard($c['name'],$body).'</div>';
    }
}
function gu_settings(array $u): void {
    $uid=(int)$u['id'];$staff=g_staff($u);$pref=gone('SELECT * FROM gd_preferences WHERE user_id=?',[$uid]);
    if(g_head($u))echo gcard('Guidance administration','<p>Manage staff access and services through Settings.</p><div class="gd-buttons">'.gl('team','Guidance team').gl('categories','Services').'</div>');
    $account='<div class="gu-record-meta"><strong>'.e($u['full_name']).'</strong><span>'.e($u['username']).'</span>'.gtag($staff?:'student').'</div><div class="gd-buttons">';
    if(is_filer($u))$account.='<a class="btn" href="'.e(url('student/profile.php')).'">Edit profile</a>';
    $account.='<a class="btn" href="'.e(url('auth/change_password.php?from=guidance')).'">Change password</a></div>';
    $account.=gdetails('Notification preference',gform('preferences',gc('ticket_updates','Receive in-app Guidance ticket updates',!$pref||(bool)$pref['ticket_updates']),'Save preference'));
    $account.=gdetails('Sessions',gform('sessions','<p>Revoke every shared session, including this browser.</p>','Sign out all sessions'));
    $privacy='<p>Download your own records or ask the Head to review a privacy concern.</p><p><a class="btn" href="'.e(url('guidance/export.php')).'">Download my Guidance records</a></p><p class="gd-note">Exports exclude other people’s records and internal notes. A privacy review does not automatically delete records.</p>'.gdetails('Submit a privacy review request',gform('privacy.submit',ga('reason','What would you like reviewed?','','required minlength="10" maxlength="4000"'),'Submit request'));
    echo '<div class="gd-grid">'.gcard('Your campus account',$account).gcard('Your data and privacy',$privacy).'</div>';
    $reviews=gall('SELECT * FROM gd_privacy WHERE user_id=? ORDER BY updated_at DESC',[$uid]);
    if($reviews){$body='';foreach($reviews as $p){$x=gr_ready()?gone('SELECT stage FROM gd_plus_privacy WHERE privacy_id=?',[$p['id']]):null;$stage=$x['stage']??$p['status'];$b='<div class="gu-record-meta">'.gtag($stage).'<span>Updated '.e(fmt_dt($p['updated_at'])).'</span></div><dl><dt>Your request</dt><dd class="gd-pre">'.e($p['reason']).'</dd><dt>Latest response</dt><dd class="gd-pre">'.e($p['response']??'Awaiting review.').'</dd></dl>';
        if(gr_ready())$b.=gu_privacy_history((int)$p['id']);
        if($stage==='awaiting_requester')$b.=gform('x.privacy.reply',gh('id',$p['id']).ga('response','Your additional information','','required minlength="10" maxlength="4000"'),'Send to reviewer');
        $body.='<div data-privacy-record="'.(int)$p['id'].'">'.gcard('Privacy request #'.$p['id'],$b).'</div>';}
        echo gu_group('privacy','Your privacy reviews','Each request, its progress and replies are kept together.',$body);
    }
}
function gu_appointments(array $u,array $appointments): void {
    $staff=g_staff($u);$head=g_head($u);$list='';
    foreach($appointments as $a){
        $body='<div class="gu-record-meta">'.gtag($a['status']).'</div><dl><dt>Student</dt><dd>'.e($a['requester']).'</dd><dt>Counselor</dt><dd>'.e($a['counselor']).'</dd><dt>Starts</dt><dd>'.e(fmt_dt($a['starts_at'])).'</dd><dt>Ends</dt><dd>'.e(fmt_dt($a['ends_at'])).'</dd></dl><p class="gd-pre">'.e($a['concern']).'</p>';
        if(in_array($a['status'],['requested','confirmed'],true))$body.=gdetails('Update appointment',gform('appointment.update',gh('id',$a['id']).gs('status','Status',($head||$staff==='counselor')?gopts(['confirmed','completed','cancelled']):['cancelled'=>'Cancel appointment']),'Update appointment'));
        $list.=gcard('Appointment · '.date('M j, Y',strtotime($a['starts_at'])),$body);
    }
    if(!$appointments)$list=gcard('Your schedule',gempty(is_filer($u)?'No appointments yet. Request a time with a counselor.':'No appointments in your current access scope.'));
    if(is_filer($u)){
        $booking=gform('appointment.create',gs('counselor_id','Counselor',array_column(g_counselors(),'full_name','id'),'','required').gf('starts_at','Start · Philippine time','datetime-local','','required').gf('ends_at','End · Philippine time','datetime-local','','required').ga('concern','Brief reason','','required minlength="5" maxlength="4000" placeholder="For example: I would like help planning my study schedule."').'<p class="gd-note">Choose a future time, up to two hours long. This is a request, not a confirmed booking. Discuss sensitive details with your counselor.</p>','Request appointment');
        echo '<div class="gd-grid"><div>'.$list.'</div><div>'.gcard('Request a time',$booking).'</div></div>';
    }else echo '<div class="gd-grid gu-appointments">'.$list.'</div>';
}
function gu_event(array $u,array $e,array $students): void {
    $uid=(int)$u['id'];$staff=g_staff($u);$id=(int)$e['id'];$plus=gr_ready();
    $inv=gall('SELECT i.*,u.full_name FROM gd_invites i JOIN users u ON u.id=i.user_id WHERE i.event_id=?'.($staff?'':' AND i.user_id='.$uid).' ORDER BY u.full_name',[$id]);
    $mine=null;foreach($inv as $i)if((int)$i['user_id']===$uid)$mine=$i;
    $pol=$plus?(gone('SELECT * FROM gd_plus_events WHERE event_id=?',[$id])??[]):[];
    $issued=(bool)gone('SELECT id FROM gd_certificates WHERE event_id=?',[$id]);
    echo '<div class="gd-buttons">'.gl('events','Back to activities').'</div>';
    echo gu_jumps($staff?['activity'=>'Activity','registration'=>'Registration','attendance'=>'Attendance','certificates'=>'Certificates','activity-settings'=>'Activity settings']:['activity'=>'Activity','my-participation'=>'My participation']);
    $summary='<div class="gu-record-meta">'.gtag($e['status']).gtag($e['kind']).'</div><p class="gd-pre">'.e($e['description']).'</p><dl><dt>Venue</dt><dd>'.e($e['venue']).'</dd><dt>Starts · Philippine time</dt><dd>'.e(fmt_dt($e['starts_at'])).'</dd><dt>Ends</dt><dd>'.e(fmt_dt($e['ends_at'])).'</dd><dt>Capacity</dt><dd>'.(int)$e['capacity'].'</dd><dt>Recognition</dt><dd>'.((int)$e['certificates_enabled']?'Certificate · '.e($e['credit_units']).' credits':'Participation history').'</dd>';
    if($plus)$summary.='<dt>Facilitator</dt><dd>'.e(($pol['facilitator']??'')?:'Not recorded').'</dd><dt>Automatic late threshold</dt><dd>'.e(isset($pol['late_minutes'])?$pol['late_minutes'].' minutes after start':'Not enabled').'</dd>';
    echo gu_group('activity','Activity information','',gcard($e['title'],$summary.'</dl>'));
    if(!$staff){
        if($mine){$body='<div class="gu-record-meta">'.gtag($mine['state']).gtag($mine['attendance']).'</div>';
            if($e['status']==='published'&&!in_array($mine['attendance'],['present','late'],true))$body.=gform('rsvp',gh('id',$id).gs('state','Your response',['registered'=>'Accept invitation','declined'=>'Decline invitation'],$mine['state']),'Save RSVP');
            if($plus){$wait=gone('SELECT state FROM gd_plus_waitlist WHERE invite_id=?',[$mine['id']]);
                if(($wait['state']??'')==='waiting')$body.='<p class="gd-note">You are waiting for a seat. This is not a confirmed registration.</p>'.gform('x.waitlist.withdraw',gh('id',$mine['id']),'Leave waitlist');
                elseif($mine['state']!=='registered'&&$e['status']==='published'&&$e['ends_at']>date('Y-m-d H:i:s')&&!in_array($mine['attendance'],['present','late'],true))$body.=gdetails('If registration is full',gform('x.waitlist.join',gh('id',$id).'<p>Staff must confirm an available seat. Joining the waitlist does not register you.</p>','Join waitlist if full'));
            }
            $pass='';if($mine['state']==='registered'){
                $pass='<p>Show your personal pass to authorized Guidance staff.</p><div class="gd-qr" data-qr="'.e(json_encode(['type'=>'guidance-pass','event'=>$id,'pass'=>$mine['pass_code']])).'"></div><code class="gd-code">'.e($mine['pass_code']).'</code>';
                if(!in_array($mine['attendance'],['present','late'],true))$pass.=gform('checkin',gh('id',$id).gf('code','Event code supplied by Guidance','text','','required autocomplete="off"'),'Check in').'<button class="btn" type="button" data-scan data-event="'.$id.'">Scan event QR</button>';
            }
            echo gu_group('my-participation','My participation','Your invitation, waitlist and personal check-in stay together.','<div class="gd-grid">'.gcard('Your invitation',$body).($pass?gcard('Your personal pass',$pass):'').'</div>');
        }
        return;
    }
    $invite=gform('invite',gh('id',$id).gs('user_ids[]','Select students',array_column($students,'full_name','id'),'','multiple size="6" required').'<p class="hint">Hold Ctrl/Command to select several students. Already invited students are skipped.</p>','Send invitations');
    if($plus)$invite.='<div class="gu-subsection"><h4>Invite by academic cohort</h4><p>Preview matching verified students before confirming invitations.</p>'.gl('cohort','Preview cohort invitations',$id).'</div>';
    $waitBody='<p>Waitlists become available after the restoration migration.</p>';
    if($plus){$rows=[];foreach(gall("SELECT i.id,u.full_name,w.queued_at,w.state FROM gd_plus_waitlist w JOIN gd_invites i ON i.id=w.invite_id JOIN users u ON u.id=i.user_id WHERE i.event_id=? ORDER BY w.queued_at,i.id",[$id]) as $r)$rows[]=[e($r['full_name']),e(fmt_dt($r['queued_at'])),gtag($r['state']),$r['state']==='waiting'?gform('x.waitlist.promote',gh('id',$r['id']),'Confirm seat'):'—'];
        $waitBody=gtable(['Student','Joined','State','Action'],$rows,'No waitlisted students.').'<p class="hint">Staff confirm available seats in eligible first-in order. Promotion is not automatic.</p>';
    }
    echo gu_group('registration','Registration','Individual invitations, cohort invitations and waitlists.','<div class="gd-grid">'.gcard('Invite students',$invite).($plus?gcard('Waitlist',$waitBody):'').'</div>');
    $registered=[];$eligible=[];foreach($inv as $i){if($i['state']==='registered')$registered[$i['user_id']]=$i['full_name'];if($i['state']==='registered'&&in_array($i['attendance'],['present','late'],true))$eligible[$i['user_id']]=$i['full_name'];}
    $desk='<div class="gd-grid"><div class="gu-subsection gu-desk-panel"><h4>Event code for students</h4><p>Share this code only with participants who are present.</p><code class="gd-code">'.e($e['checkin_code']).'</code><div class="gd-qr" data-qr="'.e(json_encode(['type'=>'guidance-event','event'=>$id,'code'=>$e['checkin_code']])).'"></div></div><div class="gu-subsection gu-desk-panel"><h4>Staff check-in</h4><div class="gd-buttons"><button class="btn" type="button" data-scan data-event="'.$id.'" data-code="'.e($e['checkin_code']).'">Scan personal pass</button></div><p class="hint">Camera requires HTTPS or localhost and permission. You can select a participant below instead. Registration, activity status and the check-in window are checked on save.</p>';
    $desk.=gform('checkin',gh('id',$id).gh('code',$e['checkin_code']).gs('user_id','Registered participant',$registered,'','required'),'Record check-in');
    $desk.=gdetails('Manual attendance adjustment',gform('attendance',gs('id','Participant',array_column($inv,'full_name','id'),'','required').gs('attendance','Attendance',gopts(['pending','present','late','absent','excused']),'present'),'Record adjustment')).'</div></div>';
    $meta=[];if($plus)$meta=array_column(gall('SELECT a.*,u.full_name checker FROM gd_plus_attendance a JOIN gd_invites i ON i.id=a.invite_id JOIN users u ON u.id=a.checked_by WHERE i.event_id=?',[$id]),null,'invite_id');
    $rows=[];foreach($inv as $i){$row=[e($i['full_name']),gtag($i['state']),gtag($i['attendance'])];if($plus){$m=$meta[$i['id']]??[];$row[]=e(isset($m['method'])?ucwords(str_replace('_',' ',$m['method'])):'Legacy / not recorded');$row[]=e($m['checker']??'Not recorded');$row[]=e($i['checked_at']?fmt_dt($i['checked_at']):'—');}$rows[]=$row;}
    $attendance=gcard('Attendance desk',$desk).gcard('Participation and attendance',gtable($plus?['Participant','RSVP','Attendance','Method','Recorded by','Check-in time']:['Participant','RSVP','Attendance'],$rows).($plus?'<p class="hint">Method labels describe the submitted client method. The server independently checks identity, registration, code and window.</p>':''));
    echo gu_group('attendance','Attendance','Check in participants and review one combined attendance record.',$attendance);
    $bulk=gform('certificates',gh('id',$id).'<p>Issue certificates to all eligible registered attendees. The activity must be completed and certificates enabled.</p>','Issue eligible certificates');
    $individual=$plus?($eligible?gform('x.certificate',gh('id',$id).gs('user_id','Eligible attendee',$eligible),'Issue one certificate'):'<p>No eligible attendees yet.</p>'):'';
    echo gu_group('certificates','Certificates','Bulk and individual issuance use the same eligibility and duplicate checks.','<div class="gd-buttons">'.($plus?gl('register','Certificate register'):'').'</div><div class="gd-grid">'.gcard('Issue to all eligible attendees',$bulk).($plus?gcard('Issue to one attendee',$individual):'').'</div>');
    $settings=gcard('Activity details',$issued?'<p class="gd-note">Locked after certificate issuance. Contact the Head for a documented correction process.</p>':gdetails('Edit activity',geform($e)));
    if($plus){$policy=gh('id',$id).gf('facilitator','Facilitator','text',$pol['facilitator']??'','maxlength="160"').gc('auto_late','Automatically mark late after the threshold',isset($pol['late_minutes'])).gf('late_minutes','Minutes after activity start','number',$pol['late_minutes']??15,'min="0" max="240"').gc('manual_window','Require manual adjustments inside the check-in window',(bool)($pol['manual_window']??0)).'<p class="gd-note">QR/code check-in always enforces the activity window. When unchecked, staff may make audited manual adjustments after the activity starts.</p>';
        $settings.=gcard('Facilitator and check-in policy',$issued?'<p class="gd-note">Policy is locked after certificate issuance.</p>':gdetails('Edit attendance policy',gform('x.event.policy',$policy,'Save policy')));
    }
    echo gu_group('activity-settings','Activity settings','Schedule, recognition and attendance policy.','<div class="gd-grid">'.$settings.'</div>');
}
