import os,subprocess,json,pathlib,tempfile,secrets,base64,concurrent.futures
assert os.environ.get('LCCT_SYNTHETIC_TEST')=='1'
R=pathlib.Path(__file__).resolve().parents[2];O=R/'guidance/tests/output';checks=[]
def ck(n,v):
 checks.append({'name':n,'passed':bool(v)})
 if not v:raise AssertionError(n)
def php(*a,env=None):return subprocess.run(['php',*a],cwd=R,env=env or os.environ.copy(),text=True,capture_output=True)
def code(s):
 pre='require "config/app.php";require "guidance/domain.php";if(getenv("LCCT_SYNTHETIC_TEST")!=="1"||!str_ends_with(DB_NAME,"_test")||!in_array(DB_HOST,["127.0.0.1","localhost","::1"],true))exit(1);'
 r=php('-r',pre+s)
 if r.returncode:raise RuntimeError(r.stderr+r.stdout)
 return json.loads(r.stdout)
try:
 before=code('$r=[];foreach(["users","tickets","gd_invites","gd_plus_students","gd_plus_cases"] as $t)$r[$t]=gq("SELECT COUNT(*) FROM `$t`")->fetchColumn();echo json_encode($r);')
 plan=php('guidance/restoration-migrate.php');ck('Migration defaults to plan only',plan.returncode==0 and 'PLAN ONLY' in plan.stdout)
 r=php('guidance/restoration-migrate.php','--apply');ck('Migration repeat succeeds',r.returncode==0)
 after=code('$r=[];foreach(["users","tickets","gd_invites","gd_plus_students","gd_plus_cases"] as $t)$r[$t]=gq("SELECT COUNT(*) FROM `$t`")->fetchColumn();echo json_encode($r);');ck('Migration preserves row counts',before==after)
 code('gq("ALTER TABLE gd_plus_students ADD synthetic_unexpected INT NULL");echo "true";')
 try:r=php('guidance/restoration-migrate.php','--apply');ck('Unexpected table shape refuses migration',r.returncode!=0 and 'unexpected shape' in r.stderr.lower())
 finally:code('gq("ALTER TABLE gd_plus_students DROP COLUMN synthetic_unexpected");echo "true";')
 ck('Normal schema still valid',php('guidance/restoration-migrate.php').returncode==0)
 env=os.environ.copy();env['LCCT_GUIDANCE_BACKUP_KEY']=base64.b64encode(secrets.token_bytes(32)).decode()
 with tempfile.TemporaryDirectory(prefix='guidance-plus-backup-',dir=R.parent) as td:
  p=pathlib.Path(td)/'backup.lcctgd';r=php('guidance/backup.php','create',str(p),env=env);ck('Encrypted backup includes new schema',r.returncode==0)
  r=php('guidance/restoration-ops.php',str(p),env=env);ck('New backup integrity verified and success recorded',r.returncode==0)
  ck('Backup marker is present',bool(code('echo json_encode(gone("SELECT setting_value FROM gd_settings WHERE setting_key=\'last_plus_backup\'"));')))
  ck('No plaintext private narrative in backup',b'PRIVATE-' not in p.read_bytes())
  bad=dict(env,LCCT_GUIDANCE_BACKUP_KEY=base64.b64encode(secrets.token_bytes(32)).decode());ck('Wrong key cannot record verified success',php('guidance/restoration-ops.php',str(p),env=bad).returncode!=0)
  damaged=pathlib.Path(td)/'tampered.lcctgd';data=bytearray(p.read_bytes());data[-1]^=1;damaged.write_bytes(data);ck('Tampered new snapshot rejected',php('guidance/restoration-ops.php',str(damaged),env=env).returncode!=0)
  ck('Backup never overwrites existing file',php('guidance/backup.php','create',str(p),env=env).returncode!=0)
 # Separate PHP processes compete for one real database seat, not one-thread HTTP serialization.
 eid=code('$h=gone("SELECT * FROM users WHERE username=\'guide_head\'");$p=["title"=>"Concurrent reservation test ".bin2hex(random_bytes(3)),"description"=>"Synthetic only","kind"=>"workshop","status"=>"published","starts_at"=>date("Y-m-d\\TH:i",time()+3600),"ends_at"=>date("Y-m-d\\TH:i",time()+7200),"venue"=>"Synthetic room","capacity"=>1,"session_no"=>1,"minutes_before"=>30,"minutes_after"=>60,"credit_units"=>"0"]; $e=g_transaction(fn()=>g_action($h,"event.save",$p));$ids=array_column(gall("SELECT id FROM users WHERE username IN (\'student_one\',\'student_two\')"),"id");g_transaction(fn()=>g_action($h,"invite",["id"=>$e["id"],"user_ids"=>$ids]));echo json_encode($e["id"]);')
 def reserve(name):return code('$u=gone("SELECT * FROM users WHERE username=?",['+json.dumps(name)+']);try{g_transaction(fn()=>g_action($u,"rsvp",["id"=>'+str(eid)+',"state"=>"registered"]));echo "200";}catch(RuntimeException $e){echo json_encode($e->getCode());}')
 with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:states=list(pool.map(reserve,['student_one','student_two']))
 ck('Concurrent reservations produce one success and one full response',sorted(states)==[200,409]);ck('Concurrent reservations do not overbook',code('echo json_encode((int)gq("SELECT COUNT(*) FROM gd_invites WHERE event_id=? AND state=\'registered\'",['+str(eid)+'])->fetchColumn());')==1)
 r=php('guidance/jobs.php');ck('Guidance jobs still default to dry run',r.returncode==0 and json.loads(r.stdout)['dry_run'])
 # Check pre-migration feature gate without deleting tables or modifying any school records.
 code('gq("UPDATE gd_settings SET setting_value=\'0\' WHERE setting_key=\'restoration_plus\'");echo "true";')
 try:ck('Feature gate safely disables extension',code('echo json_encode(!gr_ready()&&g_ready());') is True)
 finally:code('gq("UPDATE gd_settings SET setting_value=\'1\' WHERE setting_key=\'restoration_plus\'");echo "true";')
 ck('Feature gate returns to ready',code('echo json_encode(gr_ready());') is True)
 script=(R/'guidance/schedule-restoration.ps1').read_text();ck('Scheduler refuses existing task and has no retention deletion','Get-ScheduledTask -TaskName' in script and 'Remove-Item' not in script and 'Remove-ScheduledTask' not in script);ck('Scheduler uses interactive limited principal without credentials','-LogonType Interactive -RunLevel Limited' in script and '-Password' not in script)
finally:
 r={'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks,'windows_task_execution':'Not executed in Linux; PowerShell scheduler requires Windows UAT.'};(O/'restoration-operations.json').write_text(json.dumps(r,indent=2));print(json.dumps({k:r[k] for k in ['passed','total']}))
