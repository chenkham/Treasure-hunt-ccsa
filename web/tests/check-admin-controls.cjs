const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
let click,dialog;const calls=[];const field=value=>({value,disabled:false,label:{hidden:false},closest(){return this.label;}});
const form={elements:{timing:field('unlimited'),minutes:field('60')}};
const round={status:'DRAFT',duration_ms:0};
const c={S:{access:{role:'owner'},data:{event:{name:'Hunt',edit_revision:1,rounds:{1:round},settings:{hunt_mode:'continuous-v1',target_riddles:12,organizer_phone:'',opponent_map:'live'},riddles:Array(40).fill({}),public_leaderboard:true}}},roundSize:()=>12,can:()=>c.S.access.role!=='volunteer',esc:x=>String(x??''),$:(s)=>form,document:{addEventListener:(_,f)=>click=f},modal:(title,html,save)=>{c.S.modalEditRevision=1;dialog={title,html,save}},api:async(url,o)=>{calls.push({url,body:JSON.parse(o.body)});return {}},refresh:async()=>{},notify:()=>{},download:()=>{},FormData:class{constructor(f){this.f=f;}get(k){return this.f.values[k];}}};
vm.createContext(c);vm.runInContext(fs.readFileSync(path.join(__dirname,'../dashboard/controls.js'),'utf8'),c);
const button=async(attr,dataset={})=>click({target:{closest:()=>({dataset,hasAttribute:x=>x===attr})}});
(async()=>{
 assert.deepEqual(Array.from(c.enabledRounds()),[1]);assert.doesNotMatch(c.eventControls(),/hint|Round format|Prize places/);assert.match(c.eventControls(),/3 winners/);
 await button('data-live-settings');assert.match(dialog.html,/Total riddles per team/);assert.match(dialog.html,/Start countdown \(seconds\)/);assert.match(dialog.html,/Helpline number/);assert.doesNotMatch(dialog.html,/hint|121|112|Round 2/);
 await dialog.save({values:{name:'Hunt',target_riddles:'8',start_countdown_seconds:'45',phone_mode:'standard',hearts:'recover',organizer_phone:'+91 12345 67890',opponent_map:'live'},elements:{public_leaderboard:{checked:true}}});assert.equal(calls.at(-1).body.target_riddles,8);assert.equal(calls.at(-1).body.start_countdown_seconds,45);assert.equal(calls.at(-1).body.organizer_phone,'+91 12345 67890');assert.equal(calls.at(-1).body.podium_places,undefined);
 c.S.data.event.rounds[1].status='RUNNING';await button('data-live-settings');assert.doesNotMatch(dialog.html,/Total riddles per team|start_countdown_seconds/);
 await button('',{roundSettings:'1'});await dialog.save({values:{timing:'timed',minutes:'10'}});assert.equal(calls.at(-1).body.remaining_minutes,10);
 await button('',{roundSettings:'1'});await dialog.save({values:{timing:'unlimited'}});assert.equal(calls.at(-1).body.duration_ms,0);
 c.S.access.role='volunteer';assert.doesNotMatch(c.eventControls(),/data-reset-hunt|data-live-settings/);
 console.log('Continuous hunt dashboard checks passed: total, helpline, timers, fixed podium and no hints/round selectors.');
})().catch(e=>{console.error(e);process.exitCode=1});
