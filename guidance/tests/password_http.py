"""Run only against a local synthetic fixture. Does not use real accounts or passwords."""
import os, re, json, uuid, pathlib, subprocess, requests
from urllib.parse import urlparse
assert os.environ.get('LCCT_SYNTHETIC_TEST') == '1'
B=os.environ.get('GUIDANCE_BASE','http://127.0.0.1:8081')
assert urlparse(B).hostname in ('127.0.0.1','localhost','::1')
ROOT=pathlib.Path(__file__).resolve().parents[2]; checks=[]
def ck(name,ok):
 checks.append({'name':name,'passed':bool(ok)})
 if not ok: raise AssertionError(name)
def csrf(r): return re.search(r'name="csrf" value="([^"]+)"',r.text)[1]
def login(user,pw='GuidanceDemo2026!'):
 s=requests.Session(); r=s.get(B+'/auth/login.php'); r=s.post(B+'/auth/login.php',data={'csrf':csrf(r),'ident':user,'password':pw},allow_redirects=False)
 ck('Sign-in: '+user,r.status_code==302); return s
def php(code,data=None):
 bootstrap="require 'config/app.php'; if(getenv('LCCT_SYNTHETIC_TEST')!=='1'||!str_ends_with(DB_NAME,'_test'))exit(2); $x=json_decode(stream_get_contents(STDIN),true); "
 r=subprocess.run(['php','-r',bootstrap+code],cwd=ROOT,input=json.dumps(data or {}),capture_output=True,text=True,check=True)
 return json.loads(r.stdout)
def api(s,data,code=200):
 r=s.get(B+'/guidance/index.php?view=settings'); token=csrf(r)
 r=s.post(B+'/guidance/action.php',data={'csrf':token,**data}); ck('Action status '+str(code),r.status_code==code); return r
P='/auth/change_password.php?from=guidance'
try:
 ck('Password include cannot be opened directly',requests.get(B+'/guidance/password-page.php').status_code==404)
 ck('Anonymous password request requires sign-in',requests.get(B+P,allow_redirects=False).status_code==302)
 student=login('student_two'); sao=login('sao_demo'); head=login('guide_head'); admin=login('guide_admin')
 ck('Student Guidance entry has new controls','data-gp-new' in student.get(B+P).text)
 ck('Student ordinary portal retains its original form','data-gp-new' not in student.get(B+'/auth/change_password.php').text)
 ck('Other office retains original form even with Guidance query','data-gp-new' not in sao.get(B+P).text)
 ck('Guidance staff always use stronger form','data-gp-new' in head.get(B+'/auth/change_password.php').text)
 page=student.get(B+P)
 ck('Header Settings link and Guidance context preserved','href="/guidance/index.php?view=settings"' in page.text and 'My requests' in page.text)
 ck('CSRF required',student.post(B+P,data={'current':'x','new':'x','new2':'x'}).status_code==419)
 name='pw_'+uuid.uuid4().hex[:10]
 base={'action':'staff.create','username':name,'email':name+'@example.invalid','full_name':'Synthetic password review','guidance_role':'counselor'}
 for pw in ['Aa1'+'x'*8,'Aa1'+'x'*126,'aa1'+'x'*12,'AA1'+'X'*12,'Aaa'+'x'*12,'Aa1'+'x'*8+'\x00']:
  api(head,{**base,'password':pw,'password_confirm':pw},400)
 api(head,{**base,'password':'Acceptable2026!'},400)
 api(head,{**base,'password':'Acceptable2026!','password_confirm':'Different2026!'},400)
 api(admin,{**base,'password':'Acceptable2026!','password_confirm':'Acceptable2026!'},403)
 # 128 Unicode code points, >72 bytes, with meaningful leading/trailing spaces.
 temporary=' Aa1'+'é'*123+' '
 ck('Test password has exactly 128 characters',len(temporary)==128)
 saved=api(head,{**base,'password':temporary,'password_confirm':temporary}).json()
 uid=saved['id']; ck('Helpful create-account success response','Share the temporary password privately' in saved['message'])
 h=php("$r=gone('SELECT password,must_change_password FROM users WHERE id=?',[$x['id']]); echo json_encode(['algo'=>password_get_info($r['password'])['algoName'],'exact'=>password_verify($x['pw'],$r['password']),'trimmed'=>password_verify(trim($x['pw']),$r['password']),'tail'=>password_verify(mb_substr($x['pw'],0,127).'X',$r['password']),'forced'=>(int)$r['must_change_password']]);",{'id':uid,'pw':temporary})
 ck('Argon2id selected',h['algo']=='argon2id'); ck('Full Unicode password verifies',h['exact']); ck('Spaces are preserved',not h['trimmed']); ck('Difference beyond bcrypt limit is rejected',not h['tail']); ck('New staff must change temporary password',h['forced']==1)
 staff=login(name,temporary); other=login(name,temporary)
 r=staff.get(B+'/guidance/index.php'); ck('Forced-change screen reached','Set your personal password' in r.text)
 ck('Forced-change screen has no cancel bypass','Back to Guidance settings' not in r.text)
 for current,new,confirm,message in [('wrong', 'Acceptable2026!', 'Acceptable2026!', 'Current password is incorrect'),(temporary,'short','short','12–128'),(temporary,'Acceptable2026!','Mismatch2026!','do not match'),(temporary,temporary,temporary,'different from the current')]:
  token=csrf(staff.get(B+P)); r=staff.post(B+P,data={'csrf':token,'current':current,'new':new,'new2':confirm})
  ck('Rejected change: '+message,r.status_code==400 and message in r.text)
  ck('Password errors do not echo secrets',temporary not in r.text and 'value="Acceptable2026!"' not in r.text)
 # Stale recovery tokens must not survive a successful account password change.
 php("gq('UPDATE users SET reset_token=?,reset_expires=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?',['synthetic-token',$x['id']]);echo 'true';",{'id':uid})
 new='DifferentAa1'+'z'*115+'!'; ck('Replacement is also 128 characters',len(new)==128)
 token=csrf(staff.get(B+P)); r=staff.post(B+P,data={'csrf':token,'current':temporary,'new':new,'new2':new},allow_redirects=False)
 ck('Success redirects to shared sign-in confirmation',r.status_code==302 and r.headers.get('Location','').endswith('/auth/login.php?pw=1'))
 state=php("$a=gone('SELECT must_change_password,reset_token,reset_expires FROM users WHERE id=?',[$x['id']]);$a['sessions']=(int)gq('SELECT COUNT(*) FROM gd_sessions WHERE user_id=?',[$x['id']])->fetchColumn();echo json_encode($a);",{'id':uid})
 ck('Temporary-password flag removed',state['must_change_password']==0)
 ck('Recovery token and expiry invalidated',state['reset_token'] is None and state['reset_expires'] is None)
 ck('All registered account sessions removed',state['sessions']==0)
 ck('Other device cookie revoked',other.get(B+'/guidance/index.php',allow_redirects=False).status_code==302)
 ck('Current cookie revoked',staff.get(B+'/guidance/index.php',allow_redirects=False).status_code==302)
 good=login(name,new); ck('Updated password opens Guidance',good.get(B+'/guidance/index.php',allow_redirects=False).status_code==200)
 invalid=requests.Session(); token=csrf(invalid.get(B+'/auth/login.php')); r=invalid.post(B+'/auth/login.php',data={'csrf':token,'ident':name,'password':new[:-1]+'?'},allow_redirects=False)
 ck('Shared login rejects changed character 128',r.status_code!=302)
 audit=php("echo json_encode(gall('SELECT action,description FROM audit_logs WHERE user_id=?',[$x['id']]));",{'id':uid})
 ck('Password change audited',any('PASSWORD_CHANGED' in x['action'] for x in audit))
 ck('Audit contains no passwords',temporary not in str(audit) and new not in str(audit))
 # A dedicated fresh account avoids altering a fixture user's password or exhausting their rate bucket.
 name2='rate_'+uuid.uuid4().hex[:8]; ratepw='RateReview2026!'
 d=api(head,{**base,'username':name2,'email':name2+'@example.invalid','password':ratepw,'password_confirm':ratepw}).json(); rs=login(name2,ratepw)
 for i in range(11):
  token=csrf(rs.get(B+P)); response=rs.post(B+P,data={'csrf':token,'current':'incorrect','new':'Replacement2026!','new2':'Replacement2026!'})
 ck('Password-change attempts rate limited',response.status_code==429 and '15 minutes' in response.text)
 ck('Rate limiting did not change password',php("echo json_encode(password_verify($x['pw'],gone('SELECT password FROM users WHERE id=?',[$x['id']])['password']));",{'id':d['id'],'pw':ratepw}))
finally:
 out=ROOT/'guidance/tests/output';out.mkdir(exist_ok=True);(out/'password-http.json').write_text(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks},indent=2));print(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks)}))
