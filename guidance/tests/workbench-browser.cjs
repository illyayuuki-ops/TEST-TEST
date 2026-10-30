const puppeteer=require(process.env.PUPPETEER_MODULE||'/home/user/guidance-v2-tools/node_modules/puppeteer');
const fs=require('fs'),path=require('path');const B=process.env.GUIDANCE_BASE||'http://127.0.0.1:8081';
if(process.env.LCCT_SYNTHETIC_TEST!=='1'||!['127.0.0.1','localhost','[::1]'].includes(new URL(B).hostname))throw Error('Synthetic local server only');
const out=path.join(__dirname,'output'),f=JSON.parse(fs.readFileSync(path.join(out,'workbench-fixture.json')));
(async()=>{const browser=await puppeteer.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const checks=[],errors=[];const ck=(name,ok)=>{checks.push({name,passed:!!ok});if(!ok)throw Error(name)};
async function login(name){const c=await browser.createBrowserContext(),p=await c.newPage();await p.setRequestInterception(true);p.on('request',r=>r.url().startsWith(B)?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(B+'/auth/login.php');await p.type('[name=ident]',name);await p.type('[name=password]','GuidanceDemo2026!');await Promise.all([p.waitForNavigation(),p.click('form button[type=submit]')]);ck('Login '+name,!p.url().includes('login.php'));return p}
async function go(p,v,w){await p.setViewport({width:w,height:950});let r=await p.goto(B+'/guidance/index.php?'+v);ck(v+' HTTP '+w,r.status()===200);await p.evaluate(async()=>{await Promise.all(document.getAnimations().filter(a=>a.effect.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{})))});ck(v+' fits '+w,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));ck(v+' no broken image '+w,await p.$$eval('img',a=>a.every(e=>e.complete&&e.naturalWidth>0)));}
try {
const h=await login('guide_head'),c=await login('guide_counselor'),s=await login('student_one'),a=await login('guide_admin');
for(const p of [h,c])for(const width of [1366,390,320])for(const v of ['view=students','view=students&id='+f.student_two,'view=requests&category='+f.category,'view=requests&layout=board&category='+f.category,'view=reports&category='+f.category])await go(p,v,width);
for(const p of [s,a]){await go(p,'view=requests',390);ck('Student/operations no directory link',await p.$$eval('.nav a',a=>!a.some(e=>e.href.includes('view=students'))));}
await go(h,'view=students',1366);ck('Directory highlights exactly one nav link',await h.$$eval('.nav a.active',a=>a.length===1&&a[0].href.includes('view=students')));
await h.screenshot({path:path.join(out,'workbench-directory.png'),fullPage:true});
await go(h,'view=requests&layout=board&category='+f.category,1366);await h.screenshot({path:path.join(out,'workbench-board.png'),fullPage:true});
await go(h,'view=reports&category='+f.category,1366);await h.screenshot({path:path.join(out,'workbench-reports.png'),fullPage:true});
await go(h,'view=requests&layout=board&category='+f.category,390);await h.screenshot({path:path.join(out,'workbench-mobile.png'),fullPage:true});
await go(h,'view=requests',1366);let dialogs=0;const handle=d=>{dialogs++;d.dismiss()};h.on('dialog',handle);
await h.type('[name=q]',f.tag);await Promise.all([h.waitForNavigation(),h.click('[data-gfilter] button[type=submit]')]);ck('GET filters submit without draft warning',dialogs===0);ck('GET query persisted in URL',new URL(h.url()).searchParams.get('q')===f.tag);h.off('dialog',handle);
await go(s,'view=requests&category='+f.category+'&status=Resolved',1366);await s.$eval('#guidance details',e=>e.open=true);await s.type('[data-gform] textarea[name=feedback]','Unsent clinical feedback must remain protected');
const dialog=new Promise(resolve=>s.once('dialog',async d=>{ck('POST draft still raises beforeunload',d.type()==='beforeunload');await d.dismiss();resolve()}));await s.click('[data-gfilter] button[type=submit]');await dialog;ck('Cancelled navigation preserves POST draft',await s.$eval('textarea[name=feedback]',e=>e.value.includes('Unsent clinical')));
// No-JavaScript operation: filtering, pagination, directory and reports remain server rendered.
await h.setJavaScriptEnabled(false);for(const v of ['view=students','view=requests&layout=board&category='+f.category,'view=reports&category='+f.category]){const r=await h.goto(B+'/guidance/index.php?'+v);ck('No-JS '+v,r.status()===200);ck('No-JS has native GET form',!!await h.$('form[method=get]'));}
await h.goto(B+'/guidance/index.php?view=requests&category='+f.category);await h.select('[name=status]','Resolved');await Promise.all([h.waitForNavigation(),h.click('[data-gfilter] button[type=submit]')]);ck('No-JS filter works',new URL(h.url()).searchParams.get('status')==='Resolved');
ck('No JavaScript exceptions',errors.length===0);
}finally{fs.writeFileSync(path.join(out,'workbench-browser.json'),JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,checks,errors},null,2));console.log(JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors}));await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
