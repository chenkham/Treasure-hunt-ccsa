<?php
declare(strict_types=1);
// Local CLI integration only. Does not expose an HTTP service or bypass the production runtime guard.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/Install.php';
use Hunt\Api;use Hunt\Database;use Hunt\HttpError;
$root=dirname(__DIR__,2);$base=require $root.'/runtime/php-mysql/private/config.php';
if(($base['test_only']??false)!==true||!str_starts_with($base['dsn'],'mysql:host=127.0.0.1;port=3339;dbname=hunt_local'))throw new RuntimeException('Disposable loopback fixture required');
$schema='hunt_local_ready_'.bin2hex(random_bytes(5));$server=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','');
$server->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$directory=$root.'/runtime/event-check-'.$schema;mkdir($directory,0700,true);
$config=['dsn'=>"mysql:host=127.0.0.1;port=3339;dbname=$schema;charset=utf8mb4",'username'=>'root','password'=>'','private_dir'=>$directory,'test_only'=>true,'legacy_round_tests'=>true];
$checks=0;$teams=[];
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function denied(callable $request,int $status=403): void {try{$request();throw new RuntimeException('An unauthorized action succeeded');}catch(HttpError $x){check($x->status===$status,'Unexpected rejection status: '.$x->getMessage());}}
try{
 \Hunt\install($config,'TestOwner','Test-Owner-Only-2026!');$db=new Database($config);$public=new Api($db,'','test-local');
 $login=$public->dispatch('POST','/api/admin/login',['username'=>'TestOwner','password'=>'Test-Owner-Only-2026!']);$owner=new Api($db,'Bearer '.$login['token'],'test-local');
 $overview=$owner->dispatch('GET','/api/admin/overview',[]);$event=$overview['event'];
 check(count($event['riddles'])===40&&count($event['checkpoints'])===40,'Workbook import count');
 check(\Hunt\readiness($event)===[],'Prepared event readiness');
 check(count(array_filter($event['riddles'],fn($r)=>$r['difficulty']==='neutral'))===40,'No difficulty labels');
 check(!str_contains(\Hunt\json(array_column($event['riddles'],'question')),'উত্তৰ:'),'Answer line must be private');
 foreach($event['riddles'] as $r)check(!str_contains($r['title'],$r['placement']),'Titles must not reveal destinations');
 $ids=array_map(fn($n)=>'test-team-'.$n,range(1,1000));$excluded=[];$distribution=[];
 foreach([1=>3,2=>7,3=>2] as $n=>$count){
  $assigned=\Hunt\routesV3($event,$ids,$n,$excluded);$starts=[];
  foreach($assigned as $id=>$route){check(count($route['riddles'])===$count,'Round assignment size');check(!array_intersect($route['riddles'],$excluded[$id]??[]),'A team received a repeated riddle');
   $excluded[$id]=array_merge($excluded[$id]??[],$route['riddles']);$first=$route['riddles'][0];$starts[$first]=($starts[$first]??0)+1;
  }
  $distribution[$n]=['teams'=>count($assigned),'starting_stations'=>count($starts),'min'=>min($starts),'max'=>max($starts)];
  check(max($starts)-min($starts)<=2,'Starting-station distribution is imbalanced');
 }
 $again=\Hunt\routesV3($event,array_reverse($ids),1);check($again===\Hunt\routesV3($event,$ids,1),'Shuffle must be reproducible regardless of input order');
 foreach(['organizer','volunteer'] as $role)$owner->dispatch('POST','/api/admin/staff',['username'=>'test_'.$role,'password'=>'Test-Staff-Only-2026!','role'=>$role,'active'=>true,'email'=>'']);
 $login=$public->dispatch('POST','/api/admin/login',['username'=>'test_volunteer','password'=>'Test-Staff-Only-2026!']);$volunteer=new Api($db,'Bearer '.$login['token'],'test-volunteer');
 $view=$volunteer->dispatch('GET','/api/admin/overview',[]);check($view['event']['riddles']===[]&&!str_contains(\Hunt\json($view),'assignment_seed'),'Volunteer data privacy');
 foreach([['GET','/api/admin/staff'],['GET','/api/admin/print/organizer'],['GET','/api/admin/export'],['PUT','/api/admin/content'],['POST','/api/admin/teams'],['POST','/api/admin/teams/'.\Hunt\uid().'/login-check'],['POST','/api/admin/import-evidence'],['POST','/api/admin/shuffle'],['POST','/api/admin/rounds/1/start']] as [$method,$path])denied(fn()=>$volunteer->dispatch($method,$path,[]));
 $login=$public->dispatch('POST','/api/admin/login',['username'=>'test_organizer','password'=>'Test-Staff-Only-2026!']);$organizer=new Api($db,'Bearer '.$login['token'],'test-organizer');
 denied(fn()=>$organizer->dispatch('POST','/api/admin/staff',[]));denied(fn()=>$organizer->dispatch('PUT','/api/admin/account/mail',[]));
 $owner->dispatch('PUT','/api/admin/staff/test_volunteer',['role'=>'volunteer','active'=>false,'email'=>'']);
 denied(fn()=>$volunteer->dispatch('GET','/api/admin/overview',[]),401);
 denied(fn()=>$public->dispatch('POST','/api/admin/login',['username'=>'test_volunteer','password'=>'Test-Staff-Only-2026!']));
 denied(fn()=>$owner->dispatch('PUT','/api/admin/staff/TestOwner',['role'=>'volunteer','active'=>false,'email'=>'']),409);
 $before=array_column($event['checkpoints'],'nonce','id');
 $stationReceipts=[];foreach($event['checkpoints'] as $checkpoint)$stationReceipts[$checkpoint['id']]=\Hunt\signed($db->key,['event_id'=>$event['id'],'checkpoint_id'=>$checkpoint['id'],'nonce'=>$checkpoint['nonce']]);
 check(array_keys(\Hunt\verify($db->public,reset($stationReceipts)))===['event_id','checkpoint_id','nonce'],'Station QR must contain only station identity, not a next clue');
 $login=$public->dispatch('POST','/api/admin/login',['username'=>'TestOwner','password'=>'Test-Owner-Only-2026!']);
 check($login['content_import']['status']==='current','Import must be idempotent');
 check(array_column($owner->dispatch('GET','/api/admin/overview',[])['event']['checkpoints'],'nonce','id')===$before,'Re-login changed printed QR codes');
 $testPin="Test&'<>110406!"; // Disposable fixture, never a production credential.
 $only=$owner->dispatch('POST','/api/admin/teams',['code'=>'110406','name'=>'blitz','pin'=>$testPin]);
 $credentialPath='/api/admin/teams/'.$only['id'].'/credentials';
 $credentials=$organizer->dispatch('GET',$credentialPath,[]);
 check($credentials['name']==='blitz'&&$credentials['code']==='110406'&&$credentials['enrollment_pin']===$testPin,'Organizer could not view the exact saved login');
 check(!str_contains(\Hunt\json($db->team($only['id'])['data']),$testPin),'Recoverable team codes must be encrypted at rest');
 denied(fn()=>$volunteer->dispatch('GET',$credentialPath,[]),401);
 $checkPath='/api/admin/teams/'.$only['id'].'/login-check';
 check($owner->dispatch('POST',$checkPath,['pin'=>$testPin])['valid'],'An exact saved login code did not verify');
 check(!$owner->dispatch('POST',$checkPath,['pin'=>$only['offline_exit_pin']])['valid'],'Organizer exit PIN must not log in a team');
 check(!$owner->dispatch('POST',$checkPath,['pin'=>'incorrect'])['valid'],'An incorrect login code was accepted');
 check(!isset($db->team($only['id'])['data']['device_id'])&&(int)$db->sql("SELECT COUNT(*) FROM hunt_sessions WHERE kind='team'")->fetchColumn()===0,'Checking credentials must not enroll a browser as a phone');
 $owner->dispatch('PUT','/api/admin/teams/'.$only['id'],['password'=>$testPin.'2']);
 check(!$owner->dispatch('POST',$checkPath,['pin'=>$testPin])['valid']&&$organizer->dispatch('POST',$checkPath,['pin'=>$testPin.'2'])['valid'],'Updated team codes do not match the enrollment credential check');
 $testPin.='2';
 check($owner->dispatch('GET',$credentialPath,[])['enrollment_pin']===$testPin,'Edited login code was not updated for viewing');
 $legacy=$db->team($only['id']);unset($legacy['data']['login_secret']);$db->saveTeam($legacy);
 check($owner->dispatch('GET',$credentialPath,[])['enrollment_pin']===null,'A legacy one-way hash cannot be reversed');
 $owner->dispatch('POST',$checkPath,['pin'=>$testPin]);check($owner->dispatch('GET',$credentialPath,[])['enrollment_pin']===$testPin,'Validating a legacy code must save its encrypted copy');
 $onlyKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$onlyDevice=\Hunt\uid();
 $phoneLogin=['code'=>'110406','pin'=>$testPin,'client_version'=>18,'device_id'=>$onlyDevice,'public_key'=>preg_replace('/-----[^-]+-----|\s/','',openssl_pkey_get_details($onlyKey)['key'])];
 denied(fn()=>$public->dispatch('POST','/api/enroll',array_replace($phoneLogin,['client_version'=>17])),426);
 denied(fn()=>$public->dispatch('POST','/api/enroll',array_replace($phoneLogin,['code'=>'blitz'])),401);
 denied(fn()=>$public->dispatch('POST','/api/enroll',array_replace($phoneLogin,['pin'=>$only['offline_exit_pin']])),401);
 $enrollment=$public->dispatch('POST','/api/enroll',$phoneLogin);
 $enrollment=$public->dispatch('POST','/api/enroll',$phoneLogin);
 check(\Hunt\verify($db->public,$enrollment['package'])['device_id']===$onlyDevice,'The same phone must be allowed to log in again');
 denied(fn()=>$public->dispatch('POST','/api/enroll',array_replace($phoneLogin,['device_id'=>\Hunt\uid()])),409);
 $oldToken=$enrollment['token'];$expired=new Api($db,'Bearer '.$oldToken,'expired-phone');
 $db->sql('UPDATE hunt_sessions SET expires=? WHERE hash=?',[\Hunt\stamp()-1,hash('sha256',$oldToken)]);
 denied(fn()=>$expired->dispatch('POST','/api/sync',[]),401);
 $proof=['kind'=>'RECONNECT','device_id'=>$onlyDevice,'session_hash'=>hash('sha256',$oldToken)];
 $attacker=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
 denied(fn()=>$expired->dispatch('POST','/api/reconnect',['proof'=>\Hunt\signed($attacker,$proof)]),401);
 $resumed=$expired->dispatch('POST','/api/reconnect',['proof'=>\Hunt\signed($onlyKey,$proof)]);
 check($resumed['token']!==$oldToken,'Expired device session was not renewed');
 denied(fn()=>$expired->dispatch('POST','/api/reconnect',['proof'=>\Hunt\signed($onlyKey,$proof)]),401);
 $enrollment['token']=$resumed['token'];
 check(!str_contains(\Hunt\json($enrollment),'login_secret')&&!isset(\Hunt\verify($db->public,$enrollment['package'])['exit_verifier']),'Participant data contains organizer-only credentials');
 check($owner->dispatch('POST',$checkPath,['pin'=>$testPin])['phone_enrolled'],'The credential check did not detect the real enrolled phone');
 $lobby=\Hunt\verify($db->public,$enrollment['package']);check($lobby['riddles']===[]&&$lobby['routes']['1']===[],'Unpublished login must be a lobby without clues');
 check(count($enrollment['leaderboard']['rows'])===1&&$enrollment['rounds'][1]['status']==='DRAFT','Login must include lobby standings');
 denied(fn()=>$owner->dispatch('POST','/api/admin/rounds/1/start',[]),409);check(!$db->event()['published'],'A failed start must not partially publish assignments');
 $phone=new Api($db,'Bearer '.$enrollment['token'],'test-lobby');
 $resume=$owner->dispatch('POST','/api/admin/teams/'.$only['id'].'/control',['operation'=>'resume_kiosk','reason'=>'Local managed-device command check']);
 $command=\Hunt\parse($db->sql('SELECT data FROM hunt_commands WHERE id=?',[$resume['command_id']])->fetchColumn());check($resume['delivery']==='PENDING'&&$command['kind']==='RESUME_KIOSK','Dashboard cannot resume a managed phone after remote exit');
 $phone->dispatch('POST','/api/sync',['client_version'=>18,'device_id'=>$onlyDevice,'events'=>[],'ready'=>true,'kiosk'=>false,'package_hash'=>$enrollment['package_hash']]);
 $check=$owner->dispatch('GET','/api/admin/overview',[])['start_checks'][1];check(!$check['ready']&&str_contains(implode(' ',$check['blockers']),'Managed kiosk'),'A standard phone needs an actionable start reason');
 denied(fn()=>$owner->dispatch('POST','/api/admin/rounds/1/start',[]),409);
 $owner->dispatch('POST','/api/admin/publish',[]);$e=$db->event();
 $owner->dispatch('PUT','/api/admin/live-settings',['edit_revision'=>$e['edit_revision']??$e['revision'],'name'=>$e['name'],'organizer_phone'=>'','opponent_map'=>'live','public_leaderboard'=>true,'require_kiosk'=>false,'life_cooldown_ms'=>900000]);
 check($owner->dispatch('GET','/api/admin/overview',[])['start_checks'][1]['ready'],'A single connected standard phone should be startable after publication');
 $start=$owner->dispatch('POST','/api/admin/rounds/1/start',[])['round'];
 check($db->event()['published']&&$start['starts_in_ms']>29000&&$start['remaining_ms']===3600000,'Start should schedule a shared future start');
 $early=$db->team($only['id']);$e=$db->event();$result=\Hunt\applyAction($db,$e,$early,['kind'=>'SCAN','event_id'=>$e['id'],'revision'=>$e['revision'],'round'=>1]);
 check($result==='NOT_STARTED'&&$early['data']['lives_remaining']===3&&$early['data']['rounds'][1]['completed']===0,'An early scan must not score or lose hearts');
 // Reset only this disposable fixture after exercising automatic publication.
 $db->begin();$e=$db->event();$e['rounds']=\Hunt\defaultRounds();$db->saveEvent($e);$db->commit();
 $owner->dispatch('DELETE','/api/admin/teams/'.$only['id'],[]);
 check(!$db->event()['published']&&$db->teams()===[],'Removing the last team must return to draft');
 for($i=0;$i<4;$i++){
  $created=$owner->dispatch('POST','/api/admin/teams',['code'=>'READY'.$i,'name'=>'Local test '.$i,'pin'=>'Test-Team-Pass']);
  $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
  $teams[]=['id'=>$created['id'],'code'=>'READY'.$i,'device'=>\Hunt\uid(),'key'=>$key,'seq'=>0,'previous'=>'','package_hash'=>'','elapsed'=>0];
 }
 foreach($teams as &$team){
  $early=$public->dispatch('POST','/api/enroll',['code'=>$team['code'],'pin'=>'Test-Team-Pass','client_version'=>18,'device_id'=>$team['device'],'public_key'=>preg_replace('/-----[^-]+-----|\s/','',openssl_pkey_get_details($team['key'])['key'])]);
  check(count($early['leaderboard']['rows'])===4,'Lobby must show teams that have not logged in yet');
 }unset($team);
 $temporary=$owner->dispatch('POST','/api/admin/teams',['code'=>'REMOVE_ME','name'=>'Before start','pin'=>'Temporary-Team-Pass']);
 $owner->dispatch('PUT','/api/admin/teams/'.$temporary['id'],['name'=>'Edited team','password'=>'Edited-Team-Pass']);
 $row=$db->team($temporary['id']);check($row['name']==='Edited team'&&\Hunt\matches('Edited-Team-Pass',\Hunt\parse($row['pin'])),'Pre-event team editing');
 $tempKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$tempDevice=\Hunt\uid();
 $tempLogin=$public->dispatch('POST','/api/enroll',['code'=>'REMOVE_ME','pin'=>'Edited-Team-Pass','client_version'=>18,'device_id'=>$tempDevice,'public_key'=>preg_replace('/-----[^-]+-----|\s/','',openssl_pkey_get_details($tempKey)['key'])]);
 $incident=['id'=>\Hunt\uid(),'seq'=>1,'prev_hash'=>'','team_id'=>$temporary['id'],'device_id'=>$tempDevice,'event_id'=>$db->event()['id'],'revision'=>0,'kind'=>'INCIDENT','message'=>'Local lifecycle check'];
 $reply=(new Api($db,'Bearer '.$tempLogin['token'],'test-lifecycle'))->dispatch('POST','/api/sync',['client_version'=>18,'device_id'=>$tempDevice,'events'=>[\Hunt\signed($tempKey,$incident)]]);
 check($reply['ack'][0]['result']==='RECORDED','Lifecycle evidence must survive draft revision changes');
 $replacement=$owner->dispatch('POST','/api/admin/teams/'.$temporary['id'].'/control',['operation'=>'reset_device','reason'=>'Supervised local phone replacement']);
 check($owner->dispatch('GET','/api/admin/teams/'.$temporary['id'].'/credentials',[])['enrollment_pin']===$replacement['new_enrollment_pin'],'Replacement phone code was not saved for organizers');
 $revoked=new Api($db,'Bearer '.$tempLogin['token'],'revoked-phone');
 denied(fn()=>$revoked->dispatch('POST','/api/reconnect',['proof'=>\Hunt\signed($tempKey,['kind'=>'RECONNECT','device_id'=>$tempDevice,'session_hash'=>hash('sha256',$tempLogin['token'])])]),401);
 $owner->dispatch('DELETE','/api/admin/teams/'.$temporary['id'],[]);denied(fn()=>$db->team($temporary['id']),404);
 check((int)$db->sql('SELECT COUNT(*) FROM hunt_actions WHERE team_id=?',[$temporary['id']])->fetchColumn()===0,'A pre-start incident must not prevent team removal');
 $owner->dispatch('POST','/api/admin/publish',[]);
 $revision=$db->event()['revision'];$temporary=$organizer->dispatch('POST','/api/admin/teams',['code'=>'LATE_TEAM','name'=>'Late registration','pin'=>'Temporary-Team-Pass']);
 check($db->event()['revision']>$revision&&count($db->team($temporary['id'])['data']['rounds'][1]['route'])===3,'Published roster additions must assign riddles');
 $db->sql('INSERT INTO hunt_sessions VALUES(?,?,?,?)',[hash('sha256','temporary-team-token'),$temporary['id'],'team',\Hunt\stamp()+60000]);
 $organizer->dispatch('DELETE','/api/admin/teams/'.$temporary['id'],[]);
 check((int)$db->sql('SELECT COUNT(*) FROM hunt_sessions WHERE subject=?',[$temporary['id']])->fetchColumn()===0,'Removed team retained a session');
 check((int)$db->sql('SELECT COUNT(*) FROM hunt_progress WHERE team_id=?',[$temporary['id']])->fetchColumn()===0,'Removed team retained progress rows');
 check(count($db->teams())===4,'Roster count after removal');
 $owner->dispatch('POST','/api/admin/shuffle',[]);
 $event=$owner->dispatch('GET','/api/admin/overview',[])['event'];check(array_column($event['checkpoints'],'nonce','id')===$before,'Reshuffle changed station QR codes');
 foreach($teams as &$team){
  $key=preg_replace('/-----[^-]+-----|\s/','',openssl_pkey_get_details($team['key'])['key']);
  $enrolled=$public->dispatch('POST','/api/enroll',['code'=>$team['code'],'pin'=>'Test-Team-Pass','client_version'=>18,'device_id'=>$team['device'],'public_key'=>$key]);
  $team['api']=new Api($db,'Bearer '.$enrolled['token'],'test-phone');
 }unset($team);
 $sync=function(array &$team,array $events=[])use($db){
  $reply=$team['api']->dispatch('POST','/api/sync',['client_version'=>18,'device_id'=>$team['device'],'run_id'=>$team['run_id']??'','events'=>$events,'package_hash'=>$team['package_hash'],'kiosk'=>true,'ready'=>true,'pending'=>0]);
  if(isset($reply['package_hash'])){$team['package_hash']=$reply['package_hash'];$payload=\Hunt\verify($db->public,$reply['package']);$previousRun=$team['run_id']??'';$team['run_id']=$payload['run_id']??'';if($previousRun!==$team['run_id']){$team['seq']=$reply['last_seq'];$team['previous']=$reply['last_hash'];}}return $reply;
 };
 foreach($teams as &$team){$sync($team);$sync($team);}unset($team);
 $gps=['lat'=>27.448067,'lon'=>94.8953513,'accuracy'=>8,'age_ms'=>5000,'mock'=>false];
 $tracking=$teams[0]['api']->dispatch('POST','/api/sync',['client_version'=>18,'device_id'=>$teams[0]['device'],'events'=>[],'location'=>$gps,'ready'=>true,'kiosk'=>true]);
 $point=$db->team($teams[0]['id'])['data']['location'];check($point['received_at']-$point['sampled_at']===5000,'GPS sample age must be retained');
 $peers=\Hunt\opponentSnapshot($db,$db->event(),$teams[1]['id']);$marker=array_values(array_filter($peers['points'],fn($p)=>$p['code']===$teams[0]['code']))[0];
 check($peers['mode']==='live'&&$peers['delay_seconds']===0&&abs($marker['lat']-$gps['lat'])<1e-7&&abs($marker['lon']-$gps['lon'])<1e-7&&$marker['radius_m']===8,'Live GPS must preserve coordinates and accuracy');
 check(!str_contains(\Hunt\json($peers),'device_id')&&!str_contains(\Hunt\json($peers),'public_key'),'Tracking reply leaked device credentials');
 $gps['age_ms']=15000;$gps['lat']=27.45;
 $teams[0]['api']->dispatch('POST','/api/sync',['client_version'=>18,'device_id'=>$teams[0]['device'],'events'=>[],'location'=>$gps,'ready'=>true,'kiosk'=>true]);
 check($db->team($teams[0]['id'])['data']['location']['lat']===$point['lat'],'An older fix replaced a newer location');
 $row=$db->team($teams[0]['id']);$row['data']['location']['sampled_at']=\Hunt\stamp()-31000;$db->saveTeam($row);
 check(\Hunt\opponentSnapshot($db,$db->event(),$teams[1]['id'])['points'][0]['stale'],'Disconnected locations must become stale');
 $row['data']['location']['mock']=true;$db->saveTeam($row);check(\Hunt\opponentSnapshot($db,$db->event(),$teams[1]['id'])['points']===[],'Mock GPS was shared as a real position');
 $row['data']['location']=$point;$db->saveTeam($row);
 $owner->dispatch('POST','/api/admin/rounds/1/start',[]);
 $passCountdown=function(int $n)use($db){$db->begin();$e=$db->event();check($e['rounds'][$n]['started_at']>\Hunt\stamp(),'Start timestamp must be in the future');$e['rounds'][$n]['started_at']=\Hunt\stamp()-1000;$db->saveEvent($e);$db->commit();};
 $passCountdown(1);
 $e=$db->event();denied(fn()=>$owner->dispatch('POST','/api/admin/reset',['password'=>'Test-Owner-Only-2026!','confirm'=>'RESET','keep_teams'=>true,'edit_revision'=>$e['edit_revision']??$e['revision']]),409);

 denied(fn()=>$owner->dispatch('POST','/api/admin/shuffle',[]),409);
 denied(fn()=>$owner->dispatch('POST','/api/admin/teams',['code'=>'TOO_LATE','name'=>'Locked','pin'=>'Temporary-Pass']),409);
 denied(fn()=>$owner->dispatch('DELETE','/api/admin/teams/'.$teams[0]['id'],[]),409);
 $action=function(array &$team,int $n,bool $wrong=false)use($db,$owner,$stationReceipts){
  $e=$owner->dispatch('GET','/api/admin/overview',[])['event'];$state=$db->team($team['id']);$p=$state['data']['rounds'][$n];$rid=$p['route'][$p['completed']];$r=array_column($e['riddles'],null,'id')[$rid];$c=array_column($e['checkpoints'],null,'id')[$r['checkpoint_id']];
  if($wrong)foreach($e['checkpoints'] as $other)if($other['id']!==$c['id']){$c=$other;break;}
  $team['elapsed']+=1000;
  $payload=['id'=>\Hunt\uid(),'seq'=>++$team['seq'],'prev_hash'=>$team['previous'],'event_id'=>$e['id'],'revision'=>$e['revision'],'team_id'=>$team['id'],'device_id'=>$team['device'],'round'=>$n,'run_id'=>$e['run_id']??'','kind'=>'SCAN','control_version'=>$e['rounds'][$n]['control_version'],'elapsed_ms'=>$team['elapsed'],'boot_id'=>1,'qr'=>$stationReceipts[$c['id']]];
  $envelope=\Hunt\signed($team['key'],$payload);$team['previous']=hash('sha256',$envelope['payload']);return $envelope;
 };
 $hint=function(array &$team,string $rid)use($db){$e=$db->event();$value=['id'=>\Hunt\uid(),'seq'=>++$team['seq'],'prev_hash'=>$team['previous'],'team_id'=>$team['id'],'device_id'=>$team['device'],'event_id'=>$e['id'],'run_id'=>$e['run_id']??'','revision'=>$e['revision'],'kind'=>'HINT','riddle_id'=>$rid];$envelope=\Hunt\signed($team['key'],$value);$team['previous']=hash('sha256',$envelope['payload']);return $envelope;};
 $pending=$action($teams[0],1);$e=$owner->dispatch('GET','/api/admin/overview',[])['event'];$riddles=$e['riddles'];foreach($riddles as &$r){$r['hint']='Organizers can release this hint.';$r['hint_enabled']=true;}unset($r);
 $owner->dispatch('PUT','/api/admin/content',['revision'=>$e['revision'],'edit_revision'=>$e['edit_revision']??$e['revision'],'riddles'=>$riddles]);
 $after=$owner->dispatch('GET','/api/admin/overview',[])['event'];check($after['revision']===$e['revision'],'Live hints must not invalidate offline scan revision');
 check(array_column($after['checkpoints'],'nonce','id')===$before,'Hint edit changed printed QR codes');
 $reply=$sync($teams[0],[$pending]);check($reply['ack'][0]['result']==='CHECKPOINT_ACCEPTED','Queued scan failed after a live hint edit');
 $package=\Hunt\verify($db->public,$reply['package']);check(count($package['riddles'])===3&&isset($package['riddles'][0]['hint']),'Released hint must reach assigned APK package');
 check(!str_contains(\Hunt\json($package),'placement')&&!str_contains(\Hunt\json($package),'assignment_seed'),'Participant package privacy');
 check(count(array_filter($package['riddles'],fn($r)=>$r['title']==='Quest'))===3,'Participant titles revealed workbook riddle numbers');
 check(array_filter($package['checkpoints'],fn($c)=>$c['name']!=='Checkpoint')===[],'Checkpoint names leaked riddle identifiers');

 denied(fn()=>$owner->dispatch('PUT','/api/admin/content',['revision'=>$e['revision'],'edit_revision'=>$e['edit_revision']??$e['revision'],'riddles'=>$riddles]),409);
 $replay=$sync($teams[0],[$pending]);check($replay['ack']===$reply['ack'],'Offline replay must be idempotent');
 $hintId=$db->team($teams[0]['id'])['data']['rounds'][1]['route'][1];$hintEnvelope=$hint($teams[0],$hintId);
 $granted=$sync($teams[0],[$hintEnvelope]);check($granted['ack'][0]['result']==='HINT_GRANTED'&&count($granted['hints_used'])===1,'The first hint must consume exactly one allowance');
 check($sync($teams[0],[$hintEnvelope])['ack']===$granted['ack'],'A retried hint must be idempotent');
 check($sync($teams[0],[$hint($teams[0],$hintId)])['ack'][0]['result']==='HINT_ALREADY_REVEALED','Reopening a hint must be free');
 $r=$db->event()['rounds'][1];$owner->dispatch('POST','/api/admin/rounds/1/extend',['minutes'=>5]);check($db->event()['rounds'][1]['duration_ms']===$r['duration_ms']+300000&&$db->event()['rounds'][1]['control_version']===$r['control_version'],'Extending time must preserve queued scan control versions');
 $e=$db->event();$owner->dispatch('PUT','/api/admin/rounds/2/settings',['edit_revision'=>$e['edit_revision']??$e['revision'],'duration_ms'=>5460000,'qualify_mode'=>'all','qualify_limit'=>20]);check($db->event()['rounds'][2]['duration_ms']===5460000,'An upcoming round must remain configurable during play');
 foreach([2,3] as $n){$e=$db->event();$owner->dispatch('PUT',"/api/admin/rounds/$n/settings",['edit_revision'=>$e['edit_revision']??$e['revision'],'duration_ms'=>0,'qualify_mode'=>'all','qualify_limit'=>20]);check($db->event()['rounds'][$n]['duration_ms']===0,'Rounds 2 and 3 must allow no time limit');}
 $invalid=$db->event()['rounds'];$invalid[1]['duration_ms']=0;\Hunt\validateRoundSettings($invalid);check(true,'Round 1 supports no time limit');
 $e=$db->event();$owner->dispatch('PUT','/api/admin/live-settings',['edit_revision'=>$e['edit_revision']??$e['revision'],'name'=>$e['name'],'organizer_phone'=>'1234567890','opponent_map'=>'live','public_leaderboard'=>true]);check($db->event()['settings']['organizer_phone']==='1234567890','Live controls did not reach the event');
 foreach(['WRONG_SEQUENCE','WRONG_SEQUENCE','COOLDOWN_STARTED'] as $expected){$reply=$sync($teams[1],[$action($teams[1],1,true)]);check($reply['ack'][0]['result']===$expected,'Heart penalty or cooldown rule');}
 check($reply['lives_remaining']===0&&!$reply['eliminated'],'Three wrong scans should start recovery');
 $teams[1]['elapsed']+=900001;$reply=$sync($teams[1],[$action($teams[1],1)]);check($reply['ack'][0]['result']==='CHECKPOINT_ACCEPTED'&&$reply['lives_remaining']===3,'Cooldown recovery must retain route');
 foreach([1=>3,2=>7,3=>2] as $n=>$count){
  if($n>1){foreach($teams as &$team)$sync($team);unset($team);$owner->dispatch('POST',"/api/admin/rounds/$n/start",[]);$passCountdown($n);
   $db->begin();$e=$db->event();$e['rounds'][$n]['started_at']=\Hunt\stamp()-864000000;$db->saveEvent($e);$db->commit();
   $view=$owner->dispatch('GET','/api/admin/overview',[]);check($view['rounds'][$n]['status']==='RUNNING'&&$view['rounds'][$n]['remaining_ms']===null,'An unlimited round expired after elapsed time');
   $owner->dispatch('POST',"/api/admin/rounds/$n/pause",[]);check($db->event()['rounds'][$n]['status']==='PAUSED','Unlimited pause failed');$owner->dispatch('POST',"/api/admin/rounds/$n/resume",[]);
  }
  foreach($teams as &$team){
   if($n===3&&$team['id']===$teams[3]['id']){check($sync($team,[$action($team,3)])['ack'][0]['result']==='ELIMINATED','A fourth team scored after the third winner');continue;}
   $state=$db->team($team['id']);$done=$state['data']['rounds'][$n]['completed'];for($i=$done;$i<$count;$i++){
   if($team['id']===$teams[0]['id']&&(($n===1&&$i===2)||($n===2&&$i<=1))){$rid=$state['data']['rounds'][$n]['route'][$i];$h=$sync($team,[$hint($team,$rid)]);$expected=$n===2&&$i===1?'HINT_LIMIT_REACHED':'HINT_GRANTED';check($h['ack'][0]['result']===$expected,'Hint quota must remain three across rounds');check(count($h['hints_used'])<=3,'Server allowed a fourth hint');}
   check($sync($team,[$action($team,$n)])['ack'][0]['result']==='CHECKPOINT_ACCEPTED','Correct assigned QR scan failed');}}unset($team);
  if($n<3)$owner->dispatch('POST',"/api/admin/rounds/$n/end",[]);
 }
 $view=$owner->dispatch('GET','/api/admin/overview',[]);check(count(array_filter($view['leaderboard']['rows'],fn($r)=>$r['outcome']==='WINNER'))===1,'Final winner must be unique');
 check(array_slice(array_column($view['leaderboard']['rows'],'outcome'),0,3)===['WINNER','SECOND_PLACE','THIRD_PLACE'],'Final places must be first, second and third');
 check($db->event()['rounds'][3]['status']==='ENDED'&&$db->event()['rounds'][3]['end_reason']==='PODIUM_COMPLETE','The third finisher did not close the hunt');
 check(!empty($db->team($teams[3]['id'])['data']['eliminated_at']),'Unfinished teams survived podium completion');
 foreach([0,1,2] as $index)check($db->team($teams[$index]['id'])['data']['rounds'][3]['podium_place']===$index+1,'Stored podium position is incorrect');
 $publicBoard=$public->dispatch('GET','/api/leaderboard',[]);check(array_filter($publicBoard['rows'],fn($r)=>$r['solved']!==[])===[],'The participant leaderboard exposed riddle identifiers');

 foreach($db->teams() as $team){$all=[];foreach($team['data']['rounds'] as $r)$all=array_merge($all,$r['route']);check(count($all)===12&&count(array_unique($all))===12,'A team repeated a station across rounds');}
 $print=$owner->dispatch('GET','/api/admin/print/stations',[]);check(substr_count($print->body,'<article>')===40,'Station print count');check(!str_contains($print->body,'Placement:')&&!str_contains($print->body,'Organizers can release'),'Station sheets must not contain answers or hints');
 check(!str_contains($print->body,'Riddle 01')&&!str_contains($print->body,'Station 01'),'Public QR sheets expose a riddle number');
 $organizerPrint=$owner->dispatch('GET','/api/admin/print/organizer',[]);check(str_contains($organizerPrint->body,'Riddle 01')&&str_contains($organizerPrint->body,'PRIVATE'),'Private organizer mapping is missing');
 preg_match_all('/Seal [A-F0-9]{8}/',$print->body,$publicLabels);preg_match_all('/Seal [A-F0-9]{8}/',$organizerPrint->body,$privateLabels);
 check(count(array_unique($publicLabels[0]))===40&&$publicLabels[0]===$privateLabels[0],'Public and organizer seal labels do not match uniquely');
 $db->begin();$state=$db->event();$state['content_bundle']='old-version';$db->saveEvent($state);$db->commit();$snapshot=\Hunt\json($state);
 $db->sql('DELETE FROM hunt_rate_limits');$login=$public->dispatch('POST','/api/admin/login',['username'=>'TestOwner','password'=>'Test-Owner-Only-2026!']);check($login['content_import']['status']==='blocked','A running event must not be overwritten by file upload');
 $db->begin();$current=$db->event();$db->commit();check(\Hunt\json($current)===$snapshot,'Existing game results changed during import');
 // Reset is a new run, with a private recovery snapshot and no stale offline scoring.
 denied(fn()=>$organizer->dispatch('POST','/api/admin/reset',[]));
 denied(fn()=>$organizer->dispatch('GET','/api/admin/backups/reset-'.\Hunt\uid(),[]));
 $e=$db->event();$reset=['password'=>'Test-Owner-Only-2026!','confirm'=>'RESET','keep_teams'=>true,'edit_revision'=>$e['edit_revision']??$e['revision']];
 denied(fn()=>$owner->dispatch('POST','/api/admin/reset',array_replace($reset,['password'=>'wrong-password'])));
 check($db->event()['rounds'][3]['status']==='ENDED'&&glob($directory.'/reset-*.json')===[],'A rejected reset changed the completed game');
 $oldHint=$hint($teams[0],$db->team($teams[0]['id'])['data']['rounds'][1]['route'][0]);$oldEvent=$db->event();
 $reset['edit_revision']=$oldEvent['edit_revision']??$oldEvent['revision'];
 $result=$owner->dispatch('POST','/api/admin/reset',$reset);$fresh=$db->event();
 check(count($db->teams())===4&&!$fresh['published']&&count(array_filter($fresh['rounds'],fn($r)=>$r['status']==='DRAFT'))===3,'Reset did not retain the roster in draft');
 check(!empty($fresh['run_id'])&&($oldEvent['run_id']??'')!==$fresh['run_id'],'Reset must create a new run identity');
 check(array_column($fresh['checkpoints'],'nonce','id')===$before&&count($fresh['riddles'])===40,'Reset changed printed codes or riddles');
 foreach($db->teams() as $t)check(($t['data']['hints_used']??[])===[]&&($t['data']['rounds']??[])===[]&&$t['data']['lives_remaining']===3&&!$t['data']['ready'],'Reset retained old play or hint usage');
 foreach($db->teams() as $t)check($owner->dispatch('GET','/api/admin/teams/'.$t['id'].'/credentials',[])['enrollment_pin']==='Test-Team-Pass','Reset lost the saved team login');
 $backup=$owner->dispatch('GET','/api/admin/backups/'.$result['backup_id'],[]);$saved=\Hunt\parse($backup->body);
 check($backup instanceof \Hunt\BinaryReply&&$saved['event']===$oldEvent&&count($saved['teams'])===4&&count($saved['hunt_actions'])>0,'The reset backup is incomplete');
 check((int)$db->sql('SELECT COUNT(*) FROM hunt_actions')->fetchColumn()===0,'Reset did not clear prior-run action rows');
 $reply=$sync($teams[0],[$oldHint]);check($reply['ack']===[]&&$reply['hints_used']===[]&&$teams[0]['run_id']===$fresh['run_id'],'An old offline action entered a new run');
 check($teams[0]['seq']===$db->team($teams[0]['id'])['data']['last_seq'],'A reset did not restore the confirmed hash-chain cursor');
 $t=$db->team($teams[0]['id']);$a=\Hunt\verify(preg_replace('/-----[^-]+-----|\s/','',openssl_pkey_get_details($teams[0]['key'])['key']),$oldHint);
 check(\Hunt\applyAction($db,$fresh,$t,$a)==='WRONG_RUN','Imported old-run evidence affected a new game');
 $owner->dispatch('POST','/api/admin/publish',[]);$owner->dispatch('POST','/api/admin/unpublish',[]);
 check(!$db->event()['published']&&($db->team($teams[0]['id'])['data']['rounds']??[])===[],'Edit draft did not unlock assignments');
 $e=$db->event();$t=$db->team($teams[0]['id']);$newIncident=\Hunt\signed($teams[0]['key'],['id'=>\Hunt\uid(),'seq'=>$t['data']['last_seq']+1,'prev_hash'=>$t['data']['last_hash'],'event_id'=>$e['id'],'run_id'=>$e['run_id'],'team_id'=>$t['id'],'device_id'=>$teams[0]['device'],'kind'=>'INCIDENT','message'=>'New run evidence']);
 $import=$owner->dispatch('POST','/api/admin/import-evidence',['team_id'=>$t['id'],'device_id'=>$teams[0]['device'],'events'=>[$oldHint,$newIncident]]);
 check(array_column($import['ack'],'result')===['WRONG_RUN','RECORDED'],'Archived old-run evidence prevented importing current-run evidence');
 $e=$db->event();$reset['edit_revision']=$e['edit_revision']??$e['revision'];$reset['keep_teams']=false;
 $owner->dispatch('POST','/api/admin/reset',$reset);check($db->teams()===[],'Remove-all reset retained team accounts');
 denied(fn()=>$sync($teams[0]),401);
 // Real signed QR flows with one/two configured rounds and a custom final podium.
 foreach([1,2] as $count){
  $e=$db->event();$body=['edit_revision'=>$e['edit_revision']??$e['revision'],'name'=>$e['name'],'organizer_phone'=>'','opponent_map'=>'live','public_leaderboard'=>true,'require_kiosk'=>false,'round_count'=>$count,'round_sizes'=>[1=>2,2=>1,3=>1],'podium_places'=>$count];
  $owner->dispatch('PUT','/api/admin/live-settings',$body);check(\Hunt\roundNumbers($db->event())===range(1,$count),'Enabled rounds do not match the dashboard format');
  $players=[];
  for($i=0;$i<=$count;$i++){
   $created=$owner->dispatch('POST','/api/admin/teams',['code'=>"FORMAT{$count}_$i",'name'=>"Format $count team $i",'pin'=>'Test-Team-Pass']);$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$device=\Hunt\uid();
   $login=$public->dispatch('POST','/api/enroll',['code'=>"FORMAT{$count}_$i",'pin'=>'Test-Team-Pass','client_version'=>18,'device_id'=>$device,'public_key'=>preg_replace('/-----[^-]+-----|\\s/','',openssl_pkey_get_details($key)['key'])]);
   $players[]=['id'=>$created['id'],'device'=>$device,'key'=>$key,'seq'=>0,'previous'=>'','elapsed'=>0,'package_hash'=>'','api'=>new Api($db,'Bearer '.$login['token'],'format-test')];
   $payload=\Hunt\verify($db->public,$login['package']);check($payload['settings']['round_count']===$count&&count($login['rounds'])===$count,'APK received disabled rounds');
  }
  foreach($players as &$player)$sync($player);unset($player);
  for($n=1;$n<=$count;$n++){
   $owner->dispatch('POST',"/api/admin/rounds/$n/start",[]);$passCountdown($n);
   $e=$db->event();$control=$e['rounds'][$n]['control_version'];
   $owner->dispatch('PUT',"/api/admin/rounds/$n/settings",['edit_revision'=>$e['edit_revision']??$e['revision'],'remaining_minutes'=>5]);
   check(\Hunt\snapshot($db->event()['rounds'][$n])['remaining_ms']>290000,'Editing active remaining time did not update the shared deadline');
   $e=$db->event();$owner->dispatch('PUT',"/api/admin/rounds/$n/settings",['edit_revision'=>$e['edit_revision']??$e['revision'],'duration_ms'=>0]);
   check(\Hunt\snapshot($db->event()['rounds'][$n])['remaining_ms']===null&&$db->event()['rounds'][$n]['control_version']===$control,'Unlimited time broke queued evidence versions');
   $e=$db->event();$locked=$body;$locked['edit_revision']=$e['edit_revision']??$e['revision'];$locked['round_count']=$count===1?2:1;denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',$locked),409);
   foreach($players as $i=>&$player){$sync($player);
    if($n===$count&&$i===$count){check($sync($player,[$action($player,$n)])['ack'][0]['result']==='ELIMINATED','The custom final allowed a scan after its last prize');continue;}
    for($j=0;$j<\Hunt\roundTotal($db->event(),$n);$j++)check($sync($player,[$action($player,$n)])['ack'][0]['result']==='CHECKPOINT_ACCEPTED','A configured-round station scan failed');
   }unset($player);
   if($n<$count)$owner->dispatch('POST',"/api/admin/rounds/$n/end",[]);
  }
  $board=$owner->dispatch('GET','/api/admin/overview',[])['leaderboard'];check($board['round']===$count&&$db->event()['rounds'][$count]['end_reason']==='PODIUM_COMPLETE','The last enabled round did not close its podium');
  check(array_column(array_slice($board['rows'],0,$count),'podium_place')===range(1,$count),'Configured final places are not unique and ordered');
  denied(fn()=>$owner->dispatch('POST','/api/admin/rounds/'.($count+1).'/start',[]),400);
  $e=$db->event();$reset['edit_revision']=$e['edit_revision']??$e['revision'];$reset['keep_teams']=false;$owner->dispatch('POST','/api/admin/reset',$reset);
 }
 // Organizers may shorten a started hunt without asking teams to scan completed routes again.
 $e=$db->event();$body['edit_revision']=$e['edit_revision']??$e['revision'];$body['round_count']=3;$body['podium_places']=1;$owner->dispatch('PUT','/api/admin/live-settings',$body);
 $created=$owner->dispatch('POST','/api/admin/teams',['code'=>'SHORTEN','name'=>'Early final','pin'=>'Test-Team-Pass']);$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$device=\Hunt\uid();
 $login=$public->dispatch('POST','/api/enroll',['code'=>'SHORTEN','pin'=>'Test-Team-Pass','client_version'=>18,'device_id'=>$device,'public_key'=>preg_replace('/-----[^-]+-----|\\s/','',openssl_pkey_get_details($key)['key'])]);
 $player=['id'=>$created['id'],'device'=>$device,'key'=>$key,'seq'=>0,'previous'=>'','elapsed'=>0,'package_hash'=>'','api'=>new Api($db,'Bearer '.$login['token'],'early-final-test')];$sync($player);
 $owner->dispatch('POST','/api/admin/rounds/1/start',[]);$passCountdown(1);$sync($player);
 for($i=0;$i<2;$i++)$sync($player,[$action($player,1)]);
 check($db->team($created['id'])['data']['rounds'][1]['status']==='QUALIFIED','A preliminary round claimed a prize too soon');
 $owner->dispatch('POST','/api/admin/rounds/1/end',[]);$e=$db->event();$owner->dispatch('POST','/api/admin/final-round',['edit_revision'=>$e['edit_revision']??$e['revision'],'round'=>1,'reason'=>'Organizers choose one round']);
 $reply=$sync($player);$confirmed=(array)$reply['progress'];check(\Hunt\verify($db->public,$reply['package'])['settings']['round_count']===1&&$confirmed[1]['podium_place']===1&&$confirmed[1]['status']==='FINISHED','Shortening the hunt did not promote the confirmed finish to a prize');
 denied(fn()=>$owner->dispatch('POST','/api/admin/rounds/2/start',[]),400);
 $e=$db->event();$reset['edit_revision']=$e['edit_revision']??$e['revision'];$reset['keep_teams']=false;$owner->dispatch('POST','/api/admin/reset',$reset);
 // Execute the reset/recreate file only against this disposable loopback database.
 $oldEventId=$db->event()['id'];$resetSql=file_get_contents(dirname(__DIR__).'/database/reset-treasure-hunt.sql');
 check(substr_count($resetSql,'USE `u332237642_treasure_hunt`;')===1,'Reset SQL database scope changed');
 $resetSql=str_replace('USE `u332237642_treasure_hunt`;','USE `'.$schema.'`;',$resetSql);
 foreach(explode(';',$resetSql) as $statement)if(trim($statement)!=='')$db->pdo->exec($statement);
 check((int)$db->sql('SELECT COUNT(*) FROM hunt_admins')->fetchColumn()===0&&(int)$db->sql('SELECT COUNT(*) FROM hunt_events')->fetchColumn()===0,'Full SQL reset retained accounts or event state');
 \Hunt\install($config,'TestOwner','Test-Owner-Only-2026!');check(count($db->event()['riddles'])===40&&$db->event()['id']!==$oldEventId,'A reset database did not initialize with the workbook');
 // Current production flow: one hunt, no hints, organizer helpline and exactly three prizes.
 $db->config['legacy_round_tests']=false;$login=$public->dispatch('POST','/api/admin/login',['username'=>'TestOwner','password'=>'Test-Owner-Only-2026!']);$owner=new Api($db,'Bearer '.$login['token'],'continuous-owner');$view=$owner->dispatch('GET','/api/admin/overview',[]);$e=$view['event'];
 check(\Hunt\continuousHunt($e)&&\Hunt\roundNumbers($e)===[1]&&\Hunt\prizePlaces($e)===3,'Draft was not upgraded to one continuous hunt');
 $continuousNonces=array_column($e['checkpoints'],'nonce','id');
 $settings=function(int $total,string $phone='')use($db){$e=$db->event();return ['edit_revision'=>$e['edit_revision']??$e['revision'],'name'=>$e['name'],'organizer_phone'=>$phone,'opponent_map'=>'live','public_leaderboard'=>true,'target_riddles'=>$total,'require_kiosk'=>false];};
 $owner->dispatch('PUT','/api/admin/live-settings',$settings(2));$owner->dispatch('PUT','/api/admin/live-settings',$settings(5,'+91 12345 67890'));
 foreach([-1,601,1.5,'30'] as $seconds){$body=$settings(5);$body['start_countdown_seconds']=$seconds;denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',$body),400);}
 foreach([0,600,45] as $seconds){$body=$settings(5);$body['start_countdown_seconds']=$seconds;$owner->dispatch('PUT','/api/admin/live-settings',$body);check($db->event()['settings']['start_countdown_seconds']===$seconds,'Start countdown was not saved');}
 $bad=$db->event();$bad['settings']['target_riddles']=0;denied(fn()=>\Hunt\prepareV3Content($bad,[]),400);
 denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',$settings(5,'1()')),400);
 $owner->dispatch('POST','/api/admin/staff',['username'=>'LiveViewer','password'=>'Test-Viewer-Only-2026!','role'=>'volunteer','active'=>true,'email'=>'']);
 $v=$public->dispatch('POST','/api/admin/login',['username'=>'LiveViewer','password'=>'Test-Viewer-Only-2026!']);$v=(new Api($db,'Bearer '.$v['token'],'viewer-test'))->dispatch('GET','/api/admin/overview',[]);
 check($v['event']['settings']['hunt_mode']==='continuous-v1'&&$v['event']['settings']['target_riddles']===5&&$v['event']['riddles']===[],'Volunteer cannot monitor the continuous hunt safely');
 $e=$db->event();foreach($e['riddles'] as &$clue){$clue['hint']='Private archived hint';$clue['hint_enabled']=true;}unset($clue);$db->saveEvent($e);
 $currentReceipts=[];foreach($e['checkpoints'] as $cp)$currentReceipts[$cp['id']]=\Hunt\signed($db->key,['event_id'=>$e['id'],'checkpoint_id'=>$cp['id'],'nonce'=>$cp['nonce']]);
 // Existing teams migrate without schema changes; the first 60 get different art.
 $fixtureIds=[];for($i=0;$i<61;$i++){$id=\Hunt\uid();$fixtureIds[]=$id;$db->sql('INSERT INTO hunt_teams(id,code,name,pin,data) VALUES(?,?,?,?,?)',[$id,'AVATAR_'.$i,'Avatar team '.$i,'{}','{}']);}
 $db->begin();$avatars=\Hunt\teamAvatars($db,$db->teams());$db->commit();$assigned=array_column(array_column($avatars,'data'),'avatar_id');sort($assigned);
 check($assigned===range(0,60),'Character assignments collide');
 $before=array_column(array_map(fn($t)=>['id'=>$t['id'],'avatar'=>$t['data']['avatar_id']],$avatars),'avatar','id');
 $db->begin();$again=\Hunt\teamAvatars($db,array_reverse($db->teams()));$db->commit();foreach($again as $t)check($t['data']['avatar_id']===$before[$t['id']],'A saved team character changed');
 foreach($fixtureIds as $id)$db->sql('DELETE FROM hunt_teams WHERE id=?',[$id]);
 $auto=$owner->dispatch('POST','/api/admin/teams',['name'=>'Auto team']);check((bool)preg_match('/^[1-9][0-9]{4}$/',$auto['code'])&&(bool)preg_match('/^[1-9][0-9]{5}$/',$auto['enrollment_pin']),'Generated account format is wrong');
 $valid=$owner->dispatch('POST','/api/admin/teams/'.$auto['id'].'/login-check',['pin'=>$auto['enrollment_pin']]);check($valid['valid'],'Generated login code does not authenticate');
 $bulk=$owner->dispatch('POST','/api/admin/teams/import',['names'=>"Cedar\nRiver\n\n"]);check(count($bulk['accounts'])===2,'Bulk names did not create two teams');$created=array_merge([$auto],$bulk['accounts']);check(count(array_unique(array_column($created,'code')))===3,'Generated team IDs collide');
 foreach($bulk['accounts'] as $a)check((bool)preg_match('/^[1-9][0-9]{4}$/',$a['code'])&&(bool)preg_match('/^[1-9][0-9]{5}$/',$a['enrollment_pin']),'Bulk account format is wrong');
 $count=count($db->teams());denied(fn()=>$owner->dispatch('POST','/api/admin/teams/import',['names'=>"New team\nCedar"]),409);check(count($db->teams())===$count,'Duplicate bulk names partially created accounts');
 denied(fn()=>$owner->dispatch('POST','/api/admin/teams/import',['names'=>['Valid first',str_repeat('a',101)]]),400);check(count($db->teams())===$count,'Invalid bulk names partially created accounts');
 foreach($created as $a)$owner->dispatch('DELETE','/api/admin/teams/'.$a['id'],[]);
 $players=[];
 for($i=0;$i<4;$i++){
  $c=$owner->dispatch('POST','/api/admin/teams',['name'=>'Continuous '.$i,'code'=>'CONT'.$i,'pin'=>'Test-Team-Pass']);$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$device=\Hunt\uid();
  $login=$public->dispatch('POST','/api/enroll',['code'=>'CONT'.$i,'pin'=>'Test-Team-Pass','client_version'=>18,'device_id'=>$device,'public_key'=>preg_replace('/-----[^-]+-----|\\s/','',openssl_pkey_get_details($key)['key'])]);
  $content=\Hunt\verify($db->public,$login['package']);check($content['settings']['round_count']===1&&$content['settings']['target_riddles']===5&&count($login['rounds'])===1,'Phone received a multi-round format');
  check(array_filter($content['riddles'],fn($r)=>isset($r['hint']))===[],'Archived hint text reached a phone');
  check(is_int($content['avatar_id'])&&$content['avatar_id']>=0,'Team character missing from the signed phone package');
  $players[]=['id'=>$c['id'],'key'=>$key,'device'=>$device,'seq'=>0,'previous'=>'','elapsed'=>0,'package_hash'=>'','api'=>new Api($db,'Bearer '.$login['token'],'continuous-test')];
 }
 foreach($players as &$p)$sync($p);unset($p);
 $beforeStart=\Hunt\stamp();$owner->dispatch('POST','/api/admin/rounds/1/start',[]);
 check($db->event()['rounds'][1]['started_at']>=$beforeStart+45000&&$db->event()['rounds'][1]['started_at']<=\Hunt\stamp()+45000,'Configured shared countdown was not applied');$passCountdown(1);
 $body=$settings(5);$body['start_countdown_seconds']=1;denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',$body),409);
 denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',$settings(4)),409);
 $e=$db->event();denied(fn()=>$owner->dispatch('PUT','/api/admin/live-settings',['edit_revision'=>$e['edit_revision']??$e['revision'],'podium_places'=>1]),409);
 $scanCurrent=function(array &$p,bool $hint=false)use($db,$currentReceipts){$e=$db->event();$t=$db->team($p['id']);$rid=$t['data']['rounds'][1]['route'][$t['data']['rounds'][1]['completed']];$r=array_column($e['riddles'],null,'id')[$rid];
  $v=['id'=>\Hunt\uid(),'seq'=>++$p['seq'],'prev_hash'=>$p['previous'],'event_id'=>$e['id'],'run_id'=>$e['run_id']??'','revision'=>$e['revision'],'team_id'=>$p['id'],'device_id'=>$p['device'],'round'=>1,'kind'=>$hint?'HINT':'SCAN','riddle_id'=>$rid,'qr'=>$currentReceipts[$r['checkpoint_id']],'control_version'=>$e['rounds'][1]['control_version'],'elapsed_ms'=>++$p['elapsed'],'boot_id'=>1];
  $signed=\Hunt\signed($p['key'],$v);$p['previous']=hash('sha256',$signed['payload']);return $signed;
 };
 check($sync($players[0],[$scanCurrent($players[0],true)])['ack'][0]['result']==='HINTS_DISABLED','Hint claims were not disabled by the server');
 for($i=0;$i<3;$i++){for($j=0;$j<5;$j++)check($sync($players[$i],[$scanCurrent($players[$i])])['ack'][0]['result']==='CHECKPOINT_ACCEPTED','Continuous hunt scan failed');check($db->team($players[$i]['id'])['data']['rounds'][1]['podium_place']===$i+1,'Continuous prize order is wrong');}
 check($db->event()['rounds'][1]['status']==='ENDED'&&$db->event()['rounds'][1]['end_reason']==='PODIUM_COMPLETE','Third winner did not close the whole hunt');
 check($sync($players[3],[$scanCurrent($players[3])])['ack'][0]['result']==='ELIMINATED','A fourth team scored after closure');
 check(!empty($db->team($players[3]['id'])['data']['eliminated_at']),'A non-winner was not eliminated');
 $proof=$db->sql("SELECT data FROM hunt_audit WHERE kind='PODIUM_AWARDED' ORDER BY id")->fetchAll();check(count($proof)===3,'Winner timestamp audit is missing');
 foreach($proof as $i=>$row){$receipt=\Hunt\parse($row['data']);$winner=$db->team($players[$i]['id']);$p=$winner['data']['rounds'][1];check($receipt['team_name']===$winner['name']&&$receipt['place']===$i+1&&$receipt['finished_at']===$p['finished_at']&&$receipt['finish_elapsed_ms']===$p['finish_elapsed_ms'],'Winner proof does not match stored results');check($p['finish_elapsed_ms']===$p['finished_at']-$db->event()['rounds'][1]['started_at']-$db->event()['rounds'][1]['paused_ms'],'Elapsed finish time is incorrect');}
 check(array_column($db->event()['checkpoints'],'nonce','id')===$continuousNonces,'Continuous migration changed printed station QR codes');
 $printed=$owner->dispatch('GET','/api/admin/print/organizer',[]);check(!str_contains($printed->body,'Hint:'),'Organizer sheets still print hints');
 check(substr_count($printed->body,'class="print-page"')===10&&substr_count($printed->body,'<article>')===40,'Print pack does not fit four cards per A4 page');
 $printDir=$root.'/runtime/print-qa';if(!is_dir($printDir))mkdir($printDir,0700,true);file_put_contents($printDir.'/organizer.html',$printed->body);file_put_contents($printDir.'/stations.html',$owner->dispatch('GET','/api/admin/print/stations',[])->body);
 $report=['checks'=>$checks,'database'=>'disposable-mysql','riddles'=>40,'continuous_hunt'=>true,'editable_riddle_total'=>true,'prize_places'=>3,'hints_enabled'=>false,'editable_helpline'=>true,'roles'=>['owner','organizer','volunteer'],'admin_reset_with_backup'=>true,'legacy_regressions'=>['round_sizes'=>[3,7,2],'distribution'=>$distribution,'max_hints_per_team'=>3,'offline_scans_preserved_after_hint_edits'=>true],'live_hosting_tested'=>false,'cli_php'=>PHP_VERSION];
 file_put_contents($root.'/artifacts/event-ready-checks.json',\Hunt\json($report));echo \Hunt\json($report).PHP_EOL;
}finally{unset($db,$owner,$public,$volunteer,$organizer);$server->exec("DROP DATABASE $schema");}
