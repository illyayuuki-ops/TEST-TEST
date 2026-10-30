"""Local synthetic regression. Creates test records. Never use against real records."""
import os,re,json,uuid,datetime,pathlib,requests
from urllib.parse import urlparse
from bs4 import BeautifulSoup
B=os.environ.get('GUIDANCE_BASE','http://127.0.0.1:8081');assert urlparse(B).hostname in ['localhost','127.0.0.1','::1'];checks=[]
def check(n,c):
 checks.append({'name':n,'passed':bool(c)})
 if not c:raise AssertionError(n)
def csrf(r):
 m=re.search(r'name="csrf" value="([^"]+)"',r.text)
 if not m:m=re.search(r'data-csrf="([^"]+)"',r.text)
 if not m:raise RuntimeError('No CSRF token: '+r.url+' '+str(r.status_code))
 return m.group(1)
def login(name,pw='GuidanceDemo2026!'):
 s=requests.Session();r=s.get(B+'/auth/login.php');r=s.post(B+'/auth/login.php',data={'ident':name,'password':pw,'csrf':csrf(r)},allow_redirects=False);check('shared login '+name,r.status_code==302);return s
def page(s,path,status=200):
 r=s.get(B+'/'+path);check('page '+path+' status '+str(status),r.status_code==status);return r
def post(s,action,data={},status=200):
 r=s.get(B+'/guidance/index.php');p=dict(data,action=action,csrf=csrf(r));r=s.post(B+'/guidance/action.php',data=p);check('action '+action+' expected '+str(status)+' actual '+str(r.status_code)+(r.text[:160] if r.status_code!=status else ''),r.status_code==status);return r.json()
def native(s,path,data,expected=302,files=None):
 token=csrf(s.get(B+'/'+path));r=s.post(B+'/'+path,data=dict(data,csrf=token),files=files,allow_redirects=False);check('shared form '+path+' '+str(expected),r.status_code==expected);return r
def categories(s,office):
 r=s.get(B+'/student/new_ticket.php?office='+office);m=re.search(r'const CATS = (.*);',r.text);return json.loads(m.group(1))[office]
def ticket(s,office,subject,details='Synthetic request details only.',files=None):
 c=categories(s,office)[0][0];r=native(s,'student/new_ticket.php',{'office_code':office,'category':c,'priority':'Normal','subject':subject,'details':details},files=files);return int(re.search(r'id=(\d+)',r.headers['Location']).group(1))
def own(s):return s.get(B+'/guidance/export.php').json()
def when(minutes):return (datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8)))+datetime.timedelta(minutes=minutes)).strftime('%Y-%m-%dT%H:%M')
try:
 anon=requests.Session();check('anonymous login required',anon.get(B+'/guidance/index.php',allow_redirects=False).status_code==302)
 for path in ['config/app.php','config/local.php','database/ticketdesk.sql','.git/HEAD','guidance/domain.php','guidance/tests/setup.php','uploads/ticket_files/anything.pdf']:
  check('private path blocked '+path,anon.get(B+'/'+path).status_code==404)
 a=login('student_one');b=login('student_two');head=login('guide_head');c=login('guide_counselor');other=login('guide_other');admin=login('guide_admin');sao=login('sao_demo')
 check('server explicitly synthetic','Synthetic local review database' in page(a,'guidance/index.php').text)
 ids={k:own(s)['user']['id'] for k,s in [('student',a),('other',b),('head',head),('counselor',c),('other_counselor',other),('admin',admin)]}
 page(sao,'admin/dashboard.php');page(sao,'guidance/index.php',403)
 check('Guidance admin legacy report path uses Guidance reports','Aggregate reports' in page(admin,'admin/reports.php').text)
 for v in ['team','categories','privacy','audit']:page(c,'guidance/index.php?view='+v,403)
 page(a,'guidance/index.php?view=reports',403)
 token=csrf(a.get(B+'/guidance/index.php'));r=a.post(B+'/guidance/action.php',data={'action':'preferences','csrf':'bad'});check('CSRF enforced',r.status_code==419)
 label='Connected case '+uuid.uuid4().hex[:8];tid=ticket(a,'guide',label,files={'files[]':('evidence.pdf',b'%PDF-1.4\nSynthetic evidence only.','application/pdf')})
 check('same ticket visible in native student dashboard',label in page(a,'student/dashboard.php').text)
 check('same ticket visible in Guidance workspace',label in page(a,'guidance/index.php?view=requests').text)
 page(head,'ticket.php?id='+str(tid))
 for s in [c,other,admin]:page(s,'ticket.php?id='+str(tid),404)
 for s in [b,sao]:check('other user/office native ticket denied',label not in s.get(B+'/ticket.php?id='+str(tid)).text)
 r=head.get(B+'/ticket.php?id='+str(tid));fileid=int(re.search(r'file.php\?id=(\d+)',r.text).group(1))
 check('shared attachment downloads for owner',a.get(B+'/file.php?id='+str(fileid)).content.startswith(b'%PDF'))
 for s in [b,c,admin,sao]:page(s,'file.php?id='+str(fileid),404)
 native(head,'ticket.php?id='+str(tid),{'action':'assign','assigned_to':ids['counselor']})
 page(c,'ticket.php?id='+str(tid));page(c,'file.php?id='+str(fileid));page(other,'ticket.php?id='+str(tid),404)
 native(c,'ticket.php?id='+str(tid),{'action':'assign','assigned_to':ids['other_counselor']},403)
 native(c,'ticket.php?id='+str(tid),{'action':'reply','message':'INTERNAL-ONLY-PRIVATE-NOTE','internal':'1'},files={'files[]':('internal.pdf',b'%PDF-1.4\nInternal only.','application/pdf')})
 full=c.get(B+'/ticket.php?id='+str(tid));internal_ids=re.findall(r'file.php\?id=(\d+)',full.text);internalid=int(internal_ids[-1]);page(a,'file.php?id='+str(internalid),404)
 check('internal notes excluded from requester','INTERNAL-ONLY-PRIVATE-NOTE' not in page(a,'ticket.php?id='+str(tid)).text)
 check('internal note hidden from personal export','INTERNAL-ONLY-PRIVATE-NOTE' not in json.dumps(own(a)))
 for s in [admin,other,sao]:check('private ticket live endpoint reveals no signature',s.get(B+'/live.php?t='+str(tid)).json()['sig']=='')
 native(a,'ticket.php?id='+str(tid),{'action':'reply','message':'<script>alert("escaped")</script> follow-up'})
 check('native text escaped','&lt;script&gt;' in page(c,'ticket.php?id='+str(tid)).text)
 native(c,'ticket.php?id='+str(tid),{'action':'status','status':'Closed'});check('invalid status transition refused',next(t for t in own(a)['tickets'] if t['id']==tid)['status']!='Closed')
 native(c,'ticket.php?id='+str(tid),{'action':'status','status':'Resolved'});post(a,'feedback',{'id':tid,'rating':5,'feedback':'Synthetic good service'})
 native(a,'ticket.php?id='+str(tid),{'action':'confirm_resolved'});check('native close shared with Guidance',next(t for t in own(a)['tickets'] if t['id']==tid)['status']=='Closed')
 native(a,'ticket.php?id='+str(tid),{'action':'reopen'});check('native reopen shared with Guidance',next(t for t in own(a)['tickets'] if t['id']==tid)['status']=='Reopened')
 walk=post(head,'walkin',{'user_id':ids['other'],'category_id':categories(a,'guide')[0][0],'subject':'Synthetic walk-in owner check','details':'Synthetic initial walk-in details.','assigned_to':ids['counselor']})['id'];check('walk-in owner is selected student',any(t['id']==walk for t in own(b)['tickets']))
 # Student Affairs remains operational and isolated from Guidance.
 sid=ticket(a,'sao','Student Affairs coexistence '+uuid.uuid4().hex[:6]);page(sao,'ticket.php?id='+str(sid));native(sao,'ticket.php?id='+str(sid),{'action':'reply','message':'Student Affairs still works.'});check('Guidance cannot view SAO case','Student Affairs still works.' not in head.get(B+'/ticket.php?id='+str(sid)).text)
 for v in ['admin/reports.php','admin/records.php','admin/categories.php','admin/team.php','admin/audit.php']:page(sao,v)
 ap=post(a,'appointment.create',{'counselor_id':ids['counselor'],'starts_at':when(1440),'ends_at':when(1470),'concern':'Synthetic appointment concern.'})['id'];post(b,'appointment.create',{'counselor_id':ids['counselor'],'starts_at':when(1440),'ends_at':when(1470),'concern':'Conflict test concern.'},409)
 post(a,'appointment.update',{'id':ap,'status':'confirmed'},403);post(other,'appointment.update',{'id':ap,'status':'confirmed'},404);post(c,'appointment.update',{'id':ap,'status':'confirmed'});post(c,'appointment.update',{'id':ap,'status':'completed'},409);post(a,'appointment.update',{'id':ap,'status':'cancelled'})
 program=post(head,'program.save',{'title':'Wellbeing series','description':'Synthetic recurring program','active':1})['id']
 ev={'title':'Connected workshop '+uuid.uuid4().hex[:6],'description':'A synthetic workshop for integration checks.','kind':'workshop','status':'published','starts_at':when(-30),'ends_at':when(20),'venue':'Review room','capacity':1,'program_id':program,'session_no':1,'certificates_enabled':1,'credit_units':'2'}
 eid=post(head,'event.save',ev)['id'];post(head,'invite',{'id':eid,'user_ids[]':[ids['student'],ids['other']]});post(head,'invite',{'id':eid,'user_ids[]':[ids['student']]});post(a,'rsvp',{'id':eid,'state':'registered'});post(b,'rsvp',{'id':eid,'state':'registered'},409)
 html=head.get(B+'/guidance/index.php?view=event&id='+str(eid));soup=BeautifulSoup(html.text,'html.parser');code=soup.select_one('code.gd-code').text
 check('venue code hidden from requester before checkin',code not in a.get(B+'/guidance/index.php?view=event&id='+str(eid)).text)
 post(a,'checkin',{'id':eid,'code':'BAD'},400);post(a,'checkin',{'id':eid,'code':code});post(a,'rsvp',{'id':eid,'state':'declined'},409)
 invite_id=soup.select_one('form input[value="attendance"]').parent.select_one('select[name=id] option')['value']
 post(a,'attendance',{'id':invite_id,'attendance':'late'},403);post(head,'attendance',{'id':invite_id,'attendance':'late'})
 post(head,'certificates',{'id':eid},409);post(head,'event.save',dict(ev,id=eid,status='completed'),409)
 ev['ends_at']=when(-1);ev['status']='completed';post(head,'event.save',dict(ev,id=eid));post(head,'certificates',{'id':eid});post(head,'certificates',{'id':eid})
 cert=next(x for x in own(a)['certificates'] if x['event_id']==eid);page(a,'guidance/certificate.php?id='+str(cert['id']));page(b,'guidance/certificate.php?id='+str(cert['id']),404)
 post(head,'event.save',dict(ev,id=eid,title='Tampered issued title'),409);post(head,'attendance',{'id':invite_id,'attendance':'absent'},409)
 post(head,'article.save',{'title':'Published Guidance resource','body':'A helpful article for the synthetic test.','published':1});post(head,'article.save',{'title':'HIDDEN-DRAFT-RESOURCE','body':'Draft resource not intended for students.'});check('draft hidden','HIDDEN-DRAFT-RESOURCE' not in page(a,'guidance/index.php?view=knowledge').text)
 report=page(admin,'guidance/export.php?type=reports');check('report has no case subject or student identity',label not in report.text and 'Demo Student' not in report.text and 'text/csv' in report.headers['Content-Type']);page(a,'guidance/export.php?type=reports',403)
 privacy=post(a,'privacy.submit',{'reason':'Please review my synthetic Guidance data retention.'})['id'];post(head,'privacy.review',{'id':privacy,'response':'Reviewed. No automatic deletion was performed.'});check('review visible to requester','No automatic deletion' in page(a,'guidance/index.php?view=settings').text)
 post(a,'preferences',{});before=a.get(B+'/notif.php').json()['last'];native(c,'ticket.php?id='+str(tid),{'action':'reply','message':'Preference suppression test.'});check('ticket update preference honored',a.get(B+'/notif.php').json()['last']==before)
 post(head,'staff.save',{'user_id':ids['head'],'guidance_role':'administrator','active':0},409)
 post(head,'staff.save',{'user_id':ids['counselor'],'guidance_role':'administrator','active':1},409)
 newname='review_'+uuid.uuid4().hex[:8];post(head,'staff.create',{'username':newname,'full_name':'Synthetic new counselor','email':newname+'@example.invalid','password':'TemporaryTest2026!','password_confirm':'TemporaryTest2026!','guidance_role':'counselor'})
 fresh=login(newname,'TemporaryTest2026!');check('new staff forced password change','Change password' in fresh.get(B+'/guidance/index.php').text)
 native(fresh,'auth/change_password.php',{'current':'TemporaryTest2026!','new':'UpdatedTest2026!','new2':'UpdatedTest2026!'})
 fresh=login(newname,'UpdatedTest2026!');page(fresh,'guidance/index.php')
 copy=requests.Session();copy.cookies.update(a.cookies);post(a,'sessions');check('all-session revocation includes old cookie',copy.get(B+'/guidance/state.php').status_code==401)
 a=login('student_one');copy=requests.Session();copy.cookies.update(a.cookies);a.get(B+'/auth/logout.php');check('logout revokes cookie replay',copy.get(B+'/guidance/state.php').status_code==401)
 missing='missing_'+uuid.uuid4().hex[:10];guess=requests.Session();token=csrf(guess.get(B+'/auth/login.php'))
 for i in range(11):r=guess.post(B+'/auth/login.php',data={'ident':missing,'password':'bad','csrf':token})
 check('unknown-account persistent rate limit',r.status_code==429)
finally:
 out=pathlib.Path(__file__).parent/'output';out.mkdir(exist_ok=True);(out/'http.json').write_text(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks},indent=2));print(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks)},indent=2))
