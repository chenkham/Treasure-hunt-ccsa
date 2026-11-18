<?php
declare(strict_types=1);
namespace Hunt;

const THREE_ROUND_MIXES=[1=>['easy'=>1,'medium'=>1,'hard'=>1],2=>['neutral'=>7],3=>['hard'=>2]];
function sharedPool(array $e): bool { return ($e['settings']['riddle_pool']??'')==='shared'; }
function threeRound(array $e): bool { return ($e['settings']['event_format']??'')==='three-round-v3'; }
function continuousHunt(array $e): bool { return ($e['settings']['hunt_mode']??'')==='continuous-v1'; }
function roundNumbers(array $e): array { return continuousHunt($e)?[1]:range(1,threeRound($e)?max(1,min(3,(int)($e['settings']['round_count']??3))):2); }
function finalRound(array $e): int { return max(roundNumbers($e)); }
function prizePlaces(array $e): int { return continuousHunt($e)?3:max(1,min(3,(int)($e['settings']['podium_places']??3))); }
function roundTotal(array $e,int $n): int {
 if(continuousHunt($e))return (int)($e['settings']['target_riddles']??12);
 return sharedPool($e)?(int)($e['settings']['round_sizes'][$n]??array_sum(THREE_ROUND_MIXES[$n])):array_sum((threeRound($e)?THREE_ROUND_MIXES:MIXES)[$n]);
}
function activeRound(array $e): int {
 $active=1;foreach(roundNumbers($e) as $n)if($e['rounds'][$n]['status']!=='DRAFT')$active=$n;return $active;
}
function defaultRounds(): array {
 $rows=[];foreach([1=>60,2=>90,3=>30] as $n=>$minutes)$rows[$n]=[
  'status'=>'DRAFT','duration_ms'=>$minutes*60000,'started_at'=>null,'paused_at'=>null,'paused_ms'=>0,
  'pause_intervals'=>[],'control_version'=>0,'qualify_mode'=>'all','qualify_limit'=>20];return $rows;
}
function validateRoundSettings(array $rounds): void {
 if(array_map('intval',array_keys($rounds))!==[1,2,3])fail('Configure all three rounds');
 foreach($rounds as $n=>$r){$duration=$r['duration_ms']??null;if(!is_int($duration)||!($duration===0||($duration>=60000&&($duration<=14400000||in_array($r['status']??'',['RUNNING','PAUSED'],true)))))fail('Choose no time limit or 1–240 minutes.');
  if(!in_array($r['qualify_mode']??null,['all','first_n'],true)||!is_int($r['qualify_limit']??null)||$r['qualify_limit']<1||$r['qualify_limit']>1000)fail('Choose deadline or first-N qualification with a limit of 1–1000');}
}
function validateRiddleText(array &$r): void {
 foreach(['title'=>150,'question'=>5000,'placement'=>1000,'hint'=>1200] as $key=>$limit){
  $value=trim((string)($r[$key]??''));if((in_array($key,['title','question'],true)&&$value==='')||mb_strlen($value)>$limit)fail('Enter valid riddle text, placement and an optional hint');$r[$key]=$value;
 }
 $r['hint_enabled']??=false;if(!is_bool($r['hint_enabled']))fail('Hint visibility must be on or off');
 if($r['hint_enabled']&&$r['hint']==='')fail('Enter a hint before showing it to teams');
}
function prepareV3Content(array &$e,array $body): void {
 if(continuousHunt($e)&&(!is_int($e['settings']['target_riddles']??null)||$e['settings']['target_riddles']<1||$e['settings']['target_riddles']>count($e['riddles'])))fail('Choose a valid total riddles per team');
 if(sharedPool($e)){
  $count=$e['settings']['round_count']??3;$places=$e['settings']['podium_places']??3;$sizes=$e['settings']['round_sizes']??[1=>3,2=>7,3=>2];
  if(!is_int($count)||$count<1||$count>3||!is_int($places)||$places<1||$places>3||!is_array($sizes)||array_map('intval',array_keys($sizes))!==[1,2,3])fail('Choose 1–3 rounds and prize places');
  foreach($sizes as $size)if(!is_int($size)||$size<1||$size>200)fail('Choose 1–200 riddles per round');
 }
 if(isset($body['round_settings'])){
  validateRoundSettings($body['round_settings']);
  foreach($body['round_settings'] as $n=>$v){if($e['rounds'][$n]['status']!=='DRAFT')fail('An active round cannot be reconfigured',409);
   foreach(['duration_ms','qualify_mode','qualify_limit'] as $k)$e['rounds'][$n][$k]=$v[$k];}
 }
 $old=array_column($e['checkpoints'],null,'id');$checkpoints=[];
 foreach($e['riddles'] as &$r){
  $n=$r['round']??null;$level=$r['difficulty']??null;
  if(sharedPool($e)){$r['round']=0;$r['difficulty']='neutral';}
  elseif(!is_int($n)||!isset(THREE_ROUND_MIXES[$n][$level??'']))fail('Choose a supported riddle category for this legacy event');
  validateRiddleText($r);
  if(!is_bool($r['reviewed']??null))fail('Riddle review must be true or false');
  $r['points']=100;$r['checkpoint_id']='qr-'.$r['id'];unset($r['answers'],$r['validators'],$r['finish_only']);
  $checkpoints[]=['id'=>$r['checkpoint_id'],'name'=>$r['title'],'kind'=>'checkpoint','nonce'=>$old[$r['checkpoint_id']]['nonce']??token(),
   'qr_only'=>true,'description'=>''];
 }unset($r);
 $e['checkpoints']=$checkpoints;$e['anchors']=[];$e['edges']=[];
 $e['settings']['qr_validation']='signed_code';$e['settings']['min_android_version']=sharedPool($e)?18:8;
}
function readinessV3(array $e): array {
 $issues=[];
 if(sharedPool($e)){
  $count=count(array_filter($e['riddles'],fn($r)=>($r['reviewed']??false)));
  $needed=array_sum(array_map(fn($n)=>roundTotal($e,$n),roundNumbers($e)));
  if($count<$needed)$issues[]="Add at least $needed reviewed riddles for the enabled rounds without repeats";
 }else foreach(THREE_ROUND_MIXES as $n=>$mix)foreach($mix as $level=>$needed){
  $count=count(array_filter($e['riddles'],fn($r)=>($r['round']??0)===$n&&$r['difficulty']===$level&&($r['reviewed']??false)));
  if($count<$needed)$issues[]="Round $n: add $needed reviewed ".($level==='neutral'?'unlabelled':$level).' riddles';
 }
 foreach($e['riddles'] as $r)if(empty($r['reviewed']))$issues[]='Review: '.$r['title'];
 return $issues;
}
function routesV3(array $e,array $ids,int $n,array $excluded=[]): array {
 sort($ids);
 // A private event seed makes retries reproducible without predictable public assignments.
 $seed=hash('sha256',($e['settings']['assignment_seed']??$e['id']).":$n:".implode('|',$ids));
 $rng=new \Random\Randomizer(new \Random\Engine\Mt19937((int)hexdec(substr($seed,0,8))));
 $ids=$rng->shuffleArray($ids);$loads=[];$result=[];
 if(sharedPool($e)){
  $pool=array_column(array_filter($e['riddles'],fn($r)=>($r['reviewed']??false)),'id');$total=[];$edges=[];
  foreach($ids as $id){$route=[];
   for($slot=0;$slot<roundTotal($e,$n);$slot++){
    $candidates=$rng->shuffleArray(array_values(array_diff($pool,$excluded[$id]??[],$route)));
    if(!$candidates)fail('Not enough fresh riddles for this team',409);
    $previous=$route[$slot-1]??'';
    $cost=fn($rid)=>[$loads[$slot.':'.$rid]??0,$total[$rid]??0,$edges[$previous.':'.$rid]??0];
    usort($candidates,fn($a,$b)=>$cost($a)<=>$cost($b));$rid=$candidates[0];$route[]=$rid;
    $loads[$slot.':'.$rid]=($loads[$slot.':'.$rid]??0)+1;$total[$rid]=($total[$rid]??0)+1;$edges[$previous.':'.$rid]=($edges[$previous.':'.$rid]??0)+1;
   }
   $result[$id]=['riddles'=>$route,'meters'=>0];
  }return $result;
 }
 foreach($ids as $id){$best=null;
  for($attempt=0;$attempt<120;$attempt++){
   $route=[];foreach(THREE_ROUND_MIXES[$n] as $level=>$count){
    $pool=array_values(array_filter($e['riddles'],fn($r)=>($r['round']??0)===$n&&$r['difficulty']===$level&&($r['reviewed']??false)));
    if(count($pool)<$count)fail('Not enough reviewed riddles for this round',409);
    $route=array_merge($route,array_column(array_slice($rng->shuffleArray($pool),0,$count),'id'));
   }
   $route=$rng->shuffleArray($route);$later=0;foreach($route as $i=>$rid)$later+=$loads[$i.':'.$rid]??0;
   $score=[$loads['0:'.$route[0]]??0,$later];if($best===null||$score<$best[0])$best=[$score,$route];
  }
  $result[$id]=['riddles'=>$best[1],'meters'=>0];foreach($best[1] as $i=>$rid)$loads[$i.':'.$rid]=($loads[$i.':'.$rid]??0)+1;
 }return $result;
}
function endRoundV3(Database $db,array &$e,int $n,string $reason,?string $skip=null): void {
 $r=&$e['rounds'][$n];$r['status']='ENDED';$r['ended_at']=stamp();$r['end_reason']=$reason;$r['control_version']++;
 foreach($db->teams() as $t){if($t['id']===$skip)continue;$p=&progress($t,$n);
  if(in_array($p['status'],['WAITING','PLAYING'],true)&&!empty($p['route'])){
   $p['status']='ELIMINATED';$t['data']['eliminated_at']??=stamp();$t['data']['elimination_reason']??='ROUND_'.$n.'_'.$reason;$db->saveTeam($t);
  }unset($p);
 }
 $e['leaderboard_revision']++;$db->audit('authority','ROUND_ENDED',['round'=>$n,'reason'=>$reason]);
}
function finishRoundV3(Database $db,array &$e,array &$t,int $n): void {
 $final=finalRound($e);$places=prizePlaces($e);
 if($n===$final){
  $count=0;foreach($db->teams() as $other)if($other['id']!==$t['id'])$count=max($count,(int)(progress($other,$final)['podium_place']??0));
  if($e['rounds'][$final]['status']!=='RUNNING'||$count>=$places)fail('The final podium is closed',409);
 }
 $p=&progress($t,$n);$p['finished_at']=stamp();$e['finish_counter']=($e['finish_counter']??0)+1;$p['finish_order']=$e['finish_counter'];
 $p['finish_elapsed_ms']=max(0,$p['finished_at']-$e['rounds'][$n]['started_at']-$e['rounds'][$n]['paused_ms']);
 $p['status']=$n===$final?'FINISHED':'QUALIFIED';
 if($n===$final){$p['podium_place']=$count+1;$db->audit('authority','PODIUM_AWARDED',['run_id'=>$e['run_id']??'','team_id'=>$t['id'],'team_name'=>$t['name'],'place'=>$p['podium_place'],'finished_at'=>$p['finished_at'],'finish_elapsed_ms'=>$p['finish_elapsed_ms']]);if($p['podium_place']===$places)endRoundV3($db,$e,$final,'PODIUM_COMPLETE',$t['id']);}
 if($n<$final&&$e['rounds'][$n]['qualify_mode']==='first_n'){
  $qualified=1;foreach($db->teams() as $other)if($other['id']!==$t['id']&&progress($other,$n)['status']==='QUALIFIED'&&empty($other['data']['eliminated_at']))$qualified++;
  if($qualified>=$e['rounds'][$n]['qualify_limit'])endRoundV3($db,$e,$n,'QUALIFYING_LIMIT',$t['id']);
 }
}
function roundStartCheck(Database $db,array $e,int $n): array {
 if(!in_array($n,roundNumbers($e),true))return ['ready'=>false,'eligible_count'=>0,'blockers'=>['This round is disabled']];
 $blockers=[];$eligible=[];
 if($e['rounds'][$n]['status']!=='DRAFT')$blockers[]='Round is already active or finished';
 if($n>1&&$e['rounds'][$n-1]['status']!=='ENDED')$blockers[]='Stop round '.($n-1).' first';
 foreach(readiness($e) as $issue)$blockers[]=$issue;
 foreach($db->teams() as $t){$d=$t['data'];if(!empty($d['eliminated_at'])||($n>1&&progress($t,$n-1)['status']!=='QUALIFIED'))continue;
  $eligible[]=$t['id'];$reasons=[];
  if(empty($d['device_id']))$reasons[]='Log in on the team phone';
  else {
   if(empty($d['ready']))$reasons[]='Allow Camera and precise GPS; turn Location on';
   if(($d['pending_actions']??0)>0)$reasons[]='Wait for pending uploads';
   if(!empty($d['needs_review']))$reasons[]='Resolve the saved evidence review';
   if(($d['lives_remaining']??3)<=0)$reasons[]='Wait for heart recovery or restore hearts';
   if(stamp()-($d['last_seen']??0)>45000)$reasons[]='Reconnect the team phone';
   if(($e['settings']['require_kiosk']??true)&&empty($d['kiosk']))$reasons[]='Managed kiosk is inactive; use Standard APK mode for an ordinary install';
   if(($d['client_version']??0)<18)$reasons[]='Install APK 2.13.0';
  }
  foreach($reasons as $reason)$blockers[]=$t['name'].' ('.$t['code'].'): '.$reason;
 }
 if(!$eligible)$blockers[]=$n===1?'Add a team first':'No teams qualified for this round';
 return ['ready'=>$blockers===[],'eligible_count'=>count($eligible),'blockers'=>$blockers];
}
function prepareRoundAssignments(Database $db,array &$e,int $n): void {
 $teams=$db->teams();$ids=[];foreach($teams as $t)if(empty($t['data']['eliminated_at'])&&progress($t,$n-1)['status']==='QUALIFIED')$ids[]=$t['id'];
 if(!$ids)fail('No qualified teams',409);$excluded=[];
 foreach($teams as $team)foreach(range(1,$n-1) as $before)$excluded[$team['id']]=array_merge($excluded[$team['id']]??[],progress($team,$before)['route']);
 $assigned=routesV3($e,$ids,$n,$excluded);
 foreach($teams as $t)if(isset($assigned[$t['id']])){$p=&progress($t,$n);$p['route']=$assigned[$t['id']]['riddles'];$db->saveTeam($t);unset($p);}
}
function roundControlV3(Database $db,array &$e,int $n,string $op,string $actor): array {
 $autoPublish=!$e['published']&&$op==='start'&&$n===1;
 if(!$e['published']&&!$autoPublish)fail('Publish the event first',409);
 if($autoPublish)publishAssignments($db,$e,$actor);
 $r=&$e['rounds'][$n];$teams=$db->teams();
 if($op==='prepare'){
  if($n===1||$r['status']!=='DRAFT'||$e['rounds'][$n-1]['status']!=='ENDED')fail('End the previous round before preparing assignments',409);
  prepareRoundAssignments($db,$e,$n);
 }elseif($op==='start'){
  $check=roundStartCheck($db,$e,$n);if(!$check['ready'])fail(implode("\n",$check['blockers']),409);
  if($n>1){$needsPreparation=false;foreach($teams as $t)if(empty($t['data']['eliminated_at'])&&progress($t,$n-1)['status']==='QUALIFIED'&&count(progress($t,$n)['route'])!==roundTotal($e,$n))$needsPreparation=true;
   if($needsPreparation){prepareRoundAssignments($db,$e,$n);$teams=$db->teams();}
  }
  $eligible=[];foreach($teams as $t){if(!empty($t['data']['eliminated_at'])||($n>1&&progress($t,$n-1)['status']!=='QUALIFIED'))continue;
   $p=&progress($t,$n);$d=$t['data'];if(count($p['route'])!==roundTotal($e,$n))fail('Prepare assignments first',409);
   $p['status']='PLAYING';$db->saveTeam($t);$eligible[]=$t['id'];unset($p);
  }if(!$eligible)fail('No eligible teams',409);$r['status']='RUNNING';$r['started_at']=stamp()+($e['settings']['start_countdown_seconds']??30)*1000;$r['control_version']++;
 }elseif($op==='pause'){
  if($r['status']!=='RUNNING')fail('Round is not running',409);$r['status']='PAUSED';$r['paused_at']=stamp();$r['control_version']++;
 }elseif($op==='resume'){
  if($r['status']!=='PAUSED')fail('Round is not paused',409);$r['pause_intervals'][]=[$r['paused_at'],stamp()];$r['paused_ms']+=stamp()-$r['paused_at'];$r['paused_at']=null;$r['status']='RUNNING';$r['control_version']++;
 }else{
  if(!in_array($r['status'],['RUNNING','PAUSED'],true))fail('Round is not active',409);endRoundV3($db,$e,$n,'ORGANIZER_ENDED');
 }
 $e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'ROUND_'.strtoupper($op),['round'=>$n]);return ['ok'=>true,'round'=>snapshot($r)];
}
function leaderboardV3(Database $db,array $e): array {
 $n=activeRound($e);$rows=[];$clues=array_column($e['riddles'],null,'id');
 foreach($db->teams() as $t){$p=progress($t,$n);$d=$t['data'];$status=!empty($d['eliminated_at'])?'ELIMINATED':(!empty($d['cooldown_until'])&&stamp()<$d['cooldown_until']?'COOLDOWN':$p['status']);
  $solved=[];foreach(array_slice($p['route'],0,$p['completed']) as $rid)$solved[]=['id'=>$rid,'title'=>$clues[$rid]['title']??'Riddle'];
  $rows[]=['team'=>$t['name'],'code'=>$t['code'],'round'=>$n,'completed'=>$p['completed'],'total'=>roundTotal($e,$n),'solved'=>$solved,
   'status'=>$status,'score'=>$p['score'],'finished_at'=>$p['finished_at'],'finish_elapsed_ms'=>$p['finish_elapsed_ms']??null,'finish_order'=>$p['finish_order']??null,'podium_place'=>$p['podium_place']??null,
   'lives_remaining'=>$d['lives_remaining']??3,'cooldown_until'=>$d['cooldown_until']??null,'last_seen'=>$d['last_seen']??0];
 }
 $group=fn($s)=>in_array($s,['FINISHED','QUALIFIED'],true)?0:(in_array($s,['ELIMINATED','DISQUALIFIED'],true)?2:1);
 usort($rows,fn($a,$b)=>[$group($a['status']),$a['finish_order']??PHP_INT_MAX,-$a['completed'],$a['code']]<=>[$group($b['status']),$b['finish_order']??PHP_INT_MAX,-$b['completed'],$b['code']]);
 $place=0;$previous=null;foreach($rows as $i=>&$row){$tie=[$group($row['status']),$row['finish_order'],$row['completed']];$row['rank']=$i&&$tie===$previous?$rows[$i-1]['rank']:$i+1;
  if($n===finalRound($e)&&$row['status']==='FINISHED'){$row['podium_place']??=$place+1;$place=max($place,$row['podium_place']);$row['rank']=$row['podium_place'];}else $row['podium_place']=null;
  $row['outcome']=match($row['podium_place']){1=>'WINNER',2=>'SECOND_PLACE',3=>'THIRD_PLACE',default=>$row['status']};$previous=$tie;
 }unset($row);
 return ['revision'=>$e['leaderboard_revision'],'as_of'=>stamp(),'event'=>$e['name'],'round'=>$n,'round_state'=>snapshot($e['rounds'][$n]),'rows'=>$rows];
}
