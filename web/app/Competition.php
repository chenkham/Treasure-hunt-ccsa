<?php
declare(strict_types=1);
namespace Hunt;
function teamAvatars(Database $db,array $teams): array {
 $used=[];$missing=[];
 foreach($teams as $i=>$team){$id=$team['data']['avatar_id']??null;if(is_int($id)&&$id>=0&&!isset($used[$id]))$used[$id]=true;else $missing[]=$i;}
 $next=0;foreach($missing as $i){while(isset($used[$next]))$next++;$teams[$i]['data']['avatar_id']=$next;$used[$next]=true;$db->saveTeam($teams[$i]);}
 return $teams;
}
function cooldownPolicy(array $e): int { return (int)($e['settings']['life_cooldown_ms']??0); }
function refreshHearts(Database $db,array &$e,array &$t): void {
 $d=&$t['data'];
 if(!empty($d['cooldown_until'])&&stamp()>=$d['cooldown_until']&&empty($d['eliminated_at'])) {
  $d['lives_remaining']=3;unset($d['cooldown_until']);$e['leaderboard_revision']++;
  $db->audit($t['code'],'HEARTS_RESET',['lives'=>3]);
 }
}
function rememberLocation(array &$data,array $point): void {
 $history=$data['location_history']??[];$history[]=$point;
 $data['location_history']=array_slice(array_values(array_filter($history,fn($p)=>$p['received_at']>=stamp()-360000)),-80);
 $data['location']=$point;
}
function opponentSnapshot(Database $db,array $e,string $own): array {
 $points=[];$now=stamp();$mode=$e['settings']['opponent_map']??'off';
 if(in_array($mode,['coarse','live'],true))foreach($db->teams() as $team) {
  if($team['id']===$own||empty($team['data']['device_id']))continue;
  if($mode==='live'){
   $p=$team['data']['location']??null;if(!$p||($p['mock']??false))continue;
   $sample=(int)($p['sampled_at']??$p['received_at']);if($now-$sample>300000)continue;
   $points[]=['code'=>$team['code'],'name'=>$team['name'],'avatar_id'=>$team['data']['avatar_id']??0,'lat'=>round($p['lat'],7),'lon'=>round($p['lon'],7),
    'accuracy'=>$p['accuracy'],'radius_m'=>$p['accuracy'],'as_of'=>$sample,'stale'=>$now-$sample>30000];continue;
  }
  $chosen=null;foreach($team['data']['location_history']??[] as $p)if($p['received_at']<=$now-30000)$chosen=$p;
  if(!$chosen||$now-$chosen['received_at']>300000)continue;
  // Round on the authority. Exact coordinates never enter the participant reply.
  $stepLat=100/111320;$stepLon=100/(111320*cos(deg2rad(27.45)));
  $points[]=['code'=>$team['code'],'name'=>$team['name'],'avatar_id'=>$team['data']['avatar_id']??0,
   'lat'=>round($chosen['lat']/$stepLat)*$stepLat,'lon'=>round($chosen['lon']/$stepLon)*$stepLon,
   'radius_m'=>100,'as_of'=>$chosen['received_at'],'stale'=>$now-$chosen['received_at']>75000];
 }
 $revision=hash('sha256',json([$mode,$points]));
 return ['revision'=>$revision,'mode'=>$mode,'as_of'=>$now,'delay_seconds'=>$mode==='coarse'?30:0,'points'=>$points];
}
