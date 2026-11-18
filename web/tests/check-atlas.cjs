// Offline code/data checks only; no browser or network access.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {spawnSync}=require('node:child_process'),{createHash}=require('node:crypto');
const dashboard=path.resolve(__dirname,'../dashboard'),endpoint=path.join(dashboard,'atlas-data.php');
const data=JSON.parse(fs.readFileSync(path.join(dashboard,'atlas/map-data.json'),'utf8'));
const result=spawnSync('php',[endpoint],{encoding:'utf8',maxBuffer:8*1024*1024});
assert.equal(result.status,0,result.stderr);assert.deepEqual(JSON.parse(result.stdout).meta,data.meta);
const etag='"'+createHash('sha256').update(fs.readFileSync(path.join(dashboard,'atlas/map-data.json'))).digest('hex')+'"';
const cached=spawnSync('php',['-r','$_SERVER["HTTP_IF_NONE_MATCH"]=$argv[1];include $argv[2];',etag,endpoint],{encoding:'utf8'});
assert.equal(cached.status,0,cached.stderr);assert.equal(cached.stdout,'');
let requested='',failOnce=true;
const context={window:{},document:{currentScript:{src:'http://local.invalid/dashboard/atlas.js'}},URL,
  Path2D:class{moveTo(){}lineTo(){}closePath(){}},ResizeObserver:class{observe(){}disconnect(){}},Image:class{complete=false;naturalWidth=0;async decode(){throw Error('Missing character image');}},
  fetch:async url=>{requested=String(url);if(failOnce){failOnce=false;return {ok:false,status:403};}return {ok:true,json:async()=>data};},
  devicePixelRatio:1,serverNow:()=>100000,proj4:require('../dashboard/atlas/proj4.js')};
vm.createContext(context);
for(const file of ['atlas/gps-projection.js','atlas/map-labels.js','atlas.js'])vm.runInContext(fs.readFileSync(path.join(dashboard,file),'utf8'),context);
const labels=[],characters=[];const ctx=new Proxy({measureText:s=>({width:s.length*6}),fillText:s=>labels.push(s),drawImage:(...args)=>characters.push(args)},{get:(o,k)=>k in o?o[k]:(()=>{})});
const canvas={clientWidth:1000,clientHeight:500,style:{},dataset:{},getContext:()=>ctx};
(async()=>{
  await assert.rejects(context.window.HuntAtlas.load(),/HTTP 403/);
  const map=await context.window.HuntAtlas.create(canvas);
  assert.equal(new URL(requested).pathname,'/dashboard/atlas-data.php');
  map.setData([{code:'FRESH',name:'Moon runners',avatar_id:0,location:{lat:27.448067,lon:94.8953513,accuracy:8,received_at:100000,sampled_at:95000}},
    {code:'STALE',name:'River scouts',avatar_id:59,location:{lat:27.4515,lon:94.898,accuracy:90,received_at:50000,sampled_at:45000}}]);
  assert.ok(labels.includes('Moon runners')&&labels.includes('River scouts'));assert.ok(!labels.includes('FRESH')&&!labels.includes('STALE'));
  assert.equal(characters.length,0);assert.equal(canvas.dataset.teamMarkers,'2');
  map.source.characters.complete=true;map.source.characters.naturalWidth=640;map.source.characters.onload();
  assert.ok(characters.some(c=>c[1]===0&&c[2]===0));assert.ok(characters.some(c=>c[1]===576&&c[2]===400));
  assert.equal(canvas.dataset.teamMarkers,'2');const zoom=map.z;map.zoom(2);assert.ok(map.z>zoom);map.fit();
  canvas.clientWidth=0;canvas.clientHeight=0;map.resize();canvas.clientWidth=1000;canvas.clientHeight=500;map.resize();
  assert.equal(canvas.dataset.teamMarkers,'2');map.destroy();
  console.log('Atlas checks passed: PHP dataset, cache revalidation, blocked JSON URL avoidance, retry, GPS markers, zoom and resize.');
})().catch(error=>{console.error(error);process.exitCode=1;});
