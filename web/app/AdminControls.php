<?php
declare(strict_types=1);
namespace Hunt;

function upgradeContinuousDraft(Database $db,array &$e,string $actor): void {
 if(!sharedPool($e)||continuousHunt($e)||(($db->config['test_only']??false)&&($db->config['legacy_round_tests']??false)))return;
 foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')return;
 if((int)$db->sql("SELECT COUNT(*) FROM hunt_actions WHERE result NOT IN ('RECORDED','WRONG_RUN')")->fetchColumn()>0)return;
 $target=max(1,min(count($e['riddles']),array_sum(array_map(fn($n)=>roundTotal($e,$n),roundNumbers($e)))));
 unpublishAssignments($db,$e,$actor);
 $e['settings']=array_merge($e['settings'],['hunt_mode'=>'continuous-v1','target_riddles'=>$target,'round_count'=>1,'round_sizes'=>[1=>$target,2=>1,3=>1],'podium_places'=>3,'hints_enabled'=>false,'min_android_version'=>18]);
 $e['rounds'][1]['duration_ms']=0;$e['rounds'][1]['configured_duration_ms']=0;
 $db->saveEvent($e);$db->audit($actor,'CONTINUOUS_HUNT_ENABLED',['target_riddles'=>$target]);
}

function editableDraft(Database $db,array $e): void {
 foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')fail('This control locks when a round starts',409);
 if((int)$db->sql("SELECT COUNT(*) FROM hunt_actions WHERE result NOT IN ('RECORDED','WRONG_RUN')")->fetchColumn()>0)fail('Recorded play prevents draft changes',409);
}
function unpublishAssignments(Database $db,array &$e,string $actor): array {
 editableDraft($db,$e);$e['published']=false;$e['settings']['assignment_seed']=token();
 foreach($db->teams() as $team){$team['data']['rounds']=[];$team['data']['ready']=false;unset($team['data']['package_ack']);$db->sql('DELETE FROM hunt_progress WHERE team_id=?',[$team['id']]);$db->saveTeam($team);}
 $e['revision']++;$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'DRAFT_REOPENED',[]);return ['ok'=>true];
}
function updateLiveSettings(Database $db,array &$e,array $body,string $actor): array {
 if(($body['edit_revision']??null)!==($e['edit_revision']??$e['revision']))fail('Settings changed. Refresh and try again.',409);
 if(array_key_exists('start_countdown_seconds',$body)){
  $seconds=$body['start_countdown_seconds'];
  if(!is_int($seconds)||$seconds<0||$seconds>600)fail('Choose a start countdown from 0 to 600 seconds');
  foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')fail('The start countdown locks when play starts',409);
  $e['settings']['start_countdown_seconds']=$seconds;
 }
 if(continuousHunt($e)){
  if(array_intersect(['round_count','round_sizes','podium_places'],array_keys($body)))fail('Use total riddles in Hunt settings. The hunt awards three prizes.',409);
  $target=$body['target_riddles']??roundTotal($e,1);
  if(!is_int($target)||$target<1||$target>count($e['riddles']))fail('Choose a total from 1 to the number of available riddles');
  if($target!==roundTotal($e,1)){unpublishAssignments($db,$e,$actor);$e['settings']['target_riddles']=$target;$e['settings']['round_sizes'][1]=$target;}
 }
 if(array_intersect(['round_count','round_sizes','podium_places'],array_keys($body))){
  $count=$body['round_count']??count(roundNumbers($e));$sizes=$body['round_sizes']??$e['settings']['round_sizes']??[1=>3,2=>7,3=>2];$places=$body['podium_places']??prizePlaces($e);
  if(!sharedPool($e)||!is_int($count)||$count<1||$count>3||!is_int($places)||$places<1||$places>3||!is_array($sizes)||array_map('intval',array_keys($sizes))!==[1,2,3])fail('Choose 1–3 rounds and 1–3 prize places');
  foreach($sizes as $size)if(!is_int($size)||$size<1||$size>200)fail('Choose 1–200 riddles per round');
  if(array_sum(array_slice(array_values($sizes),0,$count))>count($e['riddles']))fail('The enabled rounds need more unique riddles than the pool contains');
  if($count!==count(roundNumbers($e))||$sizes!==($e['settings']['round_sizes']??[1=>3,2=>7,3=>2])||$places!==prizePlaces($e)){
   unpublishAssignments($db,$e,$actor);$e=$db->event();
   $e['settings']['round_count']=$count;$e['settings']['round_sizes']=$sizes;$e['settings']['podium_places']=$places;$e['settings']['min_android_version']=17;
  }
 }
 $name=trim((string)($body['name']??$e['name']));$phone=trim((string)($body['organizer_phone']??''));$mode=$body['opponent_map']??'';
 if($name===''||mb_strlen($name)>200||mb_strlen($phone)>100||!in_array($mode,['off','coarse','live'],true)||!is_bool($body['public_leaderboard']??null))fail('Enter valid event settings');
 if($phone!==''&&(!preg_match('/^\+?[0-9][0-9 ()-]{2,24}$/',$phone)||!preg_match('/^\+?[0-9]{3,25}$/',preg_replace('/[ ()-]/','',$phone))))fail('Enter a valid helpline number or leave it empty');
 $e['name']=$name;$e['settings']['organizer_phone']=$phone;$e['settings']['opponent_map']=$mode;$e['public_leaderboard']=$body['public_leaderboard'];
 foreach(['require_kiosk','life_cooldown_ms'] as $key)if(array_key_exists($key,$body)){
  foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')fail('Phone mode and heart recovery lock when play starts',409);
  if($key==='require_kiosk'&&!is_bool($body[$key]))fail('Choose Standard APK or Managed kiosk');
  if($key==='life_cooldown_ms'&&!in_array($body[$key],[0,900000],true))fail('Choose 15-minute heart recovery or elimination');
  $e['settings'][$key]=$body[$key];
 }
 $e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'LIVE_SETTINGS_UPDATED',['opponent_map'=>$mode]);return ['ok'=>true];
}
function updateRoundSettings(Database $db,array &$e,int $number,array $body,string $actor): array {
 if(!in_array($number,roundNumbers($e),true))fail('This round is disabled',409);
 $round=$e['rounds'][$number];$active=in_array($round['status'],['RUNNING','PAUSED'],true);
 if($round['status']==='ENDED')fail('A finished round cannot be edited',409);
 if(($body['edit_revision']??null)!==($e['edit_revision']??$e['revision']))fail('Settings changed. Refresh and try again.',409);
 $candidate=$e['rounds'];
 $elapsed=empty($round['started_at'])?0:max(0,($round['paused_at']??stamp())-$round['started_at']-$round['paused_ms']);
 if($active){
  if(isset($body['remaining_minutes'])){if(!is_int($body['remaining_minutes'])||$body['remaining_minutes']<1||$body['remaining_minutes']>240)fail('Choose 1–240 minutes remaining');$body['duration_ms']=$elapsed+$body['remaining_minutes']*60000;}
  if(($body['qualify_mode']??$round['qualify_mode'])!==$round['qualify_mode']||($body['qualify_limit']??$round['qualify_limit'])!==$round['qualify_limit'])fail('Qualification rules lock when the round starts',409);
  if(!is_int($body['duration_ms']??null)||($body['duration_ms']!==0&&($body['duration_ms']<=$elapsed||$body['duration_ms']>$elapsed+14400000)))fail('Choose 1–240 minutes remaining or no limit. Use Stop to end a round.');
 }
 foreach(['duration_ms','qualify_mode','qualify_limit'] as $key)$candidate[$number][$key]=$body[$key]??$round[$key];validateRoundSettings($candidate);
 $candidate[$number]['configured_duration_ms']=$active&&$body['duration_ms']!==0?$body['duration_ms']-$elapsed:$candidate[$number]['duration_ms'];
 $e['rounds']=$candidate;$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'ROUND_SETTINGS_UPDATED',['round'=>$number]);return ['ok'=>true];
}
function extendRound(Database $db,array &$e,int $number,array $body,string $actor): array {
 $minutes=$body['minutes']??null;$round=&$e['rounds'][$number];
 if(!is_int($minutes)||$minutes<1||$minutes>60||$round['duration_ms']===0||!in_array($round['status'],['RUNNING','PAUSED'],true)||$round['duration_ms']+$minutes*60000>14400000)fail('Add 1–60 minutes to a timed active round, up to 240 minutes total');
 $round['duration_ms']+=$minutes*60000;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'ROUND_EXTENDED',['round'=>$number,'minutes'=>$minutes]);return ['ok'=>true];
}
function shortenHunt(Database $db,array &$e,array $body,string $actor): array {
 if(($body['edit_revision']??null)!==($e['edit_revision']??$e['revision']))fail('Settings changed. Refresh and try again.',409);
 $n=$body['round']??null;$reason=trim((string)($body['reason']??''));
 if(!threeRound($e)||!is_int($n)||$n<1||$n>=finalRound($e)||strlen($reason)<8)fail('Choose an earlier final round and enter an audit reason');
 foreach($e['rounds'] as $number=>$round)if($number>$n&&$round['status']!=='DRAFT')fail('A later round has already started',409);
 $e['settings']['round_count']=$n;$e['settings']['min_android_version']=17;
 $finishers=[];foreach($db->teams() as $team){$p=progress($team,$n);if($p['status']==='QUALIFIED'&&empty($team['data']['eliminated_at']))$finishers[]=$team;}
 if(!$finishers&&$e['rounds'][$n]['status']==='ENDED')fail('This round has no confirmed finishers. Choose a later final round.',409);
 usort($finishers,fn($a,$b)=>[progress($a,$n)['finish_order']??PHP_INT_MAX,progress($a,$n)['finished_at'],$a['code']]<=>[progress($b,$n)['finish_order']??PHP_INT_MAX,progress($b,$n)['finished_at'],$b['code']]);
 foreach($finishers as $i=>$team){$p=&progress($team,$n);
  if($i<prizePlaces($e)){$p['status']='FINISHED';$p['podium_place']=$i+1;}
  else{$p['status']='ELIMINATED';$team['data']['eliminated_at']=stamp();$team['data']['elimination_reason']='PODIUM_COMPLETE';}
  $db->saveTeam($team);unset($p);
 }
 if(count($finishers)>=prizePlaces($e)||$e['rounds'][$n]['status']==='ENDED')endRoundV3($db,$e,$n,'PODIUM_COMPLETE');
 $e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$db->saveEvent($e);
 $db->audit($actor,'FINAL_ROUND_CHANGED',['round'=>$n,'reason'=>$reason]);return ['ok'=>true];
}
function resetHunt(Database $db,array &$e,array $body,string $actor): array {
 if(staffProfile($db,$actor)['role']!=='owner')fail('Only the owner can reset the hunt',403);
 $hash=$db->sql('SELECT password FROM hunt_admins WHERE username=?',[$actor])->fetchColumn();
 if(!password_verify((string)($body['password']??''),$hash))fail('Enter your current admin password',403);
 if(($body['confirm']??'')!=='RESET'||!is_bool($body['keep_teams']??null))fail('Type RESET to confirm');
 if(($body['edit_revision']??null)!==($e['edit_revision']??$e['revision']))fail('Event changed. Refresh before resetting.',409);
 foreach($e['rounds'] as $round)if(in_array($round['status'],['RUNNING','PAUSED'],true))fail('Stop the active round before resetting',409);
 $teams=$db->teams();$snapshot=['event'=>$e,'teams'=>$teams,'created_at'=>stamp()];
 // ponytail: one campus event fits in memory; stream snapshots if the evidence log grows beyond PHP's memory limit.
 foreach(['hunt_actions','hunt_progress','hunt_life_events','hunt_commands','hunt_audit'] as $table)$snapshot[$table]=$db->sql("SELECT * FROM $table")->fetchAll();
 $id='reset-'.uid();$file=rtrim($db->config['private_dir'],'/\\').'/'.$id.'.json';
 $encoded=json($snapshot);$temporary=$file.'.tmp';
 try{if(file_put_contents($temporary,$encoded,LOCK_EX)!==strlen($encoded))throw new \RuntimeException('Cannot save the reset backup');@chmod($temporary,0600);if(!rename($temporary,$file))throw new \RuntimeException('Cannot finish the reset backup');}
 finally{if(is_file($temporary))unlink($temporary);}
 foreach(['hunt_life_events','hunt_actions','hunt_progress','hunt_commands'] as $table)$db->sql("DELETE FROM $table");
 if(!$body['keep_teams']){$db->sql("DELETE FROM hunt_sessions WHERE kind='team'");$db->sql('DELETE FROM hunt_teams');}
 else foreach($teams as $team){
  $keep=array_intersect_key($team['data'],array_flip(['avatar_id','device_id','public_key','exit_verifier','login_secret','last_seq','last_hash','location','location_history','last_seen','kiosk']));
  $team['data']=$keep+['rounds'=>[],'lives_remaining'=>3,'hints_used'=>[],'ready'=>false];$db->saveTeam($team);
 }
 $rounds=defaultRounds();foreach($rounds as $n=>&$round)foreach(['duration_ms','qualify_mode','qualify_limit'] as $key)$round[$key]=$key==='duration_ms'?($e['rounds'][$n]['configured_duration_ms']??$e['rounds'][$n][$key]):$e['rounds'][$n][$key];unset($round);
 $e['rounds']=$rounds;$e['run_id']=uid();$e['published']=false;$e['finish_counter']=0;$e['revision']++;$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$e['settings']['assignment_seed']=token();
 $e['last_backup']=$id;$db->saveEvent($e);upgradeContinuousDraft($db,$e,$actor);$db->audit($actor,'HUNT_RESET',['backup'=>$id,'kept_teams'=>$body['keep_teams']]);return ['ok'=>true,'backup_id'=>$id];
}
function downloadResetBackup(Database $db,string $id): BinaryReply {
 $file=rtrim($db->config['private_dir'],'/\\').'/'.$id.'.json';if(!is_file($file))fail('Backup not found',404);
 return new BinaryReply((string)file_get_contents($file),'application/json',$id.'.json');
}
