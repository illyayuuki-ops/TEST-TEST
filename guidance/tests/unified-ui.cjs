// Local synthetic UI/security regression. Does not alter real accounts or passwords.
const puppeteer=require(process.env.PUPPETEER_MODULE||'/home/user/guidance-v2-tools/node_modules/puppeteer');
const fs=require('fs'),path=require('path'),B=process.env.GUIDANCE_BASE||'http://127.0.0.1:8081';if(!['127.0.0.1','localhost','[::1]'].includes(new URL(B).hostname))throw Error('Local synthetic fixture only');
(async()=>{const browser=await puppeteer.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const checks=[],errors=[],out=path.join(__dirname,'output');const ck=(name,ok)=>{checks.push({name,passed:!!ok});if(!ok)throw Error(name)};
async function login(user){const c=await browser.createBrowserContext(),p=await c.newPage();await p.setRequestInterception(true);p.on('request',r=>r.url().startsWith(B)?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(B+'/auth/login.php');await p.type('[name=ident]',user);await p.type('[name=password]','GuidanceDemo2026!');await Promise.all([p.waitForNavigation(),p.click('button[type=submit]')]);return p;}
async function go(p,url){const r=await p.goto(B+url);ck('Page loads '+url,r.status()===200);await p.evaluate(async()=>await Promise.all(document.getAnimations().filter(a=>a.effect.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{}))));}
const navCheck=async(p,label)=>{ck(label+' no Settings sidebar entry',await p.$$eval('#sidebar nav a',es=>es.every(e=>e.textContent.trim()!=='Settings'&&!e.href.includes('view=settings')&&!e.href.endsWith('/student/settings.php'))));ck(label+' no Profile or Password sidebar links',await p.$$eval('#sidebar nav a',es=>es.every(e=>!e.href.includes('student/profile.php')&&!e.href.includes('change_password.php'))));};
try {
 const student=await login('student_one'),head=await login('guide_head'),counselor=await login('guide_counselor'),ops=await login('guide_admin');
 for(const [p,role,label] of [[student,'student','Student'],[head,'head','Guidance Head'],[counselor,'counselor','Counselor'],[ops,'administrator','Guidance Administrator']]){
  for(const w of [1366,390,320]){
   await p.setViewport({width:w,height:900});await go(p,'/guidance/index.php');await navCheck(p,role+' '+w);
   ck(role+' native dashboard structure '+w,await p.evaluate(()=>!!document.querySelector('.hero')&&document.querySelectorAll('.stats .stat').length===4&&!!document.querySelector('.dash > aside .upd-list')));
   ck(role+' no horizontal page overflow '+w,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   ck(role+' header profile menu '+w,(await p.$('#tbUser'))!==null);
   ck(role+' correct role label '+w,await p.$eval('#tbBtn small',(e,label)=>e.textContent===label,label));
   await p.click('#tbBtn');ck(role+' account menu opens '+w,await p.$eval('#tbBtn',e=>e.getAttribute('aria-expanded')==='true'));
   ck(role+' account menu directs to Settings only '+w,await p.$$eval('#tbMenu a[role=menuitem]',es=>es.length===2&&es.some(e=>e.textContent.trim()==='Settings')&&es.some(e=>e.href.endsWith('/auth/logout.php'))));
   await p.keyboard.press('Escape');
   if(w!==320)await p.screenshot({path:path.join(out,'unified-'+role+'-'+w+'.png'),fullPage:false});
  }
  await p.click('#tbBtn');await Promise.all([p.waitForNavigation(),p.click('#tbMenu a[role=menuitem][href*=settings]')]);ck(role+' Settings reachable through header',p.url().endsWith('/guidance/index.php?view=settings'));
  await go(p,'/guidance/index.php?view=settings');await navCheck(p,role+' settings');
  ck(role+' password accessible inside Settings',await p.$('#guidance a[href*="change_password"]')!==null);
  ck(role+' no duplicate staff account in sidebar',(await p.$('.sb-user'))===null);
 }
 await go(student,'/student/dashboard.php');await navCheck(student,'Shared student dashboard');await student.click('#tbBtn');await Promise.all([student.waitForNavigation(),student.click('#tbMenu a[role=menuitem][href*=settings]')]);ck('Shared student Settings reachable through header',student.url().endsWith('/student/settings.php'));
 await go(student,'/student/settings.php');ck('Shared Settings includes existing profile editor',await student.$('a[href$="student/profile.php"]')!==null);ck('Shared Settings includes native password route',await student.$('a[href$="auth/change_password.php"]')!==null);
 for(const url of ['/student/profile.php','/auth/change_password.php']){await go(student,url);ck('Shared account subpage has no sidebar selection '+url,await student.$$eval('#sidebar nav a.active',es=>es.length===0));}
 for(const p of [student,head,counselor,ops]){await go(p,'/auth/change_password.php?from=guidance');ck('Guidance password has no sidebar selection',await p.$$eval('#sidebar nav a.active',es=>es.length===0));}
 await go(head,'/guidance/index.php?view=settings');ck('Head has Team and Services inside Settings',await head.evaluate(()=>!!document.querySelector('#guidance a[href$="view=team"]')&&!!document.querySelector('#guidance a[href$="view=categories"]')));ck('Head has no Team/Services sidebar duplication',await head.$$eval('#sidebar nav a',es=>es.every(e=>!e.href.endsWith('view=team')&&!e.href.endsWith('view=categories'))));
 for(const view of ['team','categories']){await go(head,'/guidance/index.php?view='+view);ck(view+' has no sidebar selection',await head.$$eval('#sidebar nav a.active',es=>es.length===0));}
 for(const p of [student,counselor,ops]){ck('Non-head cannot access Team',(await p.goto(B+'/guidance/index.php?view=team')).status()===403);}
 await go(ops,'/guidance/index.php');ck('Operational dashboard uses activities, not clinical cases',await ops.evaluate(()=>!!document.querySelector('[data-overview-list]')&&document.querySelector('[data-overview-list] h3').textContent==='Activity workspace'&&!document.querySelector('[data-overview-list] a[href*="ticket.php"]')));
 await go(student,'/guidance/index.php');const total=await student.$$('[data-overview-item]');ck('Fixture has authorized request cards',total.length>0);await student.type('[data-overview-query]','no-match-'+Date.now());ck('Search hides nonmatching cards',await student.$$eval('[data-overview-item]',es=>es.every(e=>e.hidden)));ck('No matches next step visible',await student.$eval('[data-overview-empty]',e=>!e.hidden&&getComputedStyle(e).display!=='none'));await student.click('[data-overview-clear]');ck('Clear restores visible cards',await student.$$eval('[data-overview-item]',es=>es.every(e=>!e.hidden)));
 const sao=await login('sao_demo');await go(sao,'/admin/dashboard.php');ck('Other-office header behavior unchanged',(await sao.$('#tbUser'))===null);ck('Other-office password sidebar preserved',await sao.$('#sidebar nav a[href$="auth/change_password.php"]')!==null);await sao.goto(B+'/student/settings.php');ck('Other office is redirected out of student Settings',sao.url().endsWith('/admin/dashboard.php'));
 ck('View partial direct access denied',(await sao.goto(B+'/guidance/overview.php')).status()===404);
 ck('No JavaScript exceptions',errors.length===0);
}finally{fs.writeFileSync(path.join(out,'unified-ui.json'),JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors,checks},null,2));console.log(JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors}));await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
