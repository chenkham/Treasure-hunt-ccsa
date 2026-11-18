'use strict';
const eventBase=new URL('./',location.href).href.replace(/\/$/,'');
document.querySelector('#server-address').value=eventBase;
document.querySelector('#copy-address').onclick=async()=>{const status=document.querySelector('#copy-status');try{await navigator.clipboard.writeText(eventBase);status.textContent='Event address copied.';}catch{document.querySelector('#server-address').select();status.textContent='Select and copy the event address.';}};
fetch('preflight.php',{cache:'no-store'}).then(r=>r.json()).then(s=>{document.querySelector('#server-state').textContent=s.status==='ready'?'Event server ready':'Registration opens after organizer setup';}).catch(()=>{document.querySelector('#server-state').textContent='Event status unavailable';});
