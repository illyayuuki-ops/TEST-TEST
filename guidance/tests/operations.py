"""Local synthetic only; migration, scheduler and encrypted snapshot checks."""
import os,subprocess,pathlib,json,base64,secrets,tempfile
assert os.environ.get('LCCT_SYNTHETIC_TEST')=='1'
ROOT=pathlib.Path(__file__).resolve().parents[2];checks=[]
def check(n,c):
 checks.append({'name':n,'passed':bool(c)})
 if not c:raise AssertionError(n)
def php(script,*args,env=None):return subprocess.run(['php',script,*args],cwd=ROOT,env=env or os.environ.copy(),text=True,capture_output=True)
def sql(code):
 r=php('-r',"require 'config/app.php';"+code)
 if r.returncode:raise RuntimeError(r.stderr)
 return json.loads(r.stdout)
try:
 counts="echo json_encode([gq('SELECT COUNT(*) FROM users')->fetchColumn(),gq('SELECT COUNT(*) FROM tickets')->fetchColumn(),gq('SELECT COUNT(*) FROM gd_staff')->fetchColumn()]);"
 before=sql(counts);r=php('guidance/migrate.php');check('Migration can run again',r.returncode==0);check('Migration preserves shared accounts/tickets/memberships',sql(counts)==before)
 r=php('guidance/tests/setup.php');check('Synthetic setup refuses nonempty database',r.returncode!=0 and 'completely empty' in (r.stdout+r.stderr).lower())
 # Create only synthetic old cases to check the scheduled boundary.
 ids=sql("$u=gone(\"SELECT id FROM users WHERE username='student_one'\");$ids=[];foreach(['guide','sao'] as $o){gq(\"INSERT INTO tickets(office_code,user_id,category_id,subject,details,priority,created_at) VALUES(?,?,(SELECT id FROM categories WHERE office_code=? LIMIT 1),'Synthetic SLA job','Synthetic record for isolated tests','Urgent',DATE_SUB(NOW(),INTERVAL 5 DAY))\",[$o,$u['id'],$o]);$ids[]=(int)$pdo->lastInsertId();}echo json_encode($ids);")
 status=lambda:sql("echo json_encode(gall('SELECT id,office_code,status FROM tickets WHERE id IN ("+','.join(map(str,ids))+ ") ORDER BY id'));")
 r=php('guidance/jobs.php');check('Scheduler defaults to dry run',r.returncode==0 and json.loads(r.stdout)['dry_run']);check('Dry run does not change cases',all(t['status']=='Open' for t in status()))
 r=php('guidance/jobs.php','--apply');check('Scheduler applies',r.returncode==0);rows=status();check('Scheduler escalates only Guidance',rows[0]['status']=='Escalated' and rows[1]['status']=='Open');r=php('guidance/jobs.php','--apply');check('Scheduler rerun does not duplicate escalation',r.returncode==0 and json.loads(r.stdout)['guidance_overdue_to_escalate']==0)
 env=os.environ.copy();env['LCCT_GUIDANCE_BACKUP_KEY']=base64.b64encode(secrets.token_bytes(32)).decode()
 with tempfile.TemporaryDirectory(prefix='guidance-verify-',dir=ROOT.parent) as temp:
  p=pathlib.Path(temp)/'snapshot.lcctgd';r=php('guidance/backup.php','create',str(p),env=env);check('Encrypted Guidance snapshot created',r.returncode==0 and p.exists());check('No plaintext case or staff password in file',b'Synthetic SLA job' not in p.read_bytes() and b'GuidanceDemo2026!' not in p.read_bytes());r=php('guidance/backup.php','verify',str(p),env=env);check('Encrypted snapshot verifies',r.returncode==0 and 'table sets' in r.stdout)
  r=php('guidance/backup.php','create',str(p),env=env);check('Existing backup not overwritten',r.returncode!=0)
  wrong=dict(env,LCCT_GUIDANCE_BACKUP_KEY=base64.b64encode(secrets.token_bytes(32)).decode());r=php('guidance/backup.php','verify',str(p),env=wrong);check('Wrong encryption key rejected',r.returncode!=0)
  data=bytearray(p.read_bytes());data[-1]^=1;t=pathlib.Path(temp)/'changed.lcctgd';t.write_bytes(data);r=php('guidance/backup.php','verify',str(t),env=env);check('Backup tampering rejected',r.returncode!=0)
  r=php('guidance/backup.php','create',str(ROOT/'public-test.lcctgd'),env=env);check('Public application-root backup rejected',r.returncode!=0 and not (ROOT/'public-test.lcctgd').exists())
finally:
 out=ROOT/'guidance/tests/output';out.mkdir(exist_ok=True);(out/'operations.json').write_text(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks},indent=2));print(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks)}))
