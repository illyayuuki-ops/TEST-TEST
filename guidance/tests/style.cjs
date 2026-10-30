// Read-only presentation checks against a local synthetic review server.
const puppeteer = require(process.env.PUPPETEER_MODULE || '/home/user/guidance-v2-tools/node_modules/puppeteer');
const fs = require('fs'), path = require('path');
const base = process.env.GUIDANCE_BASE || 'http://127.0.0.1:8081';
if (!['127.0.0.1','localhost','[::1]'].includes(new URL(base).hostname)) throw Error('Local review server only.');
const out = path.join(__dirname,'output'); fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser = await puppeteer.launch({headless:true,args:['--no-sandbox']}); const checks=[],errors=[];
 const check=(name,passed)=>{checks.push({name,passed:!!passed});if(!passed)throw Error(name)};
 async function signIn(name) { const c=await browser.createBrowserContext(),p=await c.newPage();await p.setViewport({width:1366,height:900});await p.setRequestInterception(true);p.on('request',r=>r.url().startsWith(base)?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/auth/login.php');await p.type('[name=ident]',name);await p.type('[name=password]','GuidanceDemo2026!');await Promise.all([p.waitForNavigation(),p.click('form button[type=submit]')]);return p; }
 async function go(p,url){const r=await p.goto(base+'/'+url);check('HTTP '+url,r.status()===200);await p.evaluate(async()=>{await Promise.all(document.getAnimations().filter(a=>a.effect.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{})))});}
 try {
  const s=await signIn('student_one'),h=await signIn('guide_head'),sao=await signIn('sao_demo');
  // Student initially lands on the common dashboard: use explicit Guidance endpoint instead.
  const own=await s.evaluate(async()=>await(await fetch('/guidance/export.php')).json());
  const eid=own.certificates[0].event_id;
  for(const v of ['overview','requests','appointments','events','programs','calendar','history','knowledge','settings','event']){
   await go(s,'guidance/index.php?view='+v+(v==='event'?'&id='+eid:''));
   const active=await s.$$eval('.nav a.active',es=>es.map(e=>({url:e.getAttribute('href'),current:e.getAttribute('aria-current')})));
   const expected=v==='event'?'events':v;
   check('Correct sidebar state: '+v,v==='settings' ? active.length===0 : active.length===1&&(new URL(active[0].url,base).searchParams.get('view')||'overview')===expected&&active[0].current==='page');
  }
  await go(h,'guidance/index.php?view=walkin');check('Walk-in highlights Requests',await h.$eval('.nav a.active',e=>e.getAttribute('href').endsWith('view=requests')));
  await go(h,'guidance/index.php?view=overview');check('Query overview does not mark every link',await h.$$eval('.nav a.active',es=>es.length===1));
  await go(s,'guidance/index.php');check('Duplicate tabs and old hero removed',await s.evaluate(()=>!document.querySelector('.gd-tabs,.gd-hero,.gd-stats')));
  check('Shared campus banner and source image used',await s.$eval('#guidance .hero',e=>getComputedStyle(e).backgroundImage.includes('campus-banner.jpg')));
  check('Shared icon/accent stat markup used',(await s.$$('#guidance .stats .stat .ic')).length===4);
  check('Stylesheet cache version present',await s.evaluate(()=>!![...document.querySelectorAll('link[rel=stylesheet]')].find(e=>e.href.includes('guidance.css?v='))));
  check('Cards use unmodified native visual rules',await s.evaluate(()=>{const ref=document.createElement('section');ref.className='card';document.body.appendChild(ref);const a=getComputedStyle(ref),b=getComputedStyle(document.querySelector('#guidance .gd-card'));const ok=['padding','borderRadius','borderColor','backgroundColor','boxShadow'].every(k=>a[k]===b[k]);ref.remove();return ok}));
  await go(h,'guidance/index.php?view=walkin');
  check('Form fields match shared card inputs',await h.evaluate(()=>{const ref=document.createElement('div');ref.className='card';ref.innerHTML='<input type="text">';document.body.appendChild(ref);const a=getComputedStyle(ref.firstChild),b=getComputedStyle(document.querySelector('#guidance input[name=subject]'));const ok=['height','padding','borderRadius','borderColor','backgroundColor','fontSize','color'].every(k=>a[k]===b[k]);ref.remove();return ok}));
  await s.setViewport({width:390,height:900});await go(s,'guidance/index.php?view=requests');
  check('Table uses shared stack component',!!await s.$('.table-wrap table.stack'));
  check('All table cells have responsive labels',await s.$$eval('table.stack tbody td',es=>es.length>0&&es.every(e=>e.dataset.label)));
  check('Mobile table headings are hidden',await s.$eval('table.stack thead',e=>getComputedStyle(e).display==='none'));
  check('Mobile rows stack rather than horizontal scroll',await s.$eval('table.stack tr',e=>getComputedStyle(e).display==='block'));
  check('No mobile page overflow',await s.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
  const first=await s.$('table.stack tbody tr');await first.screenshot({path:path.join(out,'style-mobile-request.png')});
  await s.type('[data-search]','NO_MATCH_UNIQUE_STYLE_CHECK');check('Search still hides nonmatching stacked rows',await s.$$eval('table.stack tbody tr',es=>es.every(e=>getComputedStyle(e).display==='none')));
  await s.emulateMediaFeatures([{name:'prefers-reduced-motion',value:'reduce'}]);await go(s,'guidance/index.php');
  check('Guidance reduced-motion respected',await s.evaluate(()=>[...document.querySelectorAll('#guidance *')].every(e=>getComputedStyle(e).animationName==='none')));
  await go(sao,'admin/dashboard.php');check('SAO native hero retained',!!await sao.$('.hero'));
  check('SAO page has no Guidance CSS',await sao.evaluate(()=>![...document.querySelectorAll('link')].some(e=>e.href.includes('guidance.css'))));
  check('SAO queue menu still active',await sao.$eval('.nav a.active',e=>e.getAttribute('href').endsWith('admin/dashboard.php')));
  await go(sao,'admin/reports.php');check('SAO reports menu still active',await sao.$eval('.nav a.active',e=>e.getAttribute('href').endsWith('admin/reports.php')));
  check('No JavaScript exceptions',errors.length===0);
 } finally {fs.writeFileSync(path.join(out,'style.json'),JSON.stringify({passed:checks.filter(x=>x.passed).length,total:checks.length,errors,checks},null,2));console.log(JSON.stringify({passed:checks.filter(x=>x.passed).length,total:checks.length,errors}));await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
