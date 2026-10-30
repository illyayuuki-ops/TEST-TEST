<?php
if(PHP_SAPI!=='cli'||getenv('LCCT_SYNTHETIC_TEST')!=='1')exit('Synthetic CLI only.');
require dirname(__DIR__,2).'/config/app.php';
require dirname(__DIR__).'/domain.php';
require dirname(__DIR__).'/workbench.php';
if(!str_ends_with(DB_NAME,'_test')||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Isolated local _test database required.');
$checks=[];
function wc(string $name,bool $ok):void { global $checks;$checks[]=['name'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name); }
function wb(string $name,callable $f,int $code=400):void { try{$f();wc($name,false);}catch(RuntimeException $e){wc($name,$e->getCode()===$code);} }
$pdo->beginTransaction();
try {
 $h=gone("SELECT * FROM users WHERE username='guide_head'");$c=gone("SELECT * FROM users WHERE username='guide_counselor'");$other=gone("SELECT * FROM users WHERE username='guide_other'");$ops=gone("SELECT * FROM users WHERE username='guide_admin'");$s=gone("SELECT * FROM users WHERE username='student_one'");$s2=gone("SELECT * FROM users WHERE username='student_two'");$sao=gone("SELECT * FROM users WHERE username='sao_demo'");
 $tag='PARITY-'.bin2hex(random_bytes(4));
 gq("INSERT INTO categories(office_code,name) VALUES('guide',?)",[$tag]);$cat=(int)$pdo->lastInsertId();
 gq("INSERT INTO users(username,email,full_name,school_id,password,role,status,verification) VALUES(?,?,?,?,?,'student','active','verified')",[$tag,$tag.'@example.invalid','Unlinked '.$tag,$tag,password_hash(bin2hex(random_bytes(30)),PASSWORD_DEFAULT)]);$unlinked=(int)$pdo->lastInsertId();
 $ids=[];
 for($i=0;$i<36;$i++) {
  gq("INSERT INTO tickets(office_code,user_id,category_id,subject,details,priority,assigned_to,status,created_at,updated_at,resolved_at) VALUES('guide',?,?,?,?,'Normal',?,?,?,NOW(),?)",[$s['id'],$cat,$tag.' private subject '.$i,'NEVER-EXPORT-NARRATIVE-'.$tag,$i<30?$c['id']:$other['id'],$i<12?'Resolved':($i===12?'Waiting for User':'Open'),'2024-01-15 10:00:00',$i<12?($i<6?'2024-01-16 10:00:00':'2024-01-20 10:00:00'):null]);
  $id=(int)$pdo->lastInsertId();$ids[]=$id;
  gq('UPDATE tickets SET code=? WHERE id=?',[$tag.'-'.$i,$id]);
  if($i<12) {
   gq("INSERT INTO replies(ticket_id,user_id,message,is_internal,created_at) VALUES(?,?,?,0,'2024-01-15 12:00:00')",[$id,$c['id'],'NEVER-EXPORT-REPLY-'.$tag]);
   gq("INSERT INTO replies(ticket_id,user_id,message,is_internal,created_at) VALUES(?,?,?,1,'2024-01-15 11:00:00')",[$id,$c['id'],'NEVER-EXPORT-INTERNAL-'.$tag]);
   gq("INSERT INTO gd_feedback(ticket_id,rating,feedback,updated_at) VALUES(?,5,?,NOW())",[$id,'NEVER-EXPORT-FEEDBACK-'.$tag]);
  }
 }
 gq("INSERT INTO tickets(office_code,user_id,category_id,subject,details,priority,assigned_to) VALUES('guide',?,?,?,?,'Urgent',?)",[$s2['id'],$cat,'OTHER-COUNSELOR-'.$tag,'Hidden clinical note',$other['id']]);$private=(int)$pdo->lastInsertId();
 gq("INSERT INTO gd_appointments(user_id,counselor_id,starts_at,ends_at,concern,status,updated_at) VALUES(?,?,'2024-01-01 12:00:00','2024-01-01 13:00:00',?,'completed',NOW())",[$s2['id'],$c['id'],'Visible appointment']);$appt=(int)$pdo->lastInsertId();
 gq("INSERT INTO gd_appointments(user_id,counselor_id,starts_at,ends_at,concern,status,updated_at) VALUES(?,?,'2024-01-02 12:00:00','2024-01-02 13:00:00',?,'completed',NOW())",[$s2['id'],$other['id'],'HIDDEN-APPOINTMENT-'.$tag]);
 wc('Head authorized request membership',in_array($private,array_column(gw_cases($h),'id')));
 wc('Counselor does not receive another counselor case',!in_array($private,array_column(gw_cases($c),'id')));
 wc('Operations receives no clinical cases',gw_cases($ops)===[]);
 wc('Other office receives no Guidance cases',gw_cases($sao)===[]);
 wc('Student case scope',!in_array($private,array_column(gw_cases($s),'id')));
 wc('Head directory includes unlinked verified student',in_array($unlinked,array_column(gw_directory($h),'id')));
 wc('Counselor directory excludes unlinked student',!in_array($unlinked,array_column(gw_directory($c),'id')));
 wc('Own appointment links student to directory',in_array($s2['id'],array_column(gw_directory($c),'id')));
 foreach([$ops,$s,$sao] as $actor)wb('Directory denied '.$actor['username'],fn()=>gw_directory($actor),403);
 foreach([['from'=>'2026-02-30'],['from'=>'2026-10-30','to'=>'2026-10-01'],['q'=>[]],['status'=>'bogus'],['layout'=>'drag'],['category'=>'1 OR 1=1'],['page'=>'-1'],['q'=>str_repeat('a',121)],['to'=>['bad']],['sort'=>'DROP']] as $bad)wb('Invalid filters '.json_encode($bad),fn()=>gw_filters($bad));
 wc('Leap day accepted',gw_dates(['from'=>'2024-02-29'])['from']==='2024-02-29');
 $f=gw_filters(['category'=>(string)$cat,'from'=>'2024-01-15','to'=>'2024-01-15']);$rows=gw_filter_cases(gw_cases($h),$f,$h);
 wc('Creation dates inclusive',count($rows)===36);
 wc('Status filter',count(gw_filter_cases($rows,array_replace($f,['status'=>'Resolved']),$h))===12);
 wc('Overdue excludes Waiting for User and resolved',count(gw_filter_cases($rows,array_replace($f,['due'=>'overdue']),$h))===23);
 wc('Filter cannot widen counselor scope',count(gw_filter_cases(gw_cases($c),$f,$c))===30);
 wc('Selected counselor filter',count(gw_filter_cases($rows,array_replace($f,['assigned'=>'person','counselor'=>(int)$other['id']]),$h))===6);
 wc('Assigned to me filter',count(gw_filter_cases($rows,array_replace($f,['assigned'=>'me']),$c))===30);
 wc('Unassigned filter',count(gw_filter_cases($rows,array_replace($f,['assigned'=>'unassigned']),$c))===0);
 wc('Search case-insensitive',count(gw_filter_cases($rows,array_replace($f,['q'=>strtolower($tag)]),$h))===36);
 wc('Pagination page size',count(gw_page($rows,1)['rows'])===25);
 wc('Pagination second page',count(gw_page($rows,2)['rows'])===11);
 wc('Pagination out-of-bounds clamp',gw_page($rows,999)['page']===2);
 $report=gw_analytics($h,gw_report_filters(['category'=>(string)$cat,'from'=>'2024-01-15','to'=>'2024-01-15']));$overview=array_column($report['Overview'],1,0);
 wc('Aggregate cohort count',$overview['Requests in intake cohort']===36);
 wc('Public staff response excludes internal reply',$overview['Mean first response hours']===2.0);
 wc('Resolution mean hours',$overview['Mean resolution hours']===72.0);
 wc('Satisfaction mean excludes narrative',$overview['Mean satisfaction / 5']===5.0);
 wc('SLA proportion eligible cells',$overview['Resolved within target %']===50.0);
 wc('Small status count suppressed',array_column($report['Status'],1,0)['Waiting for User']==='Suppressed (<5)');
 $text=json_encode($report);
 foreach([$s['full_name'],$s['school_id'],'private subject','NEVER-EXPORT','ticket_id','user_id'] as $secret)wc('Aggregate excludes '.$secret,!str_contains($text,$secret));
 wc('Zero cells suppressed',gw_suppressed(0)==='Suppressed (<5)');wc('Four cells suppressed',gw_suppressed(4)==='Suppressed (<5)');wc('Five cells visible',gw_suppressed(5)===5);
 wb('Student cannot request analytics',fn()=>gw_analytics($s,gw_report_filters([])),403);
 $fixture=['tag'=>$tag,'category'=>$cat,'student'=>(int)$s['id'],'student_two'=>(int)$s2['id'],'unlinked'=>$unlinked,'counselor'=>(int)$c['id'],'other'=>(int)$other['id'],'private'=>$private,'ids'=>$ids,'appointment'=>$appt];
 $pdo->commit();
 file_put_contents(__DIR__.'/output/workbench-fixture.json',json_encode($fixture,JSON_PRETTY_PRINT));
} finally {
 if($pdo->inTransaction())$pdo->rollBack();
 @mkdir(__DIR__.'/output',0775,true);$result=['passed'=>count(array_filter($checks,fn($c)=>$c['passed'])),'total'=>count($checks),'checks'=>$checks];file_put_contents(__DIR__.'/output/workbench-unit.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode(['passed'=>$result['passed'],'total'=>$result['total']])."\n";
}
