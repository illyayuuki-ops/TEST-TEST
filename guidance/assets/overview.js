/* Presentation-only filters over the server-authorized list. No network/storage. */
(()=>{'use strict';const root=document.querySelector('[data-overview-list]');if(!root)return;
const query=root.querySelector('[data-overview-query]'),status=root.querySelector('[data-overview-status]'),items=[...root.querySelectorAll('[data-overview-item]')],empty=root.querySelector('[data-overview-empty]'),clear=root.querySelector('[data-overview-clear]'),count=root.querySelector('[data-overview-count]');
function filter(){const term=query.value.trim().toLocaleLowerCase();let visible=0;items.forEach(item=>{item.hidden=!(item.textContent.toLocaleLowerCase().includes(term)&&(!status.value||item.dataset.status===status.value));if(!item.hidden)visible++;});count.textContent=visible+' shown';count.setAttribute('aria-live','polite');if(empty)empty.hidden=visible>0;clear.hidden=!term&&!status.value;}
query.addEventListener('input',filter);status.addEventListener('change',filter);root.querySelector('[data-overview-filter]').addEventListener('click',filter);clear.addEventListener('click',()=>{query.value='';status.value='';filter();query.focus();});
})();
