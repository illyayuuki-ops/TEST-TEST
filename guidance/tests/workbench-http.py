"""Run only against the isolated synthetic test server after workbench.php."""
import os,json,re,pathlib,csv,io,requests
from urllib.parse import urlparse
from bs4 import BeautifulSoup
B=os.environ.get('GUIDANCE_BASE','http://127.0.0.1:8081')
assert os.environ.get('LCCT_SYNTHETIC_TEST')=='1' and urlparse(B).hostname in ['127.0.0.1','localhost','::1']
O=pathlib.Path(__file__).parent/'output';f=json.loads((O/'workbench-fixture.json').read_text());checks=[]
def ck(n,v):
 checks.append({'name':n,'passed':bool(v)})
 if not v:raise AssertionError(n)
def login(n):
 s=requests.Session();r=s.get(B+'/auth/login.php');token=re.search(r'name="csrf" value="([^"]+)"',r.text).group(1)
 r=s.post(B+'/auth/login.php',data={'csrf':token,'ident':n,'password':'GuidanceDemo2026!'},allow_redirects=False);ck('login '+n,r.status_code==302);return s
def get(s,route='index.php',params=None,status=200):
 r=s.get(B+'/guidance/'+route,params=params);ck(r.url+' expected '+str(status),r.status_code==status);ck('No PHP diagnostics in '+r.url,not re.search(r'(Fatal error:|Warning:|Stack trace:)',r.text));return r
def body(r):
 d=BeautifulSoup(r.text,'html.parser');g=d.select_one('#guidance');return g.get_text(' ',strip=True) if g else r.text
try:
 anon=requests.Session();r=anon.get(B+'/guidance/workbench-export.php',allow_redirects=False);ck('Export requires shared login',r.status_code==302)
 for path in ['workbench.php','workbench-view.php']:get(anon,path,status=404)
 h=login('guide_head');c=login('guide_counselor');a=login('guide_admin');s=login('student_one');o=login('guide_other');sao=login('sao_demo')
 for actor in [a,s,sao]:get(actor,params={'view':'students'},status=403)
 get(c,params={'view':'students','id':f['unlinked']},status=404)
 r=get(h,params={'view':'students','id':f['unlinked']});ck('Head unlinked student detail','Unlinked '+f['tag'] in body(r))
 r=get(c,params={'view':'students','id':f['student_two']});ck('Appointment link does not reveal other counselor case','OTHER-COUNSELOR-'+f['tag'] not in body(r));ck('Appointment link does not reveal other counselor appointment','HIDDEN-APPOINTMENT-'+f['tag'] not in body(r))
 r=get(c,params={'view':'students','q':f['tag']});ck('Counselor cannot discover unrelated student','Unlinked '+f['tag'] not in body(r))
 for actor,n in [(h,36),(c,30),(o,6),(s,36)]:
  p={'view':'requests','category':f['category'],'from':'2024-01-15','to':'2024-01-15'}
  r=get(actor,params=p);ck('Scoped result total '+str(n),str(n)+' results' in body(r));d=BeautifulSoup(r.text,'html.parser');ck('Server pagination limits rows',len(d.select('#guidance table tbody tr'))==min(n,25))
  r=get(actor,params=p|{'layout':'board'});ck('Read-only board columns',len(BeautifulSoup(r.text,'html.parser').select('.gw-column'))==8);ck('Board page limit',len(BeautifulSoup(r.text,'html.parser').select('.gw-ticket'))==min(n,25))
 r=get(c,params={'view':'requests','category':f['category'],'page':2});ck('Next page distinct requests',len(BeautifulSoup(r.text,'html.parser').select('#guidance table tbody tr'))==5)
 r=get(c,params={'view':'requests','category':f['category'],'assigned':'person','counselor':f['other']});ck('Counselor filter never widens scope','0 results' in body(r))
 for actor in [a,s]:get(actor,'workbench-export.php',{'type':'cases'},403)
 r=get(c,'workbench-export.php',{'type':'cases','category':f['category']});rows=list(csv.reader(io.StringIO(r.text)));ck('Authorized CSV includes all pages',len(rows)==31);ck('Download no-store','no-store' in r.headers.get('Cache-Control',''));ck('CSV download attachment','attachment' in r.headers.get('Content-Disposition',''))
 for secret in ['private subject','NEVER-EXPORT','Demo Student','OTHER-COUNSELOR']:ck('Queue export excludes '+secret,secret not in r.text)
 for actor in [h,c,a]:
  p={'view':'reports','category':f['category'],'from':'2024-01-15','to':'2024-01-15'}
  r=get(actor,params=p);ck('Suppressed chart cells','Suppressed (<5)' in body(r));ck('Intake cohort explained','intake cohort' in body(r))
  for secret in ['private subject','NEVER-EXPORT','Demo Student One','Demo Student Two']:ck('Report HTML excludes '+secret,secret not in r.text)
  r=get(actor,'workbench-export.php',{'type':'reports','category':f['category'],'from':'2024-01-15','to':'2024-01-15'});ck('CSV suppressed literal','Suppressed (<5)' in r.text);ck('Aggregate correct count',['Overview','Requests in intake cohort','36'] in list(csv.reader(io.StringIO(r.text))))
  for secret in ['private subject','NEVER-EXPORT','Demo Student']:ck('Report CSV excludes '+secret,secret not in r.text)
 get(s,'workbench-export.php',{'type':'reports'},403)
 get(h,'export.php',{'type':'reports'})
 for params in [{'from':'2026-02-30'},{'from':'2026-10-30','to':'2026-01-01'},{'category[]':'1'},{'q':'x'*121},{'layout':'bad'},{'status':'bad'}]:get(h,params={'view':'requests'}|params,status=400)
 for params in [{'from':'bad'},{'counselor[]':'1'},{'category':'-2'}]:
  get(h,params={'view':'reports'}|params,status=400);get(h,'workbench-export.php',{'type':'reports'}|params,400)
 get(h,'workbench-export.php',{'type':'missing'},400)
 r=get(h,params={'view':'requests','q':'<script>alert(1)</script>'});ck('Search is HTML escaped','<script>alert(1)</script>' not in r.text)
 r=sao.get(B+'/admin/dashboard.php');ck('Other department remains native',r.status_code==200 and 'workbench.css' not in r.text)
finally:
 result={'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks};(O/'workbench-http.json').write_text(json.dumps(result,indent=2));print(json.dumps({k:result[k] for k in ['passed','total']}))
