"use strict";
let etag="",lastContact=0,loading=false,roundState=null,serverTime=0,anchor=0,lastError="";
const h=v=>String(v??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
async function refresh(){if(loading)return;loading=true;try{
 const requestTime=performance.now();
 const res=await fetch(new URL("../api/leaderboard",location.href),{headers:etag?{"If-None-Match":etag}:{}});
 if(res.status===304){lastContact=performance.now();lastError="";return;}
 if(!res.ok)throw Error(res.status===403?"Leaderboard not enabled":"Connection lost");
 const data=await res.json();etag=res.headers.get("ETag")||"";lastContact=performance.now();lastError="";roundState=data.round_state;serverTime=data.as_of+(performance.now()-requestTime)/2;anchor=performance.now();
 document.querySelector("#event-name").textContent=data.event||"Anazori";
 document.querySelector("#round-name").textContent="The hunt";
 document.querySelector("#public-board").innerHTML=`<div class="table-wrap"><table><thead><tr><th>Rank</th><th>Team</th><th>Completed</th><th>Status</th><th>Finished (IST)</th><th>Time taken</th></tr></thead><tbody>${data.rows.map(r=>`<tr class="${r.podium_place?'winner-row':''}"><td class="rank">${roundState?.status==='DRAFT'?'—':r.rank}</td><td><strong>${h(r.team)}</strong></td><td>${r.completed}/${r.total}</td><td>${h((r.outcome||r.status).replaceAll('_',' '))}</td><td>${huntFinishTime(r.finished_at)}</td><td>${huntElapsedTime(r.finish_elapsed_ms)}</td></tr>`).join('')||'<tr><td colspan="6">Waiting for teams</td></tr>'}</tbody></table></div>`;
 }catch(e){lastError=e.message;}finally{loading=false;}}
function tick(){const stale=!lastContact||performance.now()-lastContact>10000;const el=document.querySelector('#projector-status');el.textContent=lastError||(stale?'Offline':'Live');el.dataset.liveState=stale||lastError?'offline':'live';
 if(roundState)document.querySelector('#round-timer').textContent=huntRoundClock(roundState,serverTime+performance.now()-anchor);}

document.querySelector('#fullscreen').onclick=()=>{const task=document.fullscreenElement?document.exitFullscreen():document.documentElement.requestFullscreen();task.catch(()=>{});};
setInterval(refresh,3000);setInterval(tick,1000);refresh();
