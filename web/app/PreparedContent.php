<?php
declare(strict_types=1);
namespace Hunt;

/** Called only inside an authenticated owner's login transaction. */
function importPreparedContent(Database $db,string $actor): array {
 $seed=parse(file_get_contents(dirname(__DIR__).'/database/seed.json'));$version=(string)($seed['content_version']??'');
 if($version==='')return ['status'=>'none'];
 $e=$db->event();upgradeContinuousDraft($db,$e,$actor);
 if(empty($e['named_live_teams'])){
  $e['named_live_teams']=true;$e['settings']['opponent_map']='live';$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;
  $db->saveEvent($e);$db->audit($actor,'LIVE_TEAM_MAP_ENABLED',[]);
 }
 if(($e['content_bundle']??'')===$version)return ['status'=>'current','count'=>count($e['riddles'])];
 $blocked=['status'=>'blocked','message'=>'The workbook update was not applied because this event has already started. Existing results were preserved.'];
 foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')return $blocked;
 if((int)$db->sql("SELECT COUNT(*) FROM hunt_actions WHERE result NOT IN ('RECORDED','WRONG_RUN')")->fetchColumn()>0)return $blocked;
 $teams=$db->teams();foreach($teams as $team)foreach($team['data']['rounds']??[] as $round)if(($round['completed']??0)>0)return $blocked;
 $backup=['event'=>$e,'teams'=>$teams];$path=rtrim($db->config['private_dir'],'/\\').'/before-workbook-'.$e['id'].'-'.$e['revision'].'.json';
 if(!is_file($path)){if(file_put_contents($path,json($backup),LOCK_EX)===false)throw new \RuntimeException('Cannot back up the draft before importing riddles');@chmod($path,0600);}
 $e['name']='Anazori Treasure Hunt';$e['settings']=array_merge($e['settings'],$seed['settings']);$e['settings']['assignment_seed']??=token();
 $e['riddles']=$seed['riddles'];$e['rounds']=array_replace_recursive(defaultRounds(),$e['rounds']);
 prepareV3Content($e,[]);$e['published']=false;$e['protocol']=3;$e['content_bundle']=$version;$e['revision']++;$e['leaderboard_revision']++;
 foreach($teams as $team){$team['data']['rounds']=[];foreach([1,2,3] as $n)progress($team,$n);$team['data']['ready']=false;unset($team['data']['package_ack']);$db->saveTeam($team);}
 $db->saveEvent($e);$db->audit($actor,'WORKBOOK_IMPORTED',['count'=>count($e['riddles']),'version'=>$version]);
 return ['status'=>'imported','count'=>count($e['riddles'])];
}
