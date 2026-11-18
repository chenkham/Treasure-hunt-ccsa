'use strict';
(async()=>{
 const mode=new URLSearchParams(location.search).get('mode')||'organizer',token=sessionStorage.getItem('hunt-admin');
 try{
  if(!token)throw Error('Sign in to the admin dashboard, then open the print sheets.');
  if(!['organizer','stations'].includes(mode))throw Error('Unknown print layout');
  const res=await fetch(new URL('../api/admin/print/'+mode,location.href),{headers:{Authorization:'Bearer '+token}});
  if(!res.ok){const data=await res.json();throw Error(data.detail||'Print sheets unavailable');}
  const html=await res.text(),parsed=new DOMParser().parseFromString(html,'text/html');
  document.querySelector('#sheets').append(parsed.querySelector('.print-pack'));
  const count=document.querySelectorAll('article').length;
  document.querySelector('#print-status').textContent=`${count} cards · ${Math.ceil(count/4)} A4 pages · ${mode==='organizer'?'Private organizer copy':'Station QR codes'}`;
  document.querySelector('#print').disabled=false;document.querySelector('#download').disabled=false;
  document.querySelector('#print').onclick=()=>window.print();
  document.querySelector('#download').onclick=()=>{const url=URL.createObjectURL(new Blob([html],{type:'text/html'})),link=document.createElement('a');link.href=url;link.download='Treasure-Hunt-QR-'+mode+'.html';link.click();setTimeout(()=>URL.revokeObjectURL(url),30000);};
 }catch(e){document.querySelector('#print-status').textContent=e.message;}
})();
