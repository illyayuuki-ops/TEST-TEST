import os,json,re,pathlib,csv,io,uuid,requests
from urllib.parse import urlparse
from bs4 import BeautifulSoup
B=os.environ.get('GUIDANCE_BASE','http://127.0.0.1:8082');assert os.environ.get('LCCT_SYNTHETIC_TEST')=='1' and urlparse(B).hostname in ['127.0.0.1','localhost','::1']
O=pathlib.Path(__file__).parent/'output';f=json.loads((O/'restoration-fixture.json').read_text());checks=[]
def ck(n,v):
 checks.append({'name':n,'passed':bool(v)})
 if not v:raise AssertionError(n)
def token(s):
 r=s.get(B+'/guidance/index.php');return re.search(r'data-csrf="([^"]+)"',r.text).group(1)
def login(name):
 s=requests.Session();r=s.get(B+'/auth/login.php');t=re.search(r'name="csrf" value="([^"]+)"',r.text).group(1);r=s.post(B+'/auth/login.php',data={'csrf':t,'ident':name,'password':'GuidanceDemo2026!'},allow_redirects=False);ck('Login '+name,r.status_code==302);return s
def get(s,view,extra={},status=200):
 r=s.get(B+'/guidance/index.php',params={'view':view}|extra);ck(view+' HTTP '+str(status),r.status_code==status);ck(view+' no PHP diagnostics',not re.search('Fatal error:|Warning:|Stack trace:',r.text));return r
def act(s,a,data={},status=200,files=None):
 r=s.post(B+'/guidance/action.php',data={'action':a,'csrf':token(s)}|data,files=files);ck(a+' expected '+str(status)+' actual '+str(r.status_code),r.status_code==status);return r
try:
 h=login('guide_head');c=login('guide_counselor');o=login('guide_other');s=login('student_one');s2=login('student_two');a=login('guide_admin');sao=login('sao_demo')
 for actor in [h,c,a,s]:
  for view in ['notifications','help','knowledge']:get(actor,view)
 for actor in [h,c,a]:get(actor,'register')
 for actor in [s,s2]:get(actor,'register',status=403);get(actor,'cohort',{'id':f['event']},403)
 for actor in [s,c,a]:get(actor,'operations',status=403)
 get(h,'operations');get(a,'intake',status=403);get(a,'review',status=403);get(c,'review',{'id':f['case']});get(o,'review',{'id':f['case']},404);get(s2,'review',{'id':f['case']},404)
 r=get(s,'review',{'id':f['case']});ck('Student does not see private staff review reason','PRIVATE-STAFF-REVIEW' not in r.text)
 r=get(h,'cohort',{'id':f['event'],'program':f['tag'],'year_level':'2','section':'A'});ck('Cohort preview has confirmation fingerprint','audience_hash' in r.text)
 r=get(h,'students',{'program':f['tag'],'section':'NO_MATCH'});ck('Academic directory filter works','0 results' in r.text)
 get(h,'students',{'id':f['student']});get(h,'categories');get(h,'team');get(h,'privacy');get(s,'settings')
 for view in ['notifications','help','register','cohort','review','operations']:get(sao,view,{'id':f['case']},403)
 for view in ['restoration.php','restoration-view.php','restoration-context.php','restoration-migrate.php','restoration-ops.php']:
  r=requests.get(B+'/guidance/'+view);ck('Private/CLI helper blocked '+view,r.status_code==404)
 for actor in [s,c,a,sao]:ck('Audit export denied',actor.get(B+'/guidance/restoration-export.php').status_code==403)
 r=h.get(B+'/guidance/restoration-export.php',params={'q':'x.case.review'});ck('Audit export succeeds',r.status_code==200);rows=list(csv.reader(io.StringIO(r.text)));ck('Audit has three safe columns',rows[0]==['When','Actor','Action'] and all(len(row)==3 for row in rows));ck('Audit excludes clinical/IP text','PRIVATE-' not in r.text and 'ip_address' not in r.text)
 all_audit=h.get(B+'/guidance/restoration-export.php');ck('Audit export redacts requester identities','student_one' not in all_audit.text and 'student_two' not in all_audit.text);ck('Audit export labels redacted actors','identity withheld' in all_audit.text)
 ck('Audit no-store','no-store' in r.headers.get('Cache-Control',''))
 for params in [{'q[]':'bad'},{'from':'2026-02-30'},{'actor':'a'*121}]:ck('Invalid audit filter rejected',h.get(B+'/guidance/restoration-export.php',params=params).status_code==400)
 r=s.get(B+'/notif.php',params={'go':f['notice']},allow_redirects=False);ck('Native bell deep-links to authorized Guidance workspace',r.status_code==302 and 'guidance/index.php?view=settings' in r.headers.get('Location',''))
 r=s2.get(B+'/notif.php',params={'go':f['notice']},allow_redirects=False);ck('Another user cannot open that deep link','guidance/index.php?view=settings' not in r.headers.get('Location',''))
 bad=s.post(B+'/guidance/action.php',data={'action':'x.intake','csrf':'bad'});ck('New write actions protected by CSRF',bad.status_code==419)
 subject='Restoration HTTP '+uuid.uuid4().hex[:8];payload={'category_id':f['category'],'subject':subject,'details':'PRIVATE-HTTP-INTAKE synthetic details','priority':'High'}
 r=act(s,'x.intake',payload,files={'files[]':('fixture.pdf',b'%PDF-1.4\nSynthetic test only\n%%EOF','application/pdf')});j=r.json();case=j['id'];ck('Upload paths never returned','_attachments' not in j and '/tmp/' not in r.text)
 r=s.get(B+'/ticket.php',params={'id':case});ck('Initial intake attachment visible','fixture.pdf' in r.text);ck('Native request explains Guidance priority','Guidance priority: High' in r.text)
 file=BeautifulSoup(r.text,'html.parser').select_one('a[href*="file.php?id="]');ck('Attachment secure route exists',file is not None);url=file['href'];url=url if url.startswith('http') else B+'/'+url.lstrip('/');ck('Owner attachment readable',s.get(url).status_code==200);ck('Other student attachment denied',s2.get(url).status_code==404)
 act(a,'x.intake',payload,status=403);act(s,'x.intake',payload|{'subject':subject+' bad file'},400,{'files[]':('evil.php',b'<?php echo 1;','application/x-php')})
 # Native cancellation now requires a reason and uses the same scoped transaction.
 tk=re.search(r'name="csrf" value="([^"]+)"',r.text).group(1)
 r=s.post(B+'/ticket.php?id='+str(case),data={'csrf':tk,'action':'cancel'},allow_redirects=False);ck('Missing cancellation reason returns safely',r.status_code==302)
 r=get(s,'review',{'id':case});ck('Missing reason does not cancel','Shared status: Open' in BeautifulSoup(r.text,'html.parser').get_text())
 act(s,'x.case.transition',{'id':case,'status':'Cancelled','reason':'Synthetic cancellation reason'})
 r=get(s,'review',{'id':case});ck('Valid cancellation persisted','Shared status: Cancelled' in BeautifulSoup(r.text,'html.parser').get_text())
 act(c,'x.staff.edit',{'id':f['staff'],'full_name':'Bad','email':'bad@example.invalid'},403)
 act(h,'x.staff.reset',{'id':f['staff'],'current_password':'WRONG','password':'PasswordNew2026!','password_confirm':'PasswordNew2026!'},403)
 act(s2,'x.notice.read',{'id':f['notice']},404)
 for params in [{'q[]':'bad'},{'view[]':'help'},{'page':'-1'},{'from':'2026-02-30'}]:
  r=h.get(B+'/guidance/index.php',params={'view':'help'}|params);ck('Malformed GET safely rejected',r.status_code==400 and 'Fatal error' not in r.text)
 r=get(h,'help',{'q':'<script>alert(1)</script>'});ck('Catalogue search escapes HTML','<script>alert(1)</script>' not in r.text)
 art=act(h,'article.save',{'title':'Secret draft '+f['tag'],'body':'Private unpublished resource fixture','published':'0'}).json()['id']
 r=get(s,'knowledge',{'q':f['tag']});ck('Resource search cannot expose drafts','Secret draft' not in r.text)
 r=get(s2,'notifications',{'stage':'unread'});before_other=re.search(r'(\d+) results',BeautifulSoup(r.text,'html.parser').get_text()).group(1)
 act(s,'x.notices.readall')
 r=get(s,'notifications',{'stage':'unread'});ck('Mark all clears only own unread list','0 results' in BeautifulSoup(r.text,'html.parser').get_text())
 r=get(s2,'notifications',{'stage':'unread'});ck('Mark all preserves another user notices',re.search(r'(\d+) results',BeautifulSoup(r.text,'html.parser').get_text()).group(1)==before_other)
 r=sao.get(B+'/admin/dashboard.php');ck('Other office dashboard remains native',r.status_code==200 and 'restoration.js' not in r.text)
finally:
 result={'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks};(O/'restoration-http.json').write_text(json.dumps(result,indent=2));print(json.dumps({k:result[k] for k in ['passed','total']}))
