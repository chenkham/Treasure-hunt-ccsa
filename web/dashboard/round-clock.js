(function(root){
 'use strict';
 function roundClock(round,now){
  if(round.status==='DRAFT')return 'Not started';
  const reference=round.status==='PAUSED'?round.paused_at:now;
  const start=(round.started_at||0)+(round.paused_ms||0),waiting=Math.max(0,start-reference);
  if(round.status!=='ENDED'&&!waiting&&round.duration_ms===0)return round.status==='PAUSED'?'Paused · no limit':'No time limit';
  const ms=round.status==='ENDED'?0:waiting||Math.max(0,round.duration_ms-Math.max(0,reference-start));
  const sec=Math.ceil(ms/1000);
  return (waiting?'Starts in ':'')+String(Math.floor(sec/60)).padStart(2,'0')+':'+String(sec%60).padStart(2,'0');
 }
 function finishTime(ms){return Number.isFinite(ms)&&ms>0?new Date(ms+19800000).toISOString().replace('T',' ').replace('Z',' IST'):'—';}
 function elapsedTime(ms){if(!Number.isFinite(ms)||ms<0)return '—';return String(Math.floor(ms/3600000)).padStart(2,'0')+':'+String(Math.floor(ms/60000)%60).padStart(2,'0')+':'+String(Math.floor(ms/1000)%60).padStart(2,'0')+'.'+String(ms%1000).padStart(3,'0');}
 if(typeof module==='object'&&module.exports){module.exports=roundClock;roundClock.finishTime=finishTime;roundClock.elapsedTime=elapsedTime;}else {root.huntRoundClock=roundClock;root.huntFinishTime=finishTime;root.huntElapsedTime=elapsedTime;}
})(typeof globalThis==='object'?globalThis:this);
