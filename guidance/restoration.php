<?php
if(!function_exists('g_actor')){http_response_code(404);exit;}
function gr_ready():bool {static $ready;return $ready??=g_ready()&&(bool)gone("SELECT setting_key FROM gd_settings WHERE setting_key='restoration_plus' AND setting_value='1'");}
function gr_priorities():array{return gr_ready()?['Low','Normal','High','Urgent']:PRIORITIES;}
function gr_case(array $u,int $id):array{$t=gone("SELECT * FROM tickets WHERE id=? AND office_code='guide'",[$id]);gx($t&&g_case($u,$t),'Request not found.',404);return $t;}
function gr_enrich(array $t):array{
 if(!gr_ready())return $t;
 $m=gone('SELECT * FROM gd_plus_cases WHERE ticket_id=?',[$t['id']]);$s=gone('SELECT * FROM gd_plus_services WHERE category_id=?',[$t['category_id']]);
 if(($m['priority_override']??null)==='High'&&$t['priority']==='Normal')$t['priority']='High';
 $t['sla_hours']=$s[strtolower($t['priority']).'_hours']??(['Low'=>120,'Normal'=>72,'High'=>48,'Urgent'=>24][$t['priority']]??72);
 return $t;
}
function gr_notice(int $uid,string $title,string $view,int $id=0):void{
 global $pdo;
 gx(in_array($view,['event','appointments','history','settings','requests'],true));
 // No clinical text. Deep-link target is a fixed server-selected view, not an arbitrary URL.
 gq("INSERT INTO notifications(user_id,title,body) VALUES(?,?,'Open your Guidance workspace for details.')",[$uid,$title]);
 gq('INSERT INTO gd_plus_notices(notification_id,view_name,record_id) VALUES(?,?,?)',[(int)$pdo->lastInsertId(),$view,$id]);
}
function gr_cohort(array $p):array{
 $w=["u.role='student'","u.status='active'","u.verification='verified'"];$args=[];
 foreach(['program'=>120,'year_level'=>40,'section'=>80] as $k=>$max){$v=gt($p,$k,0,$max);if($v!==''){$w[]='m.'.$k.'=?';$args[]=$v;}}
 gx(count($args)>0,'Select at least one academic cohort field.',400);
 return gall('SELECT u.id,u.full_name,u.school_id,m.program,m.year_level,m.section FROM users u JOIN gd_plus_students m ON m.user_id=u.id WHERE '.implode(' AND ',$w).' ORDER BY u.full_name,u.id LIMIT 501',$args);
}
function gr_action(array $u,string $action,array $p):array{
 global $pdo;gx(gr_ready(),'Run the additive restoration migration first.',503);
 $uid=(int)$u['id'];$id=gi($p,'id');$staff=g_staff($u);$head=g_head($u);$view='overview';$msg='Saved.';
 switch($action){
 case 'x.student':
  gx($head);gx((bool)gone("SELECT id FROM users WHERE id=? AND role='student' AND verification='verified'",[$id]),'Verified student not found.',404);
  $vals=[$id,gt($p,'program',1,120),gt($p,'year_level',1,40),gt($p,'section',1,80),gt($p,'source',5,200),$uid];
  gq('INSERT INTO gd_plus_students(user_id,program,year_level,section,source,verified_by,updated_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE program=VALUES(program),year_level=VALUES(year_level),section=VALUES(section),source=VALUES(source),verified_by=VALUES(verified_by),updated_at=NOW()',$vals);$view='students';break;
 case 'x.cohort.invite':
  gx($staff!==null);$rows=gr_cohort($p);gx(count($rows)>0&&count($rows)<=500,'Audience must contain 1–500 verified students. Narrow the cohort.',400);
  $fingerprint=hash('sha256',json_encode(array_column($rows,'id')));gx(hash_equals($fingerprint,gt($p,'audience_hash',64,64)),'Audience changed. Preview it again before inviting.',409);
  $result=g_action($u,'invite',['id'=>$id,'user_ids'=>array_column($rows,'id')]);$view='event';$msg=$result['message'];break;
 case 'x.waitlist.join':
  gx(is_filer($u));$e=g_event($u,$id);$i=gone('SELECT * FROM gd_invites WHERE event_id=? AND user_id=?',[$id,$uid]);
  gx($i&&$e['status']==='published'&&$e['ends_at']>date('Y-m-d H:i:s'),'Registration is closed.',409);
  gx($i['state']!=='registered'&&!in_array($i['attendance'],['present','late'],true),'Already registered or attended.',409);
  gx((int)gq("SELECT COUNT(*) FROM gd_invites WHERE event_id=? AND state='registered'",[$id])->fetchColumn()>=(int)$e['capacity'],'A seat is available: use Register instead.',409);
  $old=gone('SELECT state FROM gd_plus_waitlist WHERE invite_id=?',[$i['id']]);
  if(($old['state']??'')!=='waiting')gq("INSERT INTO gd_plus_waitlist(invite_id,state,queued_at,updated_at) VALUES(?,'waiting',NOW(),NOW()) ON DUPLICATE KEY UPDATE state='waiting',queued_at=NOW(),updated_at=NOW()",[$i['id']]);
  $view='event';$msg='Added to the waitlist. A staff member must offer an available seat; this is not a registration.';break;
 case 'x.waitlist.withdraw':
  gx(is_filer($u));$i=gone('SELECT id,event_id FROM gd_invites WHERE id=? AND user_id=?',[$id,$uid]);gx($i!==null,'Waitlist entry not found.',404);
  gq("UPDATE gd_plus_waitlist SET state='withdrawn',updated_at=NOW() WHERE invite_id=? AND state='waiting'",[$id]);$id=(int)$i['event_id'];$view='event';break;
 case 'x.waitlist.promote':
  gx($staff!==null);$i=gone("SELECT i.* FROM gd_invites i JOIN gd_plus_waitlist w ON w.invite_id=i.id WHERE i.id=? AND w.state='waiting'",[$id]);gx($i!==null,'Waiting entry not found.',404);$e=g_event($u,(int)$i['event_id']);
  gx($e['status']==='published'&&$e['ends_at']>date('Y-m-d H:i:s'),'Registration is closed.',409);
  $first=gone("SELECT i.id FROM gd_invites i JOIN gd_plus_waitlist w ON w.invite_id=i.id JOIN users u ON u.id=i.user_id WHERE i.event_id=? AND w.state='waiting' AND i.state<>'registered' AND u.status='active' AND u.verification='verified' ORDER BY w.queued_at,i.id LIMIT 1",[$e['id']]);gx($first&&(int)$first['id']===$id,'Promote the first eligible waiting student; refresh the queue.',409);
  gx((int)gq("SELECT COUNT(*) FROM gd_invites WHERE event_id=? AND state='registered'",[$e['id']])->fetchColumn()<(int)$e['capacity'],'No seat available.',409);
  gq("UPDATE gd_invites SET state='registered' WHERE id=?",[$id]);gq("UPDATE gd_plus_waitlist SET state='promoted',updated_at=NOW() WHERE invite_id=?",[$id]);gr_notice((int)$i['user_id'],'Guidance waitlist: seat confirmed','event',(int)$e['id']);$id=(int)$e['id'];$view='event';break;
 case 'x.certificate':
  gx($staff!==null);$e=g_event($u,$id);$student=gi($p,'user_id',1);
  gx($e['status']==='completed'&&(int)$e['certificates_enabled'],'Complete an eligible activity first.',409);
  $i=gone("SELECT u.full_name FROM gd_invites i JOIN users u ON u.id=i.user_id WHERE i.event_id=? AND i.user_id=? AND i.state='registered' AND i.attendance IN ('present','late')",[$id,$student]);gx($i!==null,'Eligible attendance required.',409);
  if(!gone('SELECT id FROM gd_certificates WHERE event_id=? AND user_id=?',[$id,$student])){gq('INSERT INTO gd_certificates(event_id,user_id,verify_code,recipient_name,event_title,event_date,credits,issued_by,issued_at) VALUES(?,?,?,?,?,?,?,?,NOW())',[$id,$student,bin2hex(random_bytes(24)),$i['full_name'],$e['title'],$e['starts_at'],$e['credit_units'],$uid]);gr_notice($student,'Guidance certificate issued','history');}
  $view='register';$msg='Certificate available. Duplicate issuance is prevented.';break;
 case 'x.event.policy':
  gx($staff!==null);$e=g_event($u,$id);gx(!gone('SELECT id FROM gd_certificates WHERE event_id=?',[$id]),'Policy is locked after certificate issuance.',409);
  $late=gb($p,'auto_late')?gi($p,'late_minutes',0,240):null;
  gq('INSERT INTO gd_plus_events(event_id,facilitator,late_minutes,manual_window) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE facilitator=VALUES(facilitator),late_minutes=VALUES(late_minutes),manual_window=VALUES(manual_window)',[$id,gt($p,'facilitator',0,160),$late,gb($p,'manual_window')]);$view='event';break;
 case 'x.service':
  gx($head);gx((bool)gone("SELECT id FROM categories WHERE id=? AND office_code='guide'",[$id]),'Guidance service not found.',404);$parent=gi($p,'parent_id');$to=gi($p,'counselor_id');
  gx(!$to||in_array($to,array_map('intval',array_column(g_counselors(),'id')),true),'Choose an active counselor.',400);
  $seen=[$id];$at=$parent;
  while($at){gx(!in_array($at,$seen,true),'Category hierarchy cannot contain a cycle.',400);$seen[]=$at;gx((bool)gone("SELECT id FROM categories WHERE id=? AND office_code='guide'",[$at]),'Choose a Guidance parent.',400);$r=gone('SELECT parent_id FROM gd_plus_services WHERE category_id=?',[$at]);$at=(int)($r['parent_id']??0);}
  $hours=[];foreach(['low','normal','high','urgent'] as $k)$hours[]=gi($p,$k.'_hours',1,8760);
  gx($hours[0]>=$hours[1]&&$hours[1]>=$hours[2]&&$hours[2]>=$hours[3],'Targets must become no longer as priority increases.',400);
  gq('INSERT INTO gd_plus_services(category_id,description,parent_id,counselor_id,low_hours,normal_hours,high_hours,urgent_hours) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description),parent_id=VALUES(parent_id),counselor_id=VALUES(counselor_id),low_hours=VALUES(low_hours),normal_hours=VALUES(normal_hours),high_hours=VALUES(high_hours),urgent_hours=VALUES(urgent_hours)',array_merge([$id,gt($p,'description',0,5000),$parent?:null,$to?:null],$hours));$view='categories';break;
 case 'x.intake':
  gx(is_filer($u)||in_array($staff,['head','counselor'],true));$owner=is_filer($u)?$uid:gi($p,'user_id',1);
  gx((bool)gone("SELECT id FROM users WHERE id=? AND role IN ('student','alumni') AND status='active' AND verification='verified'",[$owner]),'Choose a verified requester.',400);
  $cat=gi($p,'category_id',1);gx((bool)gone("SELECT id FROM categories WHERE id=? AND office_code='guide' AND is_hidden=0",[$cat]),'Choose a Guidance service.',400);
  $priority=ge($p,'priority',gr_priorities(),'Normal');$pref=gi($p,'preferred_counselor');gx(!$pref||in_array($pref,array_map('intval',array_column(g_counselors(),'id')),true),'Choose an active preferred counselor.',400);
  $slot=gt($p,'preferred_slot',0,32);$slot=$slot!==''?gdtime($p,'preferred_slot'):null;gx(!$slot||$slot>date('Y-m-d H:i:s'),'Preferred time must be in the future.',400);
  $service=gone('SELECT counselor_id FROM gd_plus_services WHERE category_id=?',[$cat]);$assigned=(int)($service['counselor_id']??0);
  if(!in_array($assigned,array_map('intval',array_column(g_counselors(),'id')),true))$assigned=0;
  if($staff==='counselor')$assigned=$uid;
  if($head&&gi($p,'assigned_to')){$assigned=gi($p,'assigned_to');gx(in_array($assigned,array_map('intval',array_column(g_counselors(),'id')),true),'Choose an active assigned counselor.',400);}
  [$error,$files]=attach_collect();gx(!$error,$error?:'',400);
  $subject=gt($p,'subject',5,150);$details=gt($p,'details',10,20000);
  $dup=gone("SELECT id FROM tickets WHERE office_code='guide' AND user_id=? AND subject=? AND details=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)",[$owner,$subject,$details]);gx(!$dup,'A matching request was just created. Check your request list before retrying.',409);
  gq("INSERT INTO tickets(office_code,user_id,category_id,subject,details,priority,assigned_to) VALUES('guide',?,?,?,?,?,?)",[$owner,$cat,$subject,$details,$priority==='High'?'Normal':$priority,$assigned?:null]);$id=(int)$pdo->lastInsertId();
  gq('UPDATE tickets SET code=? WHERE id=?',['GUIDE-'.date('y').'-'.str_pad((string)$id,4,'0',STR_PAD_LEFT),$id]);gq('INSERT INTO gd_case_meta(ticket_id,created_by) VALUES(?,?)',[$id,$uid]);
  gq('INSERT INTO gd_plus_cases(ticket_id,priority_override,preferred_counselor,preferred_slot,updated_at) VALUES(?,?,?,?,NOW())',[$id,$priority==='High'?'High':null,$pref?:null,$slot]);
  $msg='Request saved. A preferred counselor/time is a preference, not a confirmed appointment.';g_notice($id);$view='requests';break;
 case 'x.case.review':
  gx(in_array($staff,['head','counselor'],true));$t=gr_case($u,$id);$state=ge($p,'review_status',['pending','in_review','accepted','needs_information']);
  $reason=gt($p,'reason',5,2000);$priority=ge($p,'priority',gr_priorities());
  gq('UPDATE tickets SET priority=?,updated_at=NOW() WHERE id=?',[$priority==='High'?'Normal':$priority,$id]);
  gq('INSERT INTO gd_plus_cases(ticket_id,priority_override,review_status,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE priority_override=VALUES(priority_override),review_status=VALUES(review_status),updated_at=NOW()',[$id,$priority==='High'?'High':null,$state]);
  gq('INSERT INTO gd_plus_case_log(ticket_id,actor_id,action,reason,created_at) VALUES(?,?,?,?,NOW())',[$id,$uid,'review:'.$state,$reason]);g_notice($id);$view='review';break;
 case 'x.case.transition':
  $t=gr_case($u,$id);$status=ge($p,'status',['Escalated','Cancelled']);gx(!in_array($t['status'],['Closed','Cancelled'],true),'Request is finished.',409);
  gx($status==='Cancelled'||in_array($staff,['head','counselor'],true));$reason=gt($p,'reason',5,2000);
  gx($status!=='Cancelled'||is_filer($u)||in_array($staff,['head','counselor'],true),'Only the requester or authorized clinical staff can cancel.',403);
  gx(change_ticket_status($pdo,$t,$status,$u),'Transition is not allowed from the current status.',409);gq('INSERT INTO gd_plus_case_log(ticket_id,actor_id,action,reason,created_at) VALUES(?,?,?,?,NOW())',[$id,$uid,$status,$reason]);g_notice($id);$view='review';break;
 case 'x.staff.edit':
 case 'x.staff.reset':
  gx($head);$target=gone("SELECT u.* FROM users u JOIN gd_staff s ON s.user_id=u.id WHERE u.id=? AND u.role='admin' AND u.office_code='guide'",[$id]);gx($target!==null,'Guidance staff not found.',404);
  if($action==='x.staff.edit'){
   $email=gt($p,'email',3,120);gx((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid email.',400);gx(!gone('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id]),'Email already in use.',409);
   gq('UPDATE users SET full_name=?,email=? WHERE id=?',[gt($p,'full_name',3,120),$email,$id]);
  }else{
   gx($id!==$uid,'Use your own Change password screen.',400);
   $me=gone('SELECT password FROM users WHERE id=?',[$uid]);gx(password_verify(gp_value($p,'current_password'),$me['password']),'Current head password is incorrect.',403);
   $pw=gp_value($p,'password');$err=gp_error($pw);gx($err===null,$err??'',400);gx($pw===gp_value($p,'password_confirm'),'Temporary passwords do not match.',400);
   gq('UPDATE users SET password=?,must_change_password=1 WHERE id=?',[gp_hash($pw),$id]);gq('DELETE FROM gd_sessions WHERE user_id=?',[$id]);
   $msg='Temporary password set and sessions revoked. Share privately after verifying the staff member’s identity. No recovery email was sent.';
  }$view='team';break;
 case 'x.privacy':
  gx($head);$r=gone('SELECT * FROM gd_privacy WHERE id=?',[$id]);gx($r!==null,'Privacy request not found.',404);$stage=ge($p,'stage',['in_review','awaiting_requester','completed','declined']);$response=gt($p,'response',10,4000);
  gq('INSERT INTO gd_plus_privacy(privacy_id,stage,updated_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE stage=VALUES(stage),updated_by=VALUES(updated_by),updated_at=NOW()',[$id,$stage,$uid]);
  gq('INSERT INTO gd_plus_privacy_log(privacy_id,stage,response,actor_id,created_at) VALUES(?,?,?,?,NOW())',[$id,$stage,$response,$uid]);
  gq('UPDATE gd_privacy SET status=?,response=?,reviewed_by=?,updated_at=NOW() WHERE id=?',[in_array($stage,['completed','declined'],true)?'reviewed':'submitted',$response,$uid,$id]);gr_notice((int)$r['user_id'],'Guidance privacy review updated','settings');$view='privacy';break;
 case 'x.privacy.reply':
  $r=gone('SELECT p.id,x.stage FROM gd_privacy p JOIN gd_plus_privacy x ON x.privacy_id=p.id WHERE p.id=? AND p.user_id=?',[$id,$uid]);gx($r!==null,'Privacy request not found.',404);gx($r['stage']==='awaiting_requester','This review is not waiting for a requester response.',409);$response=gt($p,'response',10,4000);
  gq("INSERT INTO gd_plus_privacy_log(privacy_id,stage,response,actor_id,created_at) VALUES(?,'requester_reply',?,?,NOW())",[$id,$response,$uid]);
  gq("UPDATE gd_plus_privacy SET stage='in_review',updated_by=?,updated_at=NOW() WHERE privacy_id=?",[$uid,$id]);gq('UPDATE gd_privacy SET updated_at=NOW() WHERE id=?',[$id]);$view='settings';break;
 case 'x.notices.readall':
  gq('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL',[$uid]);$view='notifications';break;
 case 'x.notice.read':
  gx((bool)gone('SELECT id FROM notifications WHERE id=? AND user_id=?',[$id,$uid]),'Notice not found.',404);gq('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?',[$id,$uid]);$view='notifications';break;
 default:gx(false,'Unknown restoration action.',404);
 }
 gq("UPDATE gd_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='plus_revision'");g_audit($u,$action,(string)$id);
 return ['_attachments'=>$files??[],'message'=>$msg,'redirect'=>url('guidance/index.php?view='.$view.(in_array($view,['event','review','students'],true)&&$id?'&id='.$id:'')),'id'=>$id];
}
function gr_after(array $u,string $action,array $p,?array $invite=null):void{
 if(!gr_ready())return;
 if(in_array($action,['checkin','attendance'],true)&&$invite){
  $e=g_event($u,(int)$invite['event_id']);$policy=gone('SELECT * FROM gd_plus_events WHERE event_id=?',[$e['id']]);
  if($action==='attendance'&&($policy['manual_window']??0))gx(time()>=strtotime($e['starts_at'])-(int)$e['minutes_before']*60&&time()<=strtotime($e['ends_at'])+(int)$e['minutes_after']*60,'Manual adjustment is outside the configured attendance window.',409);
  if($action==='attendance'||!in_array($invite['attendance'],['present','late'],true)){
   if($action==='checkin'&&isset($policy['late_minutes'])&&time()>strtotime($e['starts_at'])+(int)$policy['late_minutes']*60)gq("UPDATE gd_invites SET attendance='late' WHERE id=?",[$invite['id']]);
   $method=$action==='attendance'?'staff_adjustment':((($p['method']??'')==='qr'?'qr':'code').(g_staff($u)?'_staff':'_self'));
   gq('INSERT INTO gd_plus_attendance(invite_id,method,checked_by,updated_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE method=VALUES(method),checked_by=VALUES(checked_by),updated_at=NOW()',[$invite['id'],$method,$u['id']]);
  }
 }
 if($action==='rsvp')gq("UPDATE gd_plus_waitlist w JOIN gd_invites i ON i.id=w.invite_id SET w.state=?,w.updated_at=NOW() WHERE i.event_id=? AND i.user_id=? AND w.state='waiting'",[($p['state']??'')==='registered'?'promoted':'withdrawn',$p['id'],$u['id']]);
 gq("UPDATE gd_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1 WHERE setting_key='plus_revision'");
}
function gr_query(array $p):array{
 $q=gw_dates($p)+['page'=>max(1,gw_id($p,'page')),'q'=>gw_text($p,'q'),'id'=>gw_id($p,'id'),'category'=>gw_id($p,'category')];
 foreach(['program'=>120,'year_level'=>40,'section'=>80,'stage'=>40,'actor'=>120,'action'=>80] as $k=>$max)$q[$k]=gw_text($p,$k,$max);
 return $q;
}
function gr_audit_rows(array $q,bool $export=false):array{
 $w=["office_code='guide'"];$a=[];
 foreach(['username'=>'actor','action'=>'action'] as $col=>$key)if($q[$key]!==''){$w[]=$col.'=?';$a[]=$q[$key];}
 if($q['from']){$w[]='created_at>=?';$a[]=$q['from'].' 00:00:00';}if($q['to']){$w[]='created_at<?';$a[]=(new DateTimeImmutable($q['to']))->modify('+1 day')->format('Y-m-d');}
 if($q['q']!==''){ $escaped=str_replace(['!','%','_'],['!!','!%','!_'],$q['q']);$w[]="(username LIKE ? ESCAPE '!' OR action LIKE ? ESCAPE '!')";$a[]='%'.$escaped.'%';$a[]='%'.$escaped.'%'; }
 $actor=$export?"CASE WHEN role='admin' THEN username WHEN role IS NULL THEN 'System' ELSE 'Requester (identity withheld)' END":'username';
 $rows=gall('SELECT created_at,'.$actor.' AS username,action FROM audit_logs WHERE '.implode(' AND ',$w).' ORDER BY id DESC',$a);
 return $rows;
}
