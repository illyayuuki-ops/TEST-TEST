// Collapsible sidebar checks on a local synthetic fixture. No data mutations.
const puppeteer=require(process.env.PUPPETEER_MODULE||'/home/user/guidance-v2-tools/node_modules/puppeteer');
const fs=require('fs'),path=require('path'),B=process.env.GUIDANCE_BASE||'http://127.0.0.1:8081';if(!['127.0.0.1','localhost','[::1]'].includes(new URL(B).hostname))throw Error('Local synthetic fixture only');
(async()=>{const browser=await puppeteer.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const checks=[],errors=[],out=path.join(__dirname,'output');const ck=(name,ok)=>{checks.push({name,passed:!!ok});if(!ok)throw Error(name)};
async function login(user){const c=await browser.createBrowserContext(),p=await c.newPage();await p.setRequestInterception(true);p.on('request',r=>r.url().startsWith(B)?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(B+'/auth/login.php');await p.type('[name=ident]',user);await p.type('[name=password]','GuidanceDemo2026!');await Promise.all([p.waitForNavigation(),p.click('button[type=submit]')]);return p;}
async function go(p,view='overview'){const r=await p.goto(B+'/guidance/index.php?view='+view);ck('Guidance route '+view,r.status()===200);await p.evaluate(async()=>await Promise.all(document.getAnimations().filter(a=>a.effect.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{}))));}
async function showNav(p){if(await p.$eval('.menu-btn',e=>getComputedStyle(e).display!=='none')){await p.click('.menu-btn');await p.waitForFunction(()=>document.querySelector('#sidebar').getBoundingClientRect().left>=-1);}}
try{
 const student=await login('student_one'),head=await login('guide_head'),counselor=await login('guide_counselor'),ops=await login('guide_admin');
 const roles=[[student,'student',['My requests','My appointments','Certificates & credits']],[head,'head',['Requests & assignments','All appointments','Privacy reviews','Guidance audit']],[counselor,'counselor',['Assigned requests','My appointments']],[ops,'administrator',['Activity operations']]];
 for(const [p,role,primary] of roles){
  for(const w of [1366,390,320]){
   await p.setViewport({width:w,height:900});await go(p);await showNav(p);
   ck(role+' has one shared group '+w,(await p.$$('#sidebar nav details.gd-nav-group')).length===1);
   ck(role+' group collapsed initially '+w,await p.$eval('.gd-nav-group',e=>!e.open));
   ck(role+' role-specific work outside group '+w,await p.$$eval('#sidebar nav > a',(es,labels)=>labels.every(label=>es.some(e=>e.textContent.trim()===label&&e.checkVisibility())),primary));
   ck(role+' shared links hidden when collapsed '+w,await p.$$eval('.gd-nav-group a',es=>es.every(e=>!e.checkVisibility())));
   ck(role+' no duplicate navigation URLs '+w,await p.$$eval('#sidebar nav a',es=>new Set(es.map(e=>e.href)).size===es.length));
   ck(role+' Settings stays out of sidebar '+w,await p.$$eval('#sidebar nav a',es=>es.every(e=>!e.href.includes('view=settings')&&!e.href.includes('student/settings.php')&&!e.href.includes('change_password.php'))));
   ck(role+' header Settings remains '+w,await p.$('#tbMenu a[href*="settings"]')!==null);
   ck(role+' no page or sidebar overflow '+w,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2&&document.querySelector('#sidebar nav').scrollWidth<=document.querySelector('#sidebar nav').clientWidth+2));
   if(w===1366)await(await p.$('#sidebar')).screenshot({path:path.join(out,'role-sidebar-'+role+'-closed.png')});
   await p.click('.gd-nav-group > summary');ck(role+' click expands group '+w,await p.$eval('.gd-nav-group',e=>e.open));
   ck(role+' shared links visible after expansion '+w,await p.$$eval('.gd-nav-group a',es=>es.every(e=>e.checkVisibility())));
   if(w===1366)await(await p.$('#sidebar')).screenshot({path:path.join(out,'role-sidebar-'+role+'-open.png')});
   if(w===390&&role==='counselor')await p.screenshot({path:path.join(out,'role-sidebar-mobile.png'),fullPage:false});
   await p.focus('.gd-nav-group > summary');await p.keyboard.press('Enter');ck(role+' keyboard collapses group '+w,await p.$eval('.gd-nav-group',e=>!e.open));await p.keyboard.press('Space');ck(role+' keyboard expands group '+w,await p.$eval('.gd-nav-group',e=>e.open));
   await Promise.all([p.waitForNavigation(),p.click('.gd-nav-group a[href$="view=knowledge"]')]);ck(role+' shared link opens Resources '+w,p.url().endsWith('view=knowledge'));
   ck(role+' active child automatically expands group '+w,await p.$eval('.gd-nav-group',e=>e.open&&e.classList.contains('has-active')&&e.querySelector('a.active')?.getAttribute('aria-current')==='page'));
  }
 }
 await go(ops);ck('Administrator has no clinical Requests/Appointments menu',await ops.$$eval('#sidebar nav a',es=>es.every(e=>!e.href.endsWith('view=requests')&&!e.href.endsWith('view=appointments'))));
 await go(ops,'events');ck('Administrator activity work is outside collapsed common group',await ops.evaluate(()=>!document.querySelector('.gd-nav-group').open&&document.querySelector('#sidebar nav > a.active')?.textContent.trim()==='Activity operations'));
 for(const p of [head,counselor]){await go(p,'events');ck('Clinical staff Activities opens shared group',await p.$eval('.gd-nav-group',e=>e.open&&e.querySelector('a.active')?.href.endsWith('view=events')));await go(p,'reports');ck('Authorized aggregate reports grouped',await p.$eval('.gd-nav-group',e=>e.open&&e.querySelector('a.active')?.href.endsWith('view=reports')));}
 await go(student);ck('Student has no aggregate report link',await student.$('#sidebar nav a[href$="view=reports"]')===null);
 await go(head,'settings');ck('Settings page keeps common group collapsed',await head.$eval('.gd-nav-group',e=>!e.open));ck('Team and Services remain in Settings',await head.evaluate(()=>!!document.querySelector('#guidance a[href$="view=team"]')&&!!document.querySelector('#guidance a[href$="view=categories"]')));
 ck('Counselor cannot enter head-only Team',(await counselor.goto(B+'/guidance/index.php?view=team')).status()===403);
 const sao=await login('sao_demo');await sao.goto(B+'/admin/dashboard.php');ck('Other-office staff menu not grouped',(await sao.$('.gd-nav-group'))===null);ck('Other-office staff has no new sidebar stylesheet',await sao.$$eval('link[rel=stylesheet]',es=>es.every(e=>!e.href.includes('guidance/assets/sidebar.css'))));
 await student.goto(B+'/student/dashboard.php');ck('Shared student campus dashboard menu unchanged',(await student.$('.gd-nav-group'))===null);
 // Native details/summary must work without JavaScript, not just after hydration.
 await counselor.setJavaScriptEnabled(false);await counselor.setViewport({width:1366,height:900});await counselor.goto(B+'/guidance/index.php');ck('No-JS group initially closed',await counselor.$eval('.gd-nav-group',e=>!e.open));await counselor.click('.gd-nav-group > summary');ck('No-JS disclosure expands',await counselor.$eval('.gd-nav-group',e=>e.open));await Promise.all([counselor.waitForNavigation(),counselor.click('.gd-nav-group a[href$="view=knowledge"]')]);ck('No-JS navigation works',counselor.url().endsWith('view=knowledge'));
 ck('No JavaScript exceptions',errors.length===0);
}finally{fs.writeFileSync(path.join(out,'role-sidebar.json'),JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors,checks},null,2));console.log(JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors}));await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
