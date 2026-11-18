'use strict';
let liveAtlas=null,liveAtlasLoading=false;
setInterval(()=>{if(liveAtlas&&!document.querySelector('#application')?.hidden)liveAtlas.draw();},10000);
async function initLiveMap(){
 let card=document.querySelector('#live-map-card');
 if(S.tab!=='map'){if(card)card.hidden=true;return;}
 if(!card){card=document.createElement('section');card.id='live-map-card';card.className='card';
  card.innerHTML='<div class="card-head"><div><h2>Teams on campus</h2><p>Latest received GPS · purple is recent, gray is stale · tap a team</p></div><span class="badge">Private organizer view</span></div><div class="atlas-tools"><button class="secondary" data-atlas="in">+</button><button class="secondary" data-atlas="out">−</button><button class="secondary" data-atlas="fit">Fit campus</button></div><canvas class="geographic-map" id="live-map" aria-label="Geographic campus map with last received team locations"></canvas><p class="hint" id="live-map-note">Loading offline campus vectors…</p>';
  document.querySelector('#view').before(card);
 }
 card.hidden=false;
 if(liveAtlas&&liveAtlas.canvas!==card.querySelector('canvas')){liveAtlas.destroy();liveAtlas=null;}
 if(!liveAtlas&&!liveAtlasLoading){
  liveAtlasLoading=true;
  try {liveAtlas=await HuntAtlas.create(card.querySelector('canvas'),p=>{if(!p.team)return;const t=p.team,l=t.location;modal(t.name,`<p>GPS ${esc(l.lat.toFixed(6))}, ${esc(l.lon.toFixed(6))}</p><p>Accuracy ±${Math.round(l.accuracy)}m · ${esc(age(l.sampled_at??l.received_at))}</p><p>${t.pending_actions} uploads reported pending · ${t.kiosk?'Managed kiosk active':'Kiosk not confirmed'}</p><p>Last synchronized position; offline movement is unknown.</p>`,null);});
   card.querySelector('#live-map-note').textContent='Latest GPS · gray markers are stale · circles show accuracy.';
   card.querySelectorAll('[data-atlas]').forEach(b=>b.onclick=()=>b.dataset.atlas==='fit'?liveAtlas.fit():liveAtlas.zoom(b.dataset.atlas==='in'?1.5:1/1.5));
  }catch(e){card.querySelector('#live-map-note').textContent='Map unavailable: '+e.message+' Check that the complete dashboard/atlas folder was uploaded. Retrying…';}
  finally{liveAtlasLoading=false;}
 }
 card.hidden=S.tab!=='map'||!S.token;if(liveAtlas&&!card.hidden&&S.data){liveAtlas.resize();liveAtlas.setData(S.data.teams);}
}
