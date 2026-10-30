/* No password storage, analytics, logging or network calls. Server validation is authoritative. */
(()=>{'use strict';
 document.querySelectorAll('.gd-password-toggle').forEach(button=>{
  const input=document.getElementById(button.getAttribute('aria-controls'));
  button.addEventListener('click',()=>{const show=input.type==='password';input.type=show?'text':'password';button.textContent=show?'Hide':'Show';button.setAttribute('aria-pressed',String(show));button.setAttribute('aria-label',(show?'Hide ':'Show ')+button.dataset.gpLabel);input.focus({preventScroll:true});});
 });
 document.querySelectorAll('form').forEach(form=>{
  const next=form.querySelector('[data-gp-new]'),confirm=form.querySelector('[data-gp-confirm]');if(!next)return;
  function refresh(){
   const value=next.value,n=Array.from(value).length,flags={length:n>=12&&n<=128,upper:/[A-Z]/.test(value),lower:/[a-z]/.test(value),number:/[0-9]/.test(value)};
   const box=next.closest('.gd-password-field');
   box.querySelectorAll('[data-rule]').forEach(li=>{li.dataset.met=String(flags[li.dataset.rule]);});
   let error='';if(value){if(!flags.length)error='Use 12–128 characters for the new password.';else if(value.includes('\0'))error='Passwords cannot contain null characters.';else if(!flags.upper||!flags.lower||!flags.number)error='Include an uppercase letter, a lowercase letter and a number.';}
   next.setCustomValidity(error);
   let score=0;if(n>=12)score++;if(flags.upper&&flags.lower)score++;if(flags.number)score++;if(n>=12&&/[^A-Za-z0-9]/.test(value))score++;
   box.querySelectorAll('.gd-password-meter span').forEach((span,i)=>span.dataset.filled=String(i<score));
   const strength=box.querySelector('[data-gp-strength]');strength.textContent=value?'Strength estimate: '+(error?'Requirements not met':['Very weak','Weak','Fair','Strong','Excellent'][score])+'. '+(error||'Requirements met.'):'Use a unique password or passphrase.';
   if(confirm){const mismatch=!!confirm.value&&confirm.value!==value;confirm.setCustomValidity(mismatch?'New passwords do not match.':'');const note=confirm.closest('.gd-password-field').querySelector('[data-gp-match]');note.dataset.match=confirm.value?(mismatch?'no':'yes'):'';note.textContent=confirm.value?(mismatch?'New passwords do not match.':'Passwords match.'):'Re-enter the new password.';}
  }
  next.addEventListener('input',refresh);next.addEventListener('change',refresh);if(confirm){confirm.addEventListener('input',refresh);confirm.addEventListener('change',refresh);}
  form.addEventListener('submit',event=>{refresh();if(!form.checkValidity()){event.preventDefault();event.stopImmediatePropagation();form.reportValidity();}});
  refresh();
 });
})();
