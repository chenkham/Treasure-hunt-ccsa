<?php
declare(strict_types=1);
namespace Hunt;
const MIXES=[1=>['easy'=>1,'medium'=>1,'hard'=>1],2=>['easy'=>2,'medium'=>3,'hard'=>2]];
function &progress(array &$team,int $n): array {
 $team['data']['rounds'][$n]??=['route'=>[],'route_meters'=>0,'completed'=>0,'score'=>0,'status'=>'WAITING','finished_at'=>null];
 return $team['data']['rounds'][$n];
}
function snapshot(array $r): array {
 $reference=$r['paused_at']??stamp();$start=($r['started_at']??0)+($r['paused_ms']??0);
 $elapsed=empty($r['started_at'])?0:max(0,$reference-$start);
 $r['starts_in_ms']=empty($r['started_at'])?0:max(0,$start-$reference);
 $r['no_time_limit']=$r['duration_ms']===0;
 $r['remaining_ms']=$r['status']==='ENDED'?0:($r['no_time_limit']?null:max(0,$r['duration_ms']-$elapsed));
 if($r['status']==='RUNNING'&&$r['remaining_ms']===0)$r['status']='ENDED';return $r;
}
function meters(array $a,array $b): float {
 $p=deg2rad((float)$a['lat']);$q=deg2rad((float)$b['lat']);$h=sin(($q-$p)/2)**2+cos($p)*cos($q)*sin(deg2rad($b['lon']-$a['lon'])/2)**2;
 return 6371000*2*atan2(sqrt($h),sqrt(max(0,1-$h)));
}
function calibration(array $anchors): array {
 if(count($anchors)<3)fail('At least three measured GPS anchors are required');
 $rows=array_map(fn($p)=>[$p['x']/6000,$p['y']/3375,1.0],$anchors);$matrix=[];
 for($i=0;$i<3;$i++)for($j=0;$j<3;$j++)$matrix[$i][$j]=array_sum(array_map(fn($r)=>$r[$i]*$r[$j],$rows));
 $solve=function(string $field)use($rows,$matrix,$anchors):array {
  $a=$matrix;for($i=0;$i<3;$i++){$sum=0;foreach($rows as $k=>$r)$sum+=$r[$i]*$anchors[$k][$field];$a[$i][]=$sum;}
  for($i=0;$i<3;$i++){
   $pivot=$i;for($k=$i;$k<3;$k++)if(abs($a[$k][$i])>abs($a[$pivot][$i]))$pivot=$k;
   [$a[$i],$a[$pivot]]=[$a[$pivot],$a[$i]];if(abs($a[$i][$i])<1e-8)fail('GPS anchors are collinear');
   $scale=$a[$i][$i];for($j=0;$j<4;$j++)$a[$i][$j]/=$scale;
   for($k=0;$k<3;$k++)if($k!==$i){$f=$a[$k][$i];for($j=0;$j<4;$j++)$a[$k][$j]-=$f*$a[$i][$j];}
  }return [$a[0][3]/6000,$a[1][3]/3375,$a[2][3]];
 };
 $lat=$solve('lat');$lon=$solve('lon');if(abs($lat[0]*$lon[1]-$lat[1]*$lon[0])<1e-16)fail('Anchors do not describe a geographic area');
 $error=0;foreach($anchors as $p){$v=[$p['x'],$p['y'],1];$f=function($a)use($v){return $a[0]*$v[0]+$a[1]*$v[1]+$a[2];};$error=max($error,meters($p,['lat'=>$f($lat),'lon'=>$f($lon)]));}
 if($error>20)fail('Anchor fit exceeds 20m; survey the map again');return ['lat'=>$lat,'lon'=>$lon,'max_error_m'=>round($error,2)];
}
function network(array $checkpoints,array $edges): array {
 $d=[];foreach($checkpoints as $a)foreach($checkpoints as $b)$d[$a['id']][$b['id']]=$a['id']===$b['id']?0:INF;
 foreach($edges as $edge){$a=$edge['a'];$b=$edge['b'];$m=$edge['meters'];if(!isset($d[$a],$d[$b])||$a===$b||!is_numeric($m)||!is_finite((float)$m)||$m<=0)fail('Invalid walkway connection');$d[$a][$b]=min($m,$d[$a][$b]);$d[$b][$a]=min($m,$d[$b][$a]);}
 foreach(array_keys($d) as $k)foreach(array_keys($d) as $a)foreach(array_keys($d) as $b)if($d[$a][$k]+$d[$k][$b]<$d[$a][$b])$d[$a][$b]=$d[$a][$k]+$d[$k][$b];return $d;
}
function routes(array $event,array $ids,int $n,array $excluded=[]): array {
 if(threeRound($event))return routesV3($event,$ids,$n,$excluded);
 sort($ids);$rng=new \Random\Randomizer(new \Random\Engine\Mt19937((int)sprintf('%u',crc32($event['id'].":$n:".implode('|',$ids)))));
 $net=network($event['checkpoints'],$event['edges']);$finish=array_values(array_filter($event['riddles'],fn($r)=>$r['finish_only']??false));
 if($n===2&&count($finish)!==1)fail('Choose one shared connected finish clue',409);
 $loads=[];$result=[];$target=null;$tolerance=$event['settings']['route_tolerance']??.2;
 foreach($ids as $id){
  $counts=MIXES[$n];$last=$n===2?$finish[0]:null;if($last)$counts[$last['difficulty']]--;
  $pools=[];foreach($counts as $level=>$count){$pools[$level]=array_values(array_filter($event['riddles'],fn($r)=>$r['difficulty']===$level&&!($r['finish_only']??false)&&!in_array($r['id'],$excluded[$id]??[],true)));if(count($pools[$level])<$count)fail('Not enough fresh reviewed clues at each difficulty',409);}
  $best=null;
  for($attempt=0;$attempt<600;$attempt++){
   $route=[];foreach($counts as $level=>$count){$shuffled=$rng->shuffleArray($pools[$level]);$route=array_merge($route,array_slice($shuffled,0,$count));}
   $route=$rng->shuffleArray($route);if($last)$route[]=$last;$length=0;$congestion=0;$previous=$event['settings']['start_node'];
   foreach($route as $i=>$r){$length+=$net[$previous][$r['checkpoint_id']]??INF;$congestion+=$loads[$i.':'.$r['checkpoint_id']]??0;$previous=$r['checkpoint_id'];}
   if(!is_finite($length))continue;$lengths=array_column($result,'meters');$lengths[]=$length;
   if(count($lengths)>1&&max($lengths)>min($lengths)*(1+$tolerance)+1)continue;
   $score=[$loads['0:'.$route[0]['checkpoint_id']]??0,$congestion,$target===null?0:abs($length-$target)/max($target,1)];
   if($best===null||$score<$best[0])$best=[$score,$length,$route];
  }
  if($best===null)fail('No balanced reachable route. Review the inspected path graph.',409);
  $target??=$best[1];$result[$id]=['riddles'=>array_column($best[2],'id'),'meters'=>(int)round($best[1])];
  foreach($best[2] as $i=>$r){$key=$i.':'.$r['checkpoint_id'];$loads[$key]=($loads[$key]??0)+1;}
 }return $result;
}
function publishAssignments(Database $db,array &$e,string $actor): array {
 if($e['published'])fail('Already published',409);$issues=readiness($e);$teams=$db->teams();if(!$teams)$issues[]='Create at least one team';if($issues)fail(['issues'=>$issues],409);
 $routes=routes($e,array_column($teams,'id'),1);
 foreach($teams as $t){$p=&progress($t,1);$p['route']=$routes[$t['id']]['riddles'];$p['route_meters']=$routes[$t['id']]['meters'];$db->saveTeam($t);unset($p);}
 $e['published']=true;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'PUBLISHED',['teams'=>count($teams)]);return $routes;
}
function readiness(array $e): array {
 if(threeRound($e))return readinessV3($e);
 $issues=[];$cp=array_column($e['checkpoints'],null,'id');
 foreach(['easy','medium','hard'] as $level){$count=count(array_filter($e['riddles'],fn($r)=>$r['difficulty']===$level&&($r['reviewed']??false)));if($count<8)$issues[]="$level: $count/8 reviewed clues";}
 $start=$cp[$e['settings']['start_node']??'']??[];if(empty($start['verified'])||empty($start['accessible'])||!isset($start['lat'],$start['lon']))$issues[]='Inspect the outdoor starting grid and GPS';
 $finish=array_values(array_filter($e['riddles'],fn($r)=>$r['finish_only']??false));
 if(count($finish)!==1||empty($cp[$finish[0]['checkpoint_id']??'']['finish_network_verified']))$issues[]='Reserve one staffed, reliably connected round-two finish';
 if(count(array_unique(array_column($e['riddles'],'checkpoint_id')))!==count($e['riddles']))$issues[]='Each clue needs a distinct outdoor QR spot';
 foreach($e['riddles'] as $r){$c=$cp[$r['checkpoint_id']]??[];if(empty($r['reviewed'])||empty($c['verified'])||empty($c['accessible'])||!isset($c['lat'],$c['lon']))$issues[]='Review clue and inspect safe QR location: '.$r['title'];}
 foreach($e['edges'] as $edge)if(empty($edge['verified'])){$issues[]='Field-inspect every walking edge; no guessed shortcuts';break;}
 if(($e['settings']['map_space']??'')==='atlas-utm46-v1') {
  foreach($e['riddles'] as $r){$c=$cp[$r['checkpoint_id']]??[];if(isset($c['lat'],$c['lon'])&&atlasPoint((float)$c['lat'],(float)$c['lon'])===null)$issues[]='QR coordinates are outside the campus atlas: '.$c['name'];}
 }else try{$transform=calibration($e['anchors']);foreach($e['riddles'] as $r){$c=$cp[$r['checkpoint_id']]??[];if(isset($c['lat'],$c['lon'])){$f=fn($a)=>$a[0]*$c['x']+$a[1]*$c['y']+$a[2];if(meters($c,['lat'=>$f($transform['lat']),'lon'=>$f($transform['lon'])])>30)$issues[]='Map alignment exceeds 30m at '.$c['name'];}}}catch(HttpError $x){$issues[]=$x->getMessage();}
 try{$net=network($e['checkpoints'],$e['edges']);foreach($e['riddles'] as $r)if(!is_finite($net[$e['settings']['start_node']][$r['checkpoint_id']]??INF)){$issues[]='QR spots need a connected inspected walking graph';break;}}catch(\Throwable $x){$issues[]='Invalid walking graph';}
 return array_values(array_unique($issues));
}
function expire(Database $db,array &$e): void {
 if(threeRound($e)){
  $changed=false;foreach(roundNumbers($e) as $n)if($e['rounds'][$n]['status']==='RUNNING'&&snapshot($e['rounds'][$n])['remaining_ms']===0){endRoundV3($db,$e,$n,'DEADLINE');$changed=true;}
  if($changed)$db->saveEvent($e);return;
 }
 $changed=false;
 foreach([1,2] as $n)if($e['rounds'][$n]['status']==='RUNNING'&&snapshot($e['rounds'][$n])['remaining_ms']===0){
  $e['rounds'][$n]['status']='ENDED';$e['rounds'][$n]['control_version']++;$changed=true;
  foreach($db->teams() as $t){$p=&progress($t,$n);if(in_array($p['status'],['PLAYING','WAITING'],true))$p['status']=$n===1?'ELIMINATED':'TIME_OVER';$db->saveTeam($t);unset($p);}
 }if($changed){$e['leaderboard_revision']++;$db->saveEvent($e);}
}
function contentPackage(Database $db,array $e,array &$t): array {
 $ids=[];$assigned=[];foreach(roundNumbers($e) as $n){$p=&progress($t,$n);$assigned[$n]=$p['route'];$ids=array_merge($ids,$p['route']);}unset($p);$riddles=[];
 foreach($e['riddles'] as $r)if(in_array($r['id'],$ids,true)){
  $visible=array_intersect_key($r,array_flip(['id','title','question','checkpoint_id','difficulty','points','round']));
  if(sharedPool($e))$visible['title']='Quest';
  if(!continuousHunt($e)&&($r['hint_enabled']??false)&&($r['hint']??'')!=='')$visible['hint']=$r['hint'];$riddles[]=$visible;
 }
 $checkpoints=[];foreach($e['checkpoints'] as $c){$v=array_intersect_key($c,array_flip(['id','name','description','kind','x','y','lat','lon','radius_m','accessible','verified','qr_only']));if(sharedPool($e)){$v['name']='Checkpoint';$v['description']='';}$v['qr_nonce_hash']=hash('sha256',$c['nonce']);$checkpoints[]=$v;}
 $settings=$e['settings'];unset($settings['assignment_seed']);
 if(threeRound($e)){$settings['round_count']=finalRound($e);$settings['round_sizes']=array_combine([1,2,3],array_map(fn($n)=>roundTotal($e,$n),[1,2,3]));$settings['podium_places']=prizePlaces($e);}
 return ['protocol'=>threeRound($e)?3:2,'workflow'=>'QR_ONLY','event_id'=>$e['id'],'run_id'=>$e['run_id']??'','revision'=>$e['revision'],'name'=>$e['name'],'team_id'=>$t['id'],'team_code'=>$t['code'],'team_name'=>$t['name'],'avatar_id'=>$t['data']['avatar_id']??0,'device_id'=>$t['data']['device_id'],
  'riddles'=>$riddles,'checkpoints'=>$checkpoints,'calibration'=>count($e['anchors'])>=3?calibration($e['anchors']):null,'routes'=>(object)$assigned,
  'settings'=>$settings+['max_lives'=>3,'workflow'=>'QR_ONLY'],'edges'=>$e['edges']];
}
function applyAction(Database $db,array &$e,array &$t,array $a,bool $override=false): string {
 if(($a['event_id']??null)!==$e['id'])return 'WRONG_EVENT';
 if(($a['run_id']??'')!==($e['run_id']??''))return 'WRONG_RUN';
 if(($a['kind']??'')==='INCIDENT'){$db->audit($t['code'],'DEVICE_INCIDENT',['message'=>substr((string)($a['message']??''),0,500)]);return 'RECORDED';}
 if(($a['kind']??'')==='HINT'){
  if(continuousHunt($e))return 'HINTS_DISABLED';
  if(!is_string($a['riddle_id']??null))return 'NOT_ASSIGNED';
  $rid=(string)($a['riddle_id']??'');$assigned=[];foreach($t['data']['rounds']??[] as $round)$assigned=array_merge($assigned,$round['route']);
  if(!in_array($rid,$assigned,true))return 'NOT_ASSIGNED';
  $used=$t['data']['hints_used']??[];if(in_array($rid,$used,true))return 'HINT_ALREADY_REVEALED';
  if(count($used)>=3)return 'HINT_LIMIT_REACHED';
  $used[]=$rid;$t['data']['hints_used']=$used;$db->audit($t['code'],'HINT_USED',['riddle_id'=>$rid,'used'=>count($used)]);return 'HINT_GRANTED';
 }
 if(($a['revision']??null)!==$e['revision'])return 'WRONG_EVENT';
 if(($a['kind']??'')!=='SCAN')return 'QR_SCAN_REQUIRED';
 $n=$a['round']??null;if(!in_array($n,roundNumbers($e),true))return 'WRONG_ROUND';
 $d=&$t['data'];$d['lives_remaining']??=3;$p=&progress($t,$n);$r=$e['rounds'][$n];
 $start=$r['started_at']??PHP_INT_MAX;
 if(!$override&&($r['status']==='DRAFT'||stamp()<$start+($r['paused_ms']??0)||(isset($a['server_at'])&&(!is_int($a['server_at'])||$a['server_at']<$start))))return 'NOT_STARTED';
 if(!empty($d['eliminated_at']))return 'ELIMINATED';
 if(!empty($d['cooldown_until'])) {
  // Offline evidence uses elapsed time since the signed cooldown-triggering action.
  // A reboot or a manipulated clock cannot silently erase the penalty.
  $sameBoot=isset($d['cooldown_boot'],$a['boot_id'])&&$d['cooldown_boot']===$a['boot_id'];
  $elapsed=(int)($a['elapsed_ms']??0)-(int)($d['cooldown_elapsed']??PHP_INT_MAX);
  if($sameBoot&&$elapsed>=cooldownPolicy($e)){$d['lives_remaining']=3;unset($d['cooldown_until']);$db->audit($t['code'],'HEARTS_RESET',['evidence_elapsed_ms'=>$elapsed]);}
  elseif($sameBoot&&$elapsed<cooldownPolicy($e))return 'COOLDOWN';
  elseif(stamp()<$d['cooldown_until'])return 'COOLDOWN';
  elseif(!$sameBoot)return 'REVIEW_REQUIRED';
 }
 if($d['lives_remaining']<=0)return 'ELIMINATED';
 if($p['status']==='DISQUALIFIED')return 'DISQUALIFIED';
 if(!$override&&(!empty($d['needs_review'])||($a['control_version']??-1)!==$r['control_version']||$r['status']!=='RUNNING'||snapshot($r)['remaining_ms']===0||in_array($p['status'],['ELIMINATED','TIME_OVER'],true)))return 'REVIEW_REQUIRED';
 if(in_array($p['status'],['FINISHED','QUALIFIED'],true))return 'ALREADY_COMPLETED';
 if(empty($p['route'])||$p['completed']>=count($p['route']))return 'NOT_ASSIGNED';
 try{$receipt=verify($db->public,$a['qr']??[]);}catch(\Throwable $x){return 'INVALID_QR';}
 if(($receipt['event_id']??null)!==$e['id'])return 'WRONG_EVENT_QR';
 $cp=array_column($e['checkpoints'],null,'id');$scanned=$cp[$receipt['checkpoint_id']??'']??null;
 if(!$scanned)return 'INVALID_QR';
 if(!hash_equals($scanned['nonce'],(string)($receipt['nonce']??'')))return 'RETIRED_QR';
 $rid=$p['route'][$p['completed']];$riddles=array_column($e['riddles'],null,'id');$riddle=$riddles[$rid];
 if($scanned['id']!==$riddle['checkpoint_id']){
  $before=$d['lives_remaining'];$d['lives_remaining']=max(0,$before-1);
  $db->sql('INSERT INTO hunt_life_events(action_id,team_id,checkpoint_id,before_lives,after_lives,at) VALUES(?,?,?,?,?,?)',[$a['id'],$t['id'],$scanned['id'],$before,$d['lives_remaining'],stamp()]);
  $db->audit($t['code'],'WRONG_SEQUENCE',['action_id'=>$a['id'],'checkpoint_id'=>$scanned['id'],'lives_remaining'=>$d['lives_remaining']]);
  if($d['lives_remaining']===0){
   if(cooldownPolicy($e)>0){$d['cooldown_until']=stamp()+cooldownPolicy($e);$d['cooldown_boot']=$a['boot_id']??null;$d['cooldown_elapsed']=$a['elapsed_ms']??null;$d['cooldown_trigger_seq']=$a['seq'];$db->audit($t['code'],'COOLDOWN_STARTED',['until'=>$d['cooldown_until']]);return 'COOLDOWN_STARTED';}
   $d['pre_elimination_progress']=parse(json($d['rounds']));$d['eliminated_at']=stamp();$d['elimination_reason']='THREE_WRONG_SEQUENCE_SCANS';foreach(roundNumbers($e) as $round){$q=&progress($t,$round);$q['status']='ELIMINATED';$q['finished_at']=null;}unset($q);return 'ELIMINATED';}
  return 'WRONG_SEQUENCE';
 }
 if(threeRound($e)&&$r['status']==='ENDED')return 'ROUND_CLOSED';
 if(!threeRound($e)){
 $gps=$a['gps']??null;if(!is_array($gps))return 'GPS_REQUIRED';if(!empty($gps['mock']))return 'MOCK_LOCATION';
 foreach(['lat','lon','accuracy','age_ms'] as $k)if(!isset($gps[$k])||!is_numeric($gps[$k])||!is_finite((float)$gps[$k]))return 'GPS_REQUIRED';
 if($gps['lat']<-90||$gps['lat']>90||$gps['lon']<-180||$gps['lon']>180||$gps['accuracy']<0||$gps['accuracy']>25||$gps['age_ms']<0||$gps['age_ms']>15000)return 'GPS_UNRELIABLE';
 if(meters($gps,$scanned)>$scanned['radius_m'])return 'OUTSIDE_CHECKPOINT';
 }
 $p['completed']++;$p['score']+=$riddle['points'];$p['status']='PLAYING';
 if($p['completed']===count($p['route'])){if(threeRound($e))finishRoundV3($db,$e,$t,$n);else{$p['status']=$n===1?'QUALIFIED':'FINISHED';$p['finished_at']=stamp();}}
 return 'CHECKPOINT_ACCEPTED';
}
function participantLeaderboard(Database $db,array $e): array {
 $board=leaderboard($db,$e);if(sharedPool($e))foreach($board['rows'] as &$row)$row['solved']=[];unset($row);return $board;
}
function leaderboard(Database $db,array $e): array {
 if(threeRound($e))return leaderboardV3($db,$e);
 $n=$e['rounds'][2]['status']==='DRAFT'?1:2;$rows=[];
 foreach($db->teams() as $t){$p=progress($t,$n);$status=!empty($t['data']['eliminated_at'])?'ELIMINATED':(!empty($t['data']['cooldown_until'])&&stamp()<$t['data']['cooldown_until']?'COOLDOWN':$p['status']);$rows[]=['team'=>$t['name'],'code'=>$t['code'],'round'=>$n,'completed'=>$p['completed'],'total'=>$n===1?3:7,'status'=>$status,'score'=>$p['score'],'finished_at'=>$p['finished_at'],'lives_remaining'=>$t['data']['lives_remaining']??3,'cooldown_until'=>$t['data']['cooldown_until']??null,'last_seen'=>$t['data']['last_seen']??0];}
 $group=fn($s)=>in_array($s,['FINISHED','QUALIFIED'],true)?0:(in_array($s,['ELIMINATED','DISQUALIFIED'],true)?2:1);
 usort($rows,fn($a,$b)=>[$group($a['status']),$a['finished_at']??PHP_INT_MAX,-$a['completed'],$a['code']]<=>[$group($b['status']),$b['finished_at']??PHP_INT_MAX,-$b['completed'],$b['code']]);
 $previous=null;$winnerAssigned=false;foreach($rows as $i=>&$r){$tie=[$group($r['status']),$r['finished_at'],$r['completed']];$r['rank']=$i&&$tie===$previous?$rows[$i-1]['rank']:$i+1;$wins=$r['status']==='FINISHED'&&!$winnerAssigned;$r['outcome']=$wins?'WINNER':$r['status'];if($wins)$winnerAssigned=true;$previous=$tie;}unset($r);
 return ['revision'=>$e['leaderboard_revision'],'as_of'=>stamp(),'rows'=>$rows];
}

