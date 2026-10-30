const puppeteer=require(process.env.PUPPETEER_MODULE||'/home/user/guidance-v2-tools/node_modules/puppeteer');
const fs=require('fs'),path=require('path'),B=process.env.GUIDANCE_BASE||'http://127.0.0.1:8081';
if(!['127.0.0.1','localhost','[::1]'].includes(new URL(B).hostname))throw Error('Synthetic local server required');
(async()=>{const browser=await puppeteer.launch({headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const checks=[],errors=[];const ck=(name,ok)=>{checks.push({name,passed:!!ok});if(!ok)throw Error(name)};const out=path.join(__dirname,'output');
async function login(user){const ctx=await browser.createBrowserContext(),p=await ctx.newPage();await p.setRequestInterception(true);p.on('request',r=>r.url().startsWith(B)?r.continue():r.abort());p.on('pageerror',e=>errors.push(e.message));await p.goto(B+'/auth/login.php');await p.type('[name=ident]',user);await p.type('[name=password]','GuidanceDemo2026!');await Promise.all([p.waitForNavigation(),p.click('button[type=submit]')]);return p;}
async function put(p,selector,value){await p.$eval(selector,(e,v)=>{e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));},value)}
try{
 const student=await login('student_two'),head=await login('guide_head'),ops=await login('guide_admin'),counselor=await login('guide_counselor');
 for(const [p,labels] of [[student,['Request Guidance','Book an appointment','My activities']],[head,['Review requests','Appointments','Activities']],[ops,['Manage activities','Manage programs','Aggregate reports']],[counselor,['Assigned requests','Appointments','Activities']]]){
  await p.goto(B+'/guidance/index.php');const body=await p.$eval('.hero',e=>e.textContent);ck('Role-appropriate quick actions: '+labels[0],labels.every(x=>body.includes(x)));
 }
 await ops.goto(B+'/guidance/index.php');ck('Operations hero has no clinical shortcut',await ops.$eval('.hero',e=>!e.querySelector('[href*="view=requests"]')&&!e.querySelector('[href*="view=appointments"]')));
 await student.goto(B+'/guidance/index.php?view=settings');await Promise.all([student.waitForNavigation(),student.click('#guidance a[href*="change_password"]')]);ck('Guidance settings preserves password context',student.url().endsWith('/auth/change_password.php?from=guidance'));
 for(const w of [1366,390,320]){
  await student.setViewport({width:w,height:900});await student.reload();await student.evaluate(async()=>{await Promise.all(document.getAnimations().filter(a=>a.effect.getComputedTiming().iterations!==Infinity).map(a=>a.finished.catch(()=>{})))});
  ck('Password screen no overflow '+w,await student.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
  ck('Three independent password toggles '+w,(await student.$$('.gd-password-toggle')).length===3);
  ck('Account page has no misleading sidebar selection '+w,await student.evaluate(()=>{const links=[...document.querySelectorAll('nav a.active')];return links.length===0}));
  ck('Password labels properly associated '+w,await student.evaluate(()=>[...document.querySelectorAll('.gd-password-control input')].every(e=>document.querySelector('label[for="'+e.id+'"]'))));
  if(w!==320)await student.screenshot({path:path.join(out,'password-'+w+'.png'),fullPage:true});
 }
 await student.setViewport({width:1366,height:900});await put(student,'[name=new]','Aa1short');ck('Short password rejected in browser',await student.$eval('[name=new]',e=>!e.checkValidity()));
 ck('Short password not labelled strong',await student.$eval('[data-gp-strength]',e=>e.textContent.includes('Requirements not met')));
 await put(student,'[name=new]','Acceptable2026!');ck('Valid new password accepted',await student.$eval('[name=new]',e=>e.checkValidity()));
 ck('Requirement checklist complete',(await student.$$('[data-rule][data-met=true]')).length===4);
 await put(student,'[name=new2]','Mismatch2026!');ck('Confirmation mismatch rejected',await student.$eval('[name=new2]',e=>!e.checkValidity()));
 ck('Confirmation mismatch explained',await student.$eval('[data-gp-match]',e=>e.textContent.includes('do not match')));
 await put(student,'[name=new2]','Acceptable2026!');ck('Matching confirmation accepted',await student.$eval('[name=new2]',e=>e.checkValidity()));
 await student.click('[aria-controls="gd-password-2"]');ck('Show password changes only selected input',await student.evaluate(()=>document.querySelector('[name=new]').type==='text'&&document.querySelector('[name=current]').type==='password'&&document.querySelector('[name=new2]').type==='password'));
 ck('Toggle accessible state updated',await student.$eval('[aria-controls="gd-password-2"]',e=>e.getAttribute('aria-pressed')==='true'&&e.textContent==='Hide'));
 await student.click('[aria-controls="gd-password-2"]');ck('Hide password restored',await student.$eval('[name=new]',e=>e.type==='password'));
 const unicode='Aa1'+'😀'.repeat(125);await put(student,'[name=new]',unicode);ck('Browser accepts 128 Unicode code points',await student.$eval('[name=new]',e=>e.checkValidity()));await put(student,'[name=new]',unicode+'x');ck('Browser rejects 129 code points',await student.$eval('[name=new]',e=>!e.checkValidity()));
 ck('Passwords not saved to web storage',await student.evaluate(()=>!JSON.stringify({...localStorage,...sessionStorage}).includes('Acceptable2026!')));
 // Server rejection clears password values, while ordinary AJAX form errors preserve input.
 await put(student,'[name=current]','incorrect');await put(student,'[name=new]','Acceptable2026!');await put(student,'[name=new2]','Acceptable2026!');await Promise.all([student.waitForNavigation(),student.click('[data-gp-form] button[type=submit]')]);
 ck('Server rejection presented accessibly',(await student.$('[role=alert]'))!==null);ck('Server rejected secrets cleared',await student.evaluate(()=>[...document.querySelectorAll('.gd-password-control input')].every(e=>e.value==='')));
 await head.goto(B+'/guidance/index.php?view=team');await head.click('#guidance details summary');
 for(const w of [1366,390,320]){await head.setViewport({width:w,height:900});ck('Staff creation no overflow '+w,await head.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));ck('Staff has new and confirmation controls '+w,await head.$eval('input[name=action][value="staff.create"]',e=>e.form.querySelectorAll('[data-gp-new],[data-gp-confirm]').length===2));}
 await put(head,'[name=full_name]','Synthetic retained input');await put(head,'[name=username]','guide_head');await put(head,'[name=email]','guide_head@example.invalid');await put(head,'[name=password]','TemporaryNew2026!');await put(head,'[name=password_confirm]','Mismatch2026!');
 ck('Staff confirmation custom validity wired',await head.$eval('[name=password_confirm]',e=>!e.checkValidity()));
 await put(head,'[name=password_confirm]','TemporaryNew2026!');await head.click('form button[type=submit]');await head.waitForFunction(()=>document.querySelector('.gd-error').textContent.includes('already exists'));
 ck('Ordinary input survives failed save',await head.$eval('[name=full_name]',e=>e.value==='Synthetic retained input'));ck('Error receives keyboard focus',await head.$eval('.gd-error',e=>document.activeElement===e));ck('Save button re-enabled',await head.$eval('form button[type=submit]',e=>!e.disabled&&e.textContent==='Create account'));
 // Successful non-secret form save has visible, action-specific confirmation after redirect.
 head.on('dialog',d=>d.accept());await head.goto(B+'/guidance/index.php?view=programs');await head.click('#guidance details summary');await head.type('[name=title]','Polish browser '+Date.now());await head.type('[name=description]','Synthetic usability review.');await Promise.all([head.waitForNavigation(),head.click('form button[type=submit]')]);ck('Action-specific success survives redirect',await head.evaluate(()=>document.body.textContent.includes('Program saved.')));
 ck('No browser JavaScript exceptions',errors.length===0);
}finally{fs.mkdirSync(out,{recursive:true});fs.writeFileSync(path.join(out,'polish-browser.json'),JSON.stringify({passed:checks.filter(x=>x.passed).length,total:checks.length,errors,checks},null,2));console.log(JSON.stringify({passed:checks.filter(x=>x.passed).length,total:checks.length,errors}));await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
