<?php
declare(strict_types=1);
namespace Hunt;

final class BinaryReply {
 public function __construct(public string $body,public string $type,public string $name) {}
}
final class Api {
 public function __construct(private Database $db,private string $bearer='',private string $ip='local') {}
 private function auth(string $kind): string {
  if(!str_starts_with($this->bearer,'Bearer '))fail('Authentication required',401);
  $r=$this->db->sql('SELECT subject FROM hunt_sessions WHERE hash=? AND kind=? AND expires>?',[hash('sha256',substr($this->bearer,7)),$kind,stamp()])->fetch();
  if(!$r)fail('Session expired or revoked',401);return $r['subject'];
 }
 private function throttle(string $kind,string $name): void {
  $db=$this->db;$id=hash('sha256',"$kind:$name:".$this->ip);$db->begin();
  try{
   $q=$db->mysql?'INSERT IGNORE INTO hunt_rate_limits VALUES(?,?,0)':'INSERT OR IGNORE INTO hunt_rate_limits VALUES(?,?,0)';
   $db->sql($q,[$id,stamp()]);$r=$db->sql('SELECT * FROM hunt_rate_limits WHERE id=?'.$db->lock(),[$id])->fetch();
   if(stamp()-(int)$r['window_start']>=60000){$r['window_start']=stamp();$r['attempts']=0;}
   if((int)$r['attempts']>=8){$db->commit();fail('Too many attempts. Wait one minute.',429);}
   $db->sql('UPDATE hunt_rate_limits SET attempts=?,window_start=? WHERE id=?',[(int)$r['attempts']+1,$r['window_start'],$id]);$db->commit();
  }catch(\Throwable $x){$db->rollback();throw $x;}
 }
 private function transaction(callable $call): mixed {
  $this->db->begin();try{$result=$call();$this->db->commit();return $result;}catch(\Throwable $x){$this->db->rollback();throw $x;}
 }
 public function dispatch(string $method,string $path,array $body): mixed {
  $db=$this->db;
  if($path==='/health')return ['status'=>'ok','protocol'=>2,'backend'=>$db->mysql?'php-mysql':'php-sqlite-local-preview','security'=>runtimeSecurity($db->config)['basis']];
  if($path==='/api/admin/login'&&$method==='POST'){
   ensureStaffSchema($db);
   $user=trim((string)($body['username']??''));$this->throttle('admin',$user);
   return $this->transaction(function()use($db,$user,$body){
    $r=$db->sql('SELECT password FROM hunt_admins WHERE username=?'.$db->lock(),[$user])->fetch();
    if(!$r||!password_verify((string)($body['password']??''),$r['password']))fail('Invalid credentials',401);
    $access=staffProfile($db,$user);if(!$access['active'])fail('This account has been disabled',403);
    $import=$access['role']==='owner'?importPreparedContent($db,$user):['status'=>'none'];
    $token=token();$db->sql('INSERT INTO hunt_sessions VALUES(?,?,?,?)',[hash('sha256',$token),$user,'admin',stamp()+28800000]);return ['token'=>$token,'expires_in'=>28800,'access'=>$access,'content_import'=>$import];
   });
  }
  if($method==='POST'&&$path==='/api/admin/password/request')return (new AdminAccess($db,$this->ip))->request($body);
  if($method==='POST'&&$path==='/api/admin/password/reset')return (new AdminAccess($db,$this->ip))->reset($body);
  if($path==='/api/logout'&&$method==='POST'){$db->sql('DELETE FROM hunt_sessions WHERE hash=?',[hash('sha256',substr($this->bearer,7))]);return ['ok'=>true];}
  if($path==='/api/enroll'&&$method==='POST')return $this->enroll($body);
  if($path==='/api/reconnect'&&$method==='POST')return $this->reconnect($body);
  if($path==='/api/sync'&&$method==='POST')return $this->sync($body,$this->auth('team'));
  if($path==='/api/admin/import-evidence'&&$method==='POST'){
   $actor=$this->auth('admin');authorizeStaff($db,$actor,$method,$path);$events=$body['events']??[];if(!is_array($events)||count($events)>5000)fail('Import limit is 5000 actions');
   $ack=[];foreach(array_chunk($events,32) as $batch){$r=$this->sync(['device_id'=>$body['device_id']??null,'events'=>$batch],(string)($body['team_id']??''),true);$ack=array_merge($ack,$r['ack']);}
   $db->audit($actor,'OFFLINE_EVIDENCE_IMPORTED',['team_id'=>$body['team_id'],'records'=>count($ack)]);return ['ack'=>$ack];
  }
  if($path==='/api/leaderboard'&&$method==='GET')return $this->transaction(function()use($db){$e=$db->event();expire($db,$e);if(!$e['public_leaderboard'])fail('Public standings disabled',403);return participantLeaderboard($db,$e);});
  $actor=$this->auth('admin');
  authorizeStaff($db,$actor,$method,$path);
  if($method==='GET'&&$path==='/api/admin/staff')return staffAccounts($db);
  if($method==='POST'&&$path==='/api/admin/staff')return saveStaffAccount($db,$actor,null,$body);
  if($method==='PUT'&&preg_match('~^/api/admin/staff/([a-zA-Z0-9_-]{1,80})$~',$path,$m))return saveStaffAccount($db,$actor,$m[1],$body);
  if($method==='GET'&&$path==='/api/admin/account')return (new AdminAccess($db,$this->ip))->account($actor);
  if($method==='PUT'&&$path==='/api/admin/account/mail')return (new AdminAccess($db,$this->ip))->saveMail($actor,$body);
  if($method==='POST'&&$path==='/api/admin/reset')$this->throttle('reset',$actor);
  return $this->transaction(function()use($db,$method,$path,$body,$actor){
   $e=$db->event();expire($db,$e);
   if($method==='GET'&&$path==='/api/admin/overview')return $this->overview($e,$actor);
   if($method==='PUT'&&$path==='/api/admin/content')return $this->saveContent($e,$body,$actor);
   if($method==='POST'&&$path==='/api/admin/teams')return $this->addTeam($e,$body,$actor);
   if($method==='POST'&&$path==='/api/admin/unpublish')return unpublishAssignments($db,$e,$actor);
   if($method==='PUT'&&$path==='/api/admin/live-settings')return updateLiveSettings($db,$e,$body,$actor);
   if($method==='POST'&&$path==='/api/admin/final-round')return shortenHunt($db,$e,$body,$actor);
   if($method==='POST'&&$path==='/api/admin/reset')return resetHunt($db,$e,$body,$actor);
   if($method==='GET'&&preg_match('~^/api/admin/backups/(reset-[a-f0-9-]{36})$~',$path,$match))return downloadResetBackup($db,$match[1]);
   if($method==='PUT'&&preg_match('~^/api/admin/rounds/([123])/settings$~',$path,$match))return updateRoundSettings($db,$e,(int)$match[1],$body,$actor);
   if($method==='POST'&&preg_match('~^/api/admin/rounds/([123])/extend$~',$path,$match))return extendRound($db,$e,(int)$match[1],$body,$actor);
   if($method==='POST'&&$path==='/api/admin/teams/import')return $this->importRoster($e,$body,$actor);
   if($method==='POST'&&$path==='/api/admin/publish'){
    return ['ok'=>true,'routes'=>publishAssignments($db,$e,$actor)];
   }
   if($method==='POST'&&$path==='/api/admin/shuffle'){
    if(!$e['published'])fail('Shuffle and publish the event first',409);
    foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')fail('Assignments lock when a round starts',409);
    if((int)$db->sql("SELECT COUNT(*) FROM hunt_actions WHERE result NOT IN ('RECORDED','WRONG_RUN')")->fetchColumn()>0)fail('Existing scan evidence prevents reshuffling',409);
    $e['settings']['assignment_seed']=token();$teams=$db->teams();$assigned=routes($e,array_column($teams,'id'),1);
    foreach($teams as $team){$p=&progress($team,1);$p['route']=$assigned[$team['id']]['riddles'];$team['data']['ready']=false;unset($team['data']['package_ack']);$db->saveTeam($team);unset($p);}
    $e['revision']++;$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'ASSIGNMENTS_RESHUFFLED',['teams'=>count($teams)]);
    return ['ok'=>true];
   }
   if($method==='POST'&&preg_match('~^/api/admin/rounds/([123])/(start|pause|resume|end|prepare)$~',$path,$m))return $this->roundControl($e,(int)$m[1],$m[2],$actor);
   if($method==='PUT'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})$~',$path,$m))return $this->editTeam($e,$m[1],$body,$actor);
   if($method==='POST'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})/login-check$~',$path,$m)){
    $team=$db->team($m[1]);$valid=$this->credentialTeam($team['code'],(string)($body['pin']??''))!==null;
    return ['valid'=>$valid,'code'=>$team['code'],'phone_enrolled'=>isset($team['data']['device_id'])];
   }
   if($method==='GET'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})/credentials$~',$path,$m)){
    $team=$db->team($m[1]);$pin=teamLoginSecret($db,null,$team['data']['login_secret']??null);
    $db->audit($actor,'TEAM_LOGIN_VIEWED',['team_id'=>$team['id']]);return ['name'=>$team['name'],'code'=>$team['code'],'enrollment_pin'=>$pin];
   }
   if($method==='DELETE'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})$~',$path,$m))return $this->removeTeam($e,$m[1],$actor);
   if($method==='GET'&&preg_match('~^/api/admin/print/(organizer|stations)$~',$path,$m))return printPack($db,$e,$m[1]);
   if($method==='POST'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})/control$~',$path,$m))return $this->teamControl($e,$m[1],$body,$actor);
   if($method==='POST'&&preg_match('~^/api/admin/teams/([a-f0-9-]{36})/review$~',$path,$m))return $this->review($e,$m[1],$body,$actor);
   if($method==='GET'&&preg_match('~^/api/admin/qr/([a-zA-Z0-9_-]{1,80})$~',$path,$m)){
    $cp=array_column($e['checkpoints'],null,'id');$c=$cp[$m[1]]??null;if(!$c)fail('Checkpoint not found',404);
    $raw=json(signed($db->key,['event_id'=>$e['id'],'checkpoint_id'=>$c['id'],'nonce'=>$c['nonce']]));
    $renderer=new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(512,24),new \BaconQrCode\Renderer\Image\SvgImageBackEnd());
    return new BinaryReply((new \BaconQrCode\Writer($renderer))->writeString($raw),'image/svg+xml','checkpoint-'.$c['id'].'.svg');
   }
   if($method==='GET'&&$path==='/api/admin/export')return ['event'=>$e,'teams'=>$db->sql('SELECT id,code,name,lives_remaining,eliminated_at,elimination_reason FROM hunt_teams')->fetchAll(),'actions'=>$db->sql('SELECT * FROM hunt_actions')->fetchAll(),'life_events'=>$db->sql('SELECT * FROM hunt_life_events')->fetchAll(),'audit'=>$db->sql('SELECT * FROM hunt_audit')->fetchAll(),'leaderboard'=>leaderboard($db,$e)];
   fail('Endpoint not found',404);
  });
 }
 private function overview(array $e,string $actor): array {
  $db=$this->db;if(staffProfile($db,$actor)['role']!=='volunteer')upgradeContinuousDraft($db,$e,$actor);$teams=[];
  foreach(teamAvatars($db,$db->teams()) as $t){$d=$t['data'];$content=isset($d['device_id'])?contentPackage($db,$e,$t):null;
   $teams[]=['id'=>$t['id'],'code'=>$t['code'],'name'=>$t['name'],'avatar_id'=>$d['avatar_id'],'enrolled'=>isset($d['device_id']),'device_id'=>$d['device_id']??null,'last_seen'=>$d['last_seen']??0,'location'=>$d['location']??null,
    'kiosk'=>$d['kiosk']??false,'pending_actions'=>$d['pending_actions']??0,'rounds'=>(object)($d['rounds']??[]),'last_seq'=>$d['last_seq']??0,'lives_remaining'=>$d['lives_remaining']??3,'eliminated_at'=>$d['eliminated_at']??null,'elimination_reason'=>$d['elimination_reason']??null,'needs_review'=>$d['needs_review']??false,
    'cooldown_until'=>$d['cooldown_until']??null,'hints_used'=>count($d['hints_used']??[]),'ready'=>$d['ready']??false,'round_ack'=>$d['round_ack']??(object)[],'assignment_received'=>$content!==null&&($d['package_ack']??null)===hash('sha256',json($content))];
  }
  try{$calibration=calibration($e['anchors']);}catch(\Throwable $x){$calibration=null;}
  $rounds=[];foreach(roundNumbers($e) as $n)$rounds[$n]=snapshot($e['rounds'][$n]);
  $access=staffProfile($db,$actor);
  if($access['role']==='volunteer'){
   foreach($teams as &$team)foreach($team['rounds'] as &$round)unset($round['route']);unset($team,$round);
   $visible=array_intersect_key($e,array_flip(['id','name','published','rounds']));$visible+=['settings'=>array_intersect_key($e['settings'],array_flip(['hunt_mode','target_riddles','round_count','round_sizes','podium_places','organizer_phone','start_countdown_seconds','require_kiosk'])),'riddles'=>[],'checkpoints'=>[],'anchors'=>[],'edges'=>[]];
   return ['event'=>$visible,'rounds'=>$rounds,'teams'=>$teams,'issues'=>[],'server_time'=>stamp(),'access'=>$access,'map_calibration'=>null,'atlas'=>atlasMeta(),'audit'=>[],'rejected'=>[],'commands'=>[],'life_events'=>[],'leaderboard'=>leaderboard($db,$e)];
  }
  return ['event'=>$e,'rounds'=>$rounds,'teams'=>$teams,'access'=>$access,'issues'=>readiness($e),'start_checks'=>threeRound($e)?array_combine(roundNumbers($e),array_map(fn($n)=>roundStartCheck($db,$e,$n),roundNumbers($e))):[],'server_time'=>stamp(),'map_calibration'=>$calibration,'runtime_security'=>runtimeSecurity($db->config),'atlas'=>atlasMeta(),'local_preview'=>($db->config['test_only']??false)===true,
   'audit'=>$db->sql('SELECT * FROM hunt_audit ORDER BY id DESC LIMIT 100')->fetchAll(),
   'rejected'=>$db->sql("SELECT id,team_id,seq,result,received FROM hunt_actions WHERE result NOT IN ('CHECKPOINT_ACCEPTED','RECORDED') ORDER BY received DESC LIMIT 100")->fetchAll(),
   'commands'=>$db->sql('SELECT id,team_id,data,acknowledged FROM hunt_commands ORDER BY created_at DESC LIMIT 100')->fetchAll(),
   'life_events'=>$db->sql('SELECT * FROM hunt_life_events ORDER BY at DESC LIMIT 100')->fetchAll(),'leaderboard'=>leaderboard($db,$e)];
 }
 private function saveContent(array $e,array $body,string $actor): array {
  $editRevision=(int)($e['edit_revision']??$e['revision']);
  if(isset($body['edit_revision'])&&$body['edit_revision']!==$editRevision)fail('Content changed. Refresh before saving.',409);
  if($e['published']){
   if(!sharedPool($e)||!isset($body['edit_revision'])||array_diff(array_keys($body),['revision','edit_revision','riddles']))fail('Published routes and rules are locked. Riddle text and hints can still be edited.',409);
   $incoming=$body['riddles']??null;if(!is_array($incoming)||count($incoming)!==count($e['riddles']))fail('Add or remove riddles before publishing',409);
   $indexed=[];foreach($incoming as $r){if(!is_array($r)||!is_string($r['id']??null)||isset($indexed[$r['id']]))fail('Invalid or duplicate riddle');$indexed[$r['id']]=$r;}
   foreach($e['riddles'] as &$r){$new=$indexed[$r['id']]??null;if(!$new)fail('Assigned riddles cannot be replaced during play',409);
    foreach(['title','question','placement','hint','hint_enabled'] as $key)if(array_key_exists($key,$new))$r[$key]=$new[$key];validateRiddleText($r);
   }unset($r);
   // Text updates change the package hash, not the scan revision: queued offline scans remain valid.
   $e['edit_revision']=$editRevision+1;$e['leaderboard_revision']++;$this->db->saveEvent($e);$this->db->audit($actor,'RIDDLE_TEXT_UPDATED',['edit_revision'=>$e['edit_revision']]);
   return ['ok'=>true,'issues'=>readiness($e)];
  }
  if(($body['revision']??null)!==$e['revision'])fail('Content changed. Refresh before saving.',409);
  foreach(['name','riddles','checkpoints','anchors','edges','settings','public_leaderboard'] as $key)if(array_key_exists($key,$body))$e[$key]=$body[$key];
  if(!is_string($e['name'])||strlen($e['name'])<1||strlen($e['name'])>200)fail('Invalid event name');
  if(!is_array($e['settings'])||!isset($e['settings']['start_node'],$e['settings']['route_tolerance'])||$e['settings']['route_tolerance']<0||$e['settings']['route_tolerance']>.5)fail('Invalid route settings');
  if(isset($e['settings']['life_cooldown_ms'])&&!in_array($e['settings']['life_cooldown_ms'],[0,900000],true))fail('Heart cooldown must be disabled or 15 minutes');
  if(isset($e['settings']['opponent_map'])&&!in_array($e['settings']['opponent_map'],['off','coarse','live'],true))fail('Choose live locations, approximate zones or off');
  if(threeRound($e)){
   if(!is_array($e['riddles'])||count($e['riddles'])>200)fail('Enter at most 200 riddles');$ids=[];
   foreach($e['riddles'] as $r){if(!preg_match('/^[a-zA-Z0-9_-]{1,76}$/',(string)($r['id']??''))||isset($ids[$r['id']]))fail('Duplicate or invalid riddle ID');$ids[$r['id']]=true;}
   $e['checkpoints']=$this->db->event()['checkpoints'];prepareV3Content($e,$body);$e['revision']++;$e['edit_revision']=$editRevision+1;
   $this->db->saveEvent($e);$this->db->audit($actor,'CONTENT_UPDATED',['revision'=>$e['revision']]);return ['ok'=>true,'issues'=>readiness($e)];
  }
  foreach(['checkpoints','riddles'] as $collection){
   if(!is_array($e[$collection])||count($e[$collection])>200)fail('Maximum 200 places/clues');
   $ids=[];foreach($e[$collection] as $v){if(!is_array($v)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',(string)($v['id']??''))||isset($ids[$v['id']]))fail('Duplicate/invalid IDs');$ids[$v['id']]=true;}
  }
  $old=array_column($this->db->event()['checkpoints'],null,'id');
  foreach($e['checkpoints'] as &$c){
   if(($e['settings']['map_space']??'')==='atlas-utm46-v1'&&isset($c['lat'],$c['lon'])) {
    $point=atlasPoint((float)$c['lat'],(float)$c['lon']);if(!$point)fail('Place coordinates are outside this campus atlas');$c['x']=$point['x'];$c['y']=$point['y'];
   }
   foreach(['x','y','radius_m'] as $k)if(!isset($c[$k])||!is_numeric($c[$k])||!is_finite((float)$c[$k]))fail('Place coordinates/radius must be finite');
   if($c['x']<0||$c['x']>6000||$c['y']<0||$c['y']>3375||$c['radius_m']<10||$c['radius_m']>150)fail('Place outside map/radius bounds');
   foreach(['lat'=>90,'lon'=>180] as $k=>$limit)if(isset($c[$k])&&(!is_numeric($c[$k])||!is_finite((float)$c[$k])||abs((float)$c[$k])>$limit))fail('Invalid GPS coordinate');
   foreach(['verified','accessible','finish_network_verified'] as $k)if(isset($c[$k])&&!is_bool($c[$k]))fail('Inspection flags must be booleans');
   if(!is_string($c['name']??null)||strlen($c['name'])<1||strlen($c['name'])>200)fail('A place name is required');
   if(!in_array($c['kind']??'checkpoint',['checkpoint','department','hidden','road_node','start'],true))fail('Unknown place kind');
   $c['nonce']=$old[$c['id']]['nonce']??token();$c['description']=substr((string)($c['description']??''),0,2000);
  }unset($c);
  $cp=array_column($e['checkpoints'],null,'id');
  foreach($e['riddles'] as &$r){if(!in_array($r['difficulty']??null,['easy','medium','hard'],true)||!isset($cp[$r['checkpoint_id']??''])||!is_int($r['points']??null)||$r['points']<0||$r['points']>10000||empty($r['title'])||empty($r['question']))fail('Clues need a title, question, difficulty, QR spot and points');unset($r['answers'],$r['validators']);$r['hint']=(string)($r['hint']??'');}unset($r);
  foreach($e['anchors'] as $a)foreach(['x','y','lat','lon'] as $k)if(!isset($a[$k])||!is_numeric($a[$k])||!is_finite((float)$a[$k]))fail('Measured anchors must contain finite coordinates');
  network($e['checkpoints'],$e['edges']);$e['settings']['max_lives']=3;$e['settings']['workflow']='QR_ONLY';$e['revision']++;$this->db->saveEvent($e);$this->db->audit($actor,'CONTENT_UPDATED',['revision'=>$e['revision']]);
  return ['ok'=>true,'issues'=>readiness($e)];
 }
 private function addTeam(array $e,array $body,string $actor): array {
  $this->requireRosterOpen($e);
  if(!is_string($body['name']??null)||isset($body['code'])&&!is_string($body['code'])||isset($body['pin'])&&!is_string($body['pin']))fail('Enter a team name');
  $code=strtoupper(trim((string)($body['code']??'')));$name=trim($body['name']);$pin=(string)($body['pin']??'');
  if($code===''){
   $used=array_fill_keys(array_column($this->db->sql('SELECT code FROM hunt_teams')->fetchAll(),'code'),true);$start=random_int(0,89999);
   for($i=0;$i<90000;$i++){$candidate=(string)(10000+($start+$i)%90000);if(!isset($used[$candidate])){$code=$candidate;break;}}
   if($code==='')fail('All five-digit team IDs are in use',409);
  }
  if($pin==='')$pin=(string)random_int(100000,999999);
  if(!preg_match('/^[A-Z0-9_-]{1,32}$/',$code)||strlen($name)<1||strlen($name)>100||strlen($pin)<6||strlen($pin)>200)fail('Enter a team code, name and PIN of at least six characters');
  if($this->db->sql('SELECT id FROM hunt_teams WHERE code=?',[$code])->fetch())fail('Team code exists',409);
  $id=uid();$exit=pin(10);$d=['rounds'=>(object)[],'last_seq'=>0,'last_hash'=>'','lives_remaining'=>3,'exit_verifier'=>verifier($exit),'login_secret'=>teamLoginSecret($this->db,$pin)];
  $this->db->sql('INSERT INTO hunt_teams(id,code,name,pin,data) VALUES(?,?,?,?,?)',[$id,$code,$name,json(verifier($pin)),json($d)]);$this->db->audit($actor,'TEAM_CREATED',['id'=>$id,'code'=>$code]);
  teamAvatars($this->db,$this->db->teams());
  $this->rosterChanged($e);
  return ['id'=>$id,'name'=>$name,'code'=>$code,'enrollment_pin'=>$pin,'offline_exit_pin'=>$exit];
 }
 private function importRoster(array $e,array $b,string $actor): array {
  if(array_key_exists('names',$b)){
   $names=is_string($b['names'])?preg_split('/\R/u',$b['names']):$b['names'];
   if(!is_array($names))fail('Enter one team name per line');$rows=[];$seen=[];
   foreach($this->db->teams() as $t)$seen[mb_strtolower(trim($t['name']))]=true;
   foreach($names as $name){if(!is_string($name))fail('Enter one team name per line');$name=trim($name);if($name==='')continue;$key=mb_strtolower($name);if(isset($seen[$key]))fail('Team name already listed: '.$name,409);$seen[$key]=true;$rows[]=['name'=>$name];}
   $b['rows']=$rows;
  }
  $rows=$b['rows']??null;if($rows===null&&isset($b['csv'])){$f=fopen('php://temp','r+');fwrite($f,ltrim($b['csv'],"\xEF\xBB\xBF"));rewind($f);$header=fgetcsv($f,0,',','"','');$rows=[];while(($v=fgetcsv($f,0,',','"',''))!==false){if(count($v)!==count($header))fail('Malformed CSV row');$rows[]=array_combine($header,$v);}fclose($f);}
  if(!is_array($rows)||count($rows)<1||count($rows)>100)fail('Import 1–100 team rows');$accounts=[];foreach($rows as $row)$accounts[]=$this->addTeam($e,$row,$actor);return ['accounts'=>$accounts];
 }
 private function editTeam(array $e,string $id,array $b,string $actor): array {
  $t=$this->db->team($id);$name=trim((string)($b['name']??$t['name']));$password=(string)($b['password']??'');
  if(mb_strlen($name)<1||mb_strlen($name)>100||($password!==''&&(strlen($password)<6||strlen($password)>200)))fail('Enter a team name and a password of 6–200 characters');
  $this->db->sql('UPDATE hunt_teams SET name=? WHERE id=?',[$name,$id]);
  if($password!==''){$this->db->sql('UPDATE hunt_teams SET pin=? WHERE id=?',[json(verifier($password)),$id]);$t['data']['login_secret']=teamLoginSecret($this->db,$password);}
  $t['name']=$name;$this->db->saveTeam($t);
  $e['leaderboard_revision']++;$this->db->saveEvent($e);$this->db->audit($actor,'TEAM_ACCOUNT_UPDATED',['team_id'=>$id,'password_changed'=>$password!=='']);return ['ok'=>true];
 }
 private function requireRosterOpen(array $e): void {
  foreach($e['rounds'] as $round)if($round['status']!=='DRAFT')fail('The team roster locks when a round starts',409);
  if((int)$this->db->sql("SELECT COUNT(*) FROM hunt_actions WHERE result NOT IN ('RECORDED','WRONG_RUN')")->fetchColumn()>0)fail('Scan evidence prevents roster changes',409);
 }
 private function rosterChanged(array $e): void {
  $db=$this->db;$e['leaderboard_revision']++;
  if($e['published']){
   $teams=$db->teams();if(!$teams)$e['published']=false;$assigned=routes($e,array_column($teams,'id'),1);
   foreach($teams as $team){$p=&progress($team,1);$p['route']=$assigned[$team['id']]['riddles'];$p['route_meters']=$assigned[$team['id']]['meters'];$team['data']['ready']=false;unset($team['data']['package_ack']);$db->saveTeam($team);unset($p);}
   $e['revision']++;$e['edit_revision']=($e['edit_revision']??$e['revision'])+1;
  }
  $db->saveEvent($e);
 }
 private function removeTeam(array $e,string $id,string $actor): array {
  $this->requireRosterOpen($e);$db=$this->db;$team=$db->team($id);
  $db->sql("DELETE FROM hunt_sessions WHERE subject=? AND kind='team'",[$id]);
  $db->sql('DELETE FROM hunt_commands WHERE team_id=?',[$id]);$db->sql('DELETE FROM hunt_progress WHERE team_id=?',[$id]);
  $db->sql("DELETE FROM hunt_actions WHERE team_id=? AND result='RECORDED'",[$id]);
  $db->sql('DELETE FROM hunt_teams WHERE id=?',[$id]);$this->rosterChanged($e);
  $db->audit($actor,'TEAM_REMOVED',['team_id'=>$id,'code'=>$team['code'],'name'=>$team['name']]);return ['ok'=>true];
 }
 private function credentialTeam(string $code,string $pin): ?array {
  $row=$this->db->sql('SELECT id,pin FROM hunt_teams WHERE code=?',[strtoupper(trim($code))])->fetch();
  if(!$row||!matches($pin,parse($row['pin'])))return null;
  $team=$this->db->team($row['id']);
  if(!isset($team['data']['login_secret'])){$team['data']['login_secret']=teamLoginSecret($this->db,$pin);$this->db->saveTeam($team);}
  return $team;
 }
 private function reconnect(array $body): array {
  return $this->transaction(function()use($body){
   $db=$this->db;$db->event();$hash=hash('sha256',str_starts_with($this->bearer,'Bearer ')?substr($this->bearer,7):'');
   $session=$db->sql("SELECT subject FROM hunt_sessions WHERE hash=? AND kind='team'".$db->lock(),[$hash])->fetch();
   if(!$session)fail('Phone access was revoked. Sign in again or ask for a supervised handover.',401);
   $team=$db->team($session['subject']);$d=$team['data'];
   try{$proof=verify($d['public_key']??'', $body['proof']??[]);}catch(\Throwable $x){fail('This phone cannot prove its enrolled identity',401);}
   if(($proof['kind']??'')!=='RECONNECT'||($proof['device_id']??null)!==($d['device_id']??null)||($proof['session_hash']??'')!==$hash)fail('Phone identity changed. Ask for a supervised handover.',401);
   // Consume the old session: a captured proof cannot be replayed to issue another token.
   $db->sql('DELETE FROM hunt_sessions WHERE hash=?',[$hash]);$token=token();
   $db->sql('INSERT INTO hunt_sessions VALUES(?,?,?,?)',[hash('sha256',$token),$team['id'],'team',stamp()+43200000]);
   return ['token'=>$token];
  });
 }
 private function enroll(array $b): array {
  $code=strtoupper(trim((string)($b['code']??'')));$this->throttle('team',$code);$public=(string)($b['public_key']??'');
  $details=openssl_pkey_get_details(validPublic($public));$public=preg_replace('/-----[^-]+-----|\s/','',$details['key']);
  $device=(string)($b['device_id']??'');if(!preg_match('/^[a-f0-9-]{36}$/i',$device))fail('Invalid device ID');
  return $this->transaction(function()use($b,$code,$public,$device){
   $db=$this->db;$e=$db->event();if((int)($b['client_version']??0)<max(sharedPool($e)?18:0,(int)($e['settings']['min_android_version']??0)))fail('Install the current Anazori event APK before enrolling.',426);
   $t=$this->credentialTeam($code,(string)($b['pin']??''));if(!$t)fail('Invalid team code or PIN',401);
   if(!isset($t['data']['avatar_id'])){teamAvatars($db,$db->teams());$t=$db->team($t['id']);}$d=&$t['data'];
   $other=$db->sql('SELECT id FROM hunt_teams WHERE id<>? AND (device_id=? OR public_fingerprint=?)',[$t['id'],$device,hash('sha256',$public)])->fetch();if($other)fail('Phone already belongs to another team',409);
   if(isset($d['public_key'])&&($d['public_key']!==$public||$d['device_id']!==$device))fail('This login belongs to a different app installation. On the dashboard use Teams > Manage > Replace phone, then use its new login code. Do not uninstall or clear app data to retry.',409);
   $d['device_id']=$device;$d['public_key']=$public;$d['last_seen']=stamp();$d['client_version']=(int)($b['client_version']??0);$d['lives_remaining']??=3;
   $content=contentPackage($db,$e,$t);$db->saveTeam($t);$db->sql("DELETE FROM hunt_sessions WHERE subject=? AND kind='team'",[$t['id']]);$token=token();
   $db->sql('INSERT INTO hunt_sessions VALUES(?,?,?,?)',[hash('sha256',$token),$t['id'],'team',stamp()+43200000]);$db->audit($code,'ENROLLED',['device_id'=>$device]);
   $rounds=[];foreach(roundNumbers($e) as $n)$rounds[$n]=snapshot($e['rounds'][$n]);
   return ['token'=>$token,'organizer_public_key'=>$db->public,'package'=>signed($db->key,$content),'package_hash'=>hash('sha256',json($content)),
    'server_time'=>stamp(),'last_seq'=>$d['last_seq'],'last_hash'=>$d['last_hash'],'ack'=>[],'rounds'=>$rounds,'progress'=>(object)$d['rounds'],
    'commands'=>[],'lives_remaining'=>$d['lives_remaining'],'eliminated'=>!empty($d['eliminated_at']),'elimination_reason'=>$d['elimination_reason']??null,
    'needs_review'=>$d['needs_review']??false,'hints_used'=>$d['hints_used']??[],'cooldown_until'=>$d['cooldown_until']??null,'cooldown_trigger_seq'=>$d['cooldown_trigger_seq']??null,'leaderboard'=>participantLeaderboard($db,$e),'opponents'=>opponentSnapshot($db,$e,$t['id'])];
  });
 }
 private function sync(array $b,string $id,bool $import=false): array {
  $events=$b['events']??[];if(!is_array($events)||count($events)>32)fail('Maximum 32 actions per batch');
  if(isset($b['pending'])&&(!is_int($b['pending'])||$b['pending']<0||$b['pending']>100000))fail('Invalid pending count');
  $applied=$b['applied_commands']??[];if(!is_array($applied)||count($applied)>32)fail('Invalid command acknowledgements');
  return $this->transaction(function()use($b,$id,$events,$applied,$import){
   $db=$this->db;$e=$db->event();if(!$import&&(int)($b['client_version']??0)<max(sharedPool($e)?18:0,(int)($e['settings']['min_android_version']??0)))fail('Update the Anazori event APK before synchronizing.',426);expire($db,$e);$t=$db->team($id);
   if(!isset($t['data']['avatar_id'])){teamAvatars($db,$db->teams());$t=$db->team($id);}$d=&$t['data'];
   if(!$import&&($b['run_id']??'')!==($e['run_id']??'')){$events=[];$applied=[];}
   if(($b['device_id']??null)!==($d['device_id']??null))fail('Device has been replaced',401);$ack=[];
   foreach($events as $envelope){
    try{
     $a=verify($d['public_key'],$envelope);if(!preg_match('/^[a-f0-9-]{36}$/i',(string)($a['id']??''))||!is_int($a['seq']??null)||$a['seq']<1)fail('Invalid evidence identity');
     // An exported phone archive can include a previous run with a retired sequence cursor.
     if($import&&(($a['event_id']??null)!==$e['id']||($a['run_id']??'')!==($e['run_id']??''))){$ack[]=['id'=>$a['id'],'result'=>'WRONG_RUN'];continue;}
     if(($a['team_id']??null)!==$id||($a['device_id']??null)!==$d['device_id'])fail('Invalid evidence identity');
    }catch(\Throwable $x){fail('Invalid action signature or identity');}
    $hash=hash('sha256',$envelope['payload']);$old=$db->sql('SELECT * FROM hunt_actions WHERE id=?',[$a['id']])->fetch();
    if($old){if($old['team_id']!==$id||!hash_equals($old['hash'],$hash))fail('Action ID conflicts with prior evidence',409);$ack[]=['id'=>$a['id'],'result'=>$old['result']];continue;}
    if($a['seq']!==($d['last_seq']??0)+1||($a['prev_hash']??'')!==($d['last_hash']??''))fail(['message'=>'Action sequence gap/conflict','expected'=>($d['last_seq']??0)+1],409);
    $result=applyAction($db,$e,$t,$a);$db->sql('INSERT INTO hunt_actions(id,team_id,seq,hash,data,result,received) VALUES(?,?,?,?,?,?,?)',[$a['id'],$id,$a['seq'],$hash,json($a),$result,stamp()]);
    $d['last_seq']=$a['seq'];$d['last_hash']=$hash;$ack[]=['id'=>$a['id'],'result'=>$result];
    if(in_array($result,['CHECKPOINT_ACCEPTED','WRONG_SEQUENCE','ELIMINATED','COOLDOWN_STARTED'],true))$e['leaderboard_revision']++;
    if($result==='REVIEW_REQUIRED')$d['needs_review']=true;
   }
   refreshHearts($db,$e,$t);
   if(!$import){
    $l=$b['location']??null;
    if(is_array($l)&&isset($l['lat'],$l['lon'],$l['accuracy'])&&is_numeric($l['lat'])&&is_numeric($l['lon'])&&is_numeric($l['accuracy'])&&is_finite((float)$l['lat'])&&is_finite((float)$l['lon'])&&is_finite((float)$l['accuracy'])&&abs($l['lat'])<=90&&abs($l['lon'])<=180&&$l['accuracy']>=0&&$l['accuracy']<=10000&&is_numeric($l['age_ms']??0)&&($l['age_ms']??0)>=0&&($l['age_ms']??0)<=15000){
     $received=stamp();$point=['lat'=>(float)$l['lat'],'lon'=>(float)$l['lon'],'accuracy'=>(float)$l['accuracy'],'mock'=>(bool)($l['mock']??false),'received_at'=>$received,'sampled_at'=>$received-(int)($l['age_ms']??0)];
     if($point['sampled_at']>=($d['location']['sampled_at']??$d['location']['received_at']??0))rememberLocation($d,$point);
    }
    $d['client_version']=(int)($b['client_version']??0);$d['kiosk']=(bool)($b['kiosk']??false);$d['ready']=($b['ready']??false)===true;$d['last_seen']=stamp();$d['pending_actions']=$b['pending']??0;
    $roundAck=$b['round_ack']??[];if(!is_array($roundAck)||count($roundAck)>3)fail('Invalid round acknowledgement');
    foreach($roundAck as $number=>$version)if(!in_array((int)$number,[1,2,3],true)||!is_int($version)||$version<0)fail('Invalid round acknowledgement');
    $d['round_ack']=$roundAck;
   }
   $confirmed=[];foreach($applied as $command){if(!is_string($command))fail('Invalid command ID');$db->sql('UPDATE hunt_commands SET acknowledged=? WHERE id=? AND team_id=? AND acknowledged IS NULL',[stamp(),$command,$id]);$r=$db->sql('SELECT acknowledged FROM hunt_commands WHERE id=? AND team_id=?',[$command,$id])->fetch();if($r&&$r['acknowledged'])$confirmed[]=$command;}
   $commands=[];foreach($db->sql('SELECT data FROM hunt_commands WHERE team_id=? AND acknowledged IS NULL ORDER BY created_at LIMIT 32',[$id])->fetchAll() as $row){$c=parse($row['data']);if($c['expires_at']>stamp())$commands[]=signed($db->key,$c);}
   $content=contentPackage($db,$e,$t);$hash=hash('sha256',json($content));if(($b['package_hash']??'')===$hash)$d['package_ack']=$hash;
   if(!$import)$db->sql("UPDATE hunt_sessions SET expires=? WHERE hash=? AND kind='team'",[stamp()+43200000,hash('sha256',substr($this->bearer,7))]);
   $db->saveTeam($t);$db->saveEvent($e);$rounds=[];foreach(roundNumbers($e) as $n)$rounds[$n]=snapshot($e['rounds'][$n]);
   $out=['ack'=>$ack,'last_seq'=>$d['last_seq'],'last_hash'=>$d['last_hash'],'server_time'=>stamp(),'rounds'=>$rounds,'progress'=>(object)$d['rounds'],'lives_remaining'=>$d['lives_remaining']??3,'eliminated'=>!empty($d['eliminated_at']),'elimination_reason'=>$d['elimination_reason']??null,
    'needs_review'=>$d['needs_review']??false,'hints_used'=>$d['hints_used']??[],'cooldown_until'=>$d['cooldown_until']??null,'cooldown_trigger_seq'=>$d['cooldown_trigger_seq']??null,'commands'=>$commands,'acknowledged_commands'=>$confirmed];
   $opponents=opponentSnapshot($db,$e,$id);if(($b['opponents_revision']??'')!==$opponents['revision'])$out['opponents']=$opponents;
   if(($b['package_hash']??'')!==$hash){$out['package']=signed($db->key,$content);$out['package_hash']=$hash;}
   if(($b['leaderboard_revision']??-1)!==$e['leaderboard_revision'])$out['leaderboard']=participantLeaderboard($db,$e);return $out;
  });
 }
 private function roundControl(array $e,int $n,string $op,string $actor): array {
  if(!in_array($n,roundNumbers($e),true))fail('Invalid round');
  if(threeRound($e))return roundControlV3($this->db,$e,$n,$op,$actor);
  $db=$this->db;$r=&$e['rounds'][$n];if(!$e['published'])fail('Publish verified content first',409);$teams=$db->teams();
  if($op==='prepare'){
   if($n!==2||$r['status']!=='DRAFT'||$e['rounds'][1]['status']!=='ENDED')fail('End round 1 before preparing round 2',409);
   $qualified=[];$excluded=[];foreach($teams as $t){$p=progress($t,1);if($p['status']==='QUALIFIED'&&empty($t['data']['eliminated_at'])){$qualified[]=$t['id'];$excluded[$t['id']]=$p['route'];}}
   if(!$qualified)fail('No qualified teams',409);$routes=routes($e,$qualified,2,$excluded);
   foreach($teams as $t)if(isset($routes[$t['id']])){$p=&progress($t,2);$p['route']=$routes[$t['id']]['riddles'];$p['route_meters']=$routes[$t['id']]['meters'];$db->saveTeam($t);unset($p);}
  }elseif($op==='start'){
   if($r['status']!=='DRAFT')fail('Round already started',409);if($n===2&&$e['rounds'][1]['status']!=='ENDED')fail('End round 1 first',409);
   $eligible=[];$cp=array_column($e['checkpoints'],null,'id');$start=$cp[$e['settings']['start_node']];
   foreach($teams as $t){
    if(!empty($t['data']['eliminated_at'])||($n===2&&progress($t,1)['status']!=='QUALIFIED'))continue;$p=&progress($t,$n);$d=$t['data'];
    if(!$p['route'])fail('Prepare and download assignments first',409);
    if(empty($d['device_id'])||empty($d['ready'])||(($e['settings']['require_kiosk']??true)&&empty($d['kiosk'])))fail('Phone not ready: '.$t['code'],409);
    if(($d['package_ack']??null)!==hash('sha256',json(contentPackage($db,$e,$t))))fail('Assignment not acknowledged: '.$t['code'],409);
    $l=$d['location']??[];if(!$l||stamp()-$l['received_at']>60000||$l['mock']||$l['accuracy']>25||meters($l,$start)>$start['radius_m'])fail('Fresh starting-grid GPS needed: '.$t['code'],409);
    $p['status']='PLAYING';$db->saveTeam($t);$eligible[]=$t['id'];unset($p);
   }
   if(!$eligible)fail('No eligible teams',409);$r['status']='RUNNING';$r['started_at']=stamp();
  }elseif($op==='pause'){
   if($r['status']!=='RUNNING')fail('Round not running',409);$r['status']='PAUSED';$r['paused_at']=stamp();
  }elseif($op==='resume'){
   if($r['status']!=='PAUSED')fail('Round not paused',409);$r['pause_intervals'][]=[$r['paused_at'],stamp()];$r['paused_ms']+=stamp()-$r['paused_at'];$r['paused_at']=null;$r['status']='RUNNING';
  }else{
   if(!in_array($r['status'],['RUNNING','PAUSED'],true))fail('Round is not active',409);$r['status']='ENDED';
   foreach($teams as $t){$p=&progress($t,$n);if(in_array($p['status'],['WAITING','PLAYING'],true))$p['status']=$n===1?'ELIMINATED':'TIME_OVER';$db->saveTeam($t);unset($p);}
  }
  if($op!=='prepare')$r['control_version']++;$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'ROUND_'.strtoupper($op),['round'=>$n]);return ['ok'=>true,'round'=>snapshot($r)];
 }
 private function teamControl(array $e,string $id,array $b,string $actor): array {
  $db=$this->db;$reason=trim((string)($b['reason']??''));if(strlen($reason)<8)fail('Enter an audit reason of at least eight characters');$op=$b['operation']??'';$t=$db->team($id);$d=&$t['data'];$out=['ok'=>true];
  if($op==='reset_device'){
   $db->sql("DELETE FROM hunt_sessions WHERE subject=? AND kind='team'",[$id]);unset($d['device_id'],$d['public_key'],$d['package_ack']);$d['kiosk']=false;$pin=pin(8);$db->sql('UPDATE hunt_teams SET pin=? WHERE id=?',[json(verifier($pin)),$id]);$d['login_secret']=teamLoginSecret($db,$pin);$out['new_enrollment_pin']=$pin;
  }elseif($op==='rotate_exit'){$pin=pin(10);$d['exit_verifier']=verifier($pin);$out['offline_exit_pin']=$pin;
  }elseif($op==='restore_lives'){
   $lives=$b['lives']??3;if(!is_int($lives)||$lives<1||$lives>3)fail('Restore one to three lives');$d['lives_remaining']=$lives;unset($d['cooldown_until']);
   if(($d['elimination_reason']??'')==='THREE_WRONG_SEQUENCE_SCANS'){
    if(isset($d['pre_elimination_progress']))$d['rounds']=$d['pre_elimination_progress'];
    unset($d['eliminated_at'],$d['elimination_reason'],$d['pre_elimination_progress']);
   }
  }elseif($op==='reinstate'){
   if(!in_array($d['elimination_reason']??'',['ADMIN_ELIMINATED','ADMIN_DISQUALIFIED'],true))fail('Use Restore lives for a three-scan elimination',409);
   if(!isset($d['pre_elimination_progress']))fail('No saved progress is available for reinstatement',409);
   $d['rounds']=$d['pre_elimination_progress'];unset($d['eliminated_at'],$d['elimination_reason'],$d['pre_elimination_progress']);
  }elseif(in_array($op,['exit','notice','resume_kiosk'],true)){
   if(empty($d['device_id']))fail('Team has no enrolled phone',409);$c=['id'=>uid(),'event_id'=>$e['id'],'device_id'=>$d['device_id'],'kind'=>strtoupper($op),'message'=>substr((string)($b['message']??$reason),0,500),'expires_at'=>stamp()+900000];
   $db->sql('INSERT INTO hunt_commands(id,team_id,data,acknowledged,created_at) VALUES(?,?,?,NULL,?)',[$c['id'],$id,json($c),stamp()]);$out+=['command_id'=>$c['id'],'delivery'=>'PENDING'];
  }elseif(in_array($op,['qualify','eliminate','disqualify','manual_finish'],true)){
   $n=(int)($b['round']??1);if(!in_array($n,roundNumbers($e),true))fail('Invalid round');$p=&progress($t,$n);
   if(threeRound($e)&&in_array($op,['manual_finish','qualify'],true)){
    if(!empty($d['eliminated_at'])||($d['lives_remaining']??3)<=0||count($p['route'])!==roundTotal($e,$n))fail('Team has no eligible assignment',409);
    if(($op==='manual_finish'&&$n!==finalRound($e))||($op==='qualify'&&($n===finalRound($e)||$e['rounds'][$n+1]['status']!=='DRAFT')))fail('Invalid qualification or final round',409);
    if($p['finished_at']!==null)fail('Team already completed this round',409);
    if($n<finalRound($e)&&$e['rounds'][$n]['qualify_mode']==='first_n'){
     $count=0;foreach($db->teams() as $other)if(progress($other,$n)['status']==='QUALIFIED')$count++;
     if($count>=$e['rounds'][$n]['qualify_limit'])fail('Qualification limit is full',409);
    }
    $p['completed']=roundTotal($e,$n);$p['score']=$p['completed']*100;finishRoundV3($db,$e,$t,$n);
   }
   elseif($op==='manual_finish'){if($n!==2||!empty($d['eliminated_at'])||($d['lives_remaining']??3)<=0||count($p['route'])!==7)fail('Only an assigned, non-eliminated round-two team can finish',409);$p['status']='FINISHED';$p['completed']=7;$p['finished_at']=stamp();}
   elseif($op==='qualify'){if($n!==1||$e['rounds'][2]['status']!=='DRAFT'||!empty($d['eliminated_at']))fail('Resolve elimination before qualification',409);$p['status']='QUALIFIED';foreach($db->teams() as $other){$q=&progress($other,2);$q['route']=[];$db->saveTeam($other);}progress($t,2)['route']=[];}
   else{if(empty($d['eliminated_at']))$d['pre_elimination_progress']=parse(json($d['rounds']));$d['eliminated_at']=stamp();$d['elimination_reason']=$op==='disqualify'?'ADMIN_DISQUALIFIED':'ADMIN_ELIMINATED';foreach(roundNumbers($e) as $round){$q=&progress($t,$round);$q['status']=$op==='disqualify'?'DISQUALIFIED':'ELIMINATED';}}
   unset($p,$q);
  }else fail('Unknown team operation');
  $db->saveTeam($t);$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'TEAM_'.strtoupper($op),['team_id'=>$id,'reason'=>$reason,'lives_remaining'=>$d['lives_remaining']??3]);return $out;
 }
 private function review(array $e,string $id,array $b,string $actor): array {
  $reason=trim((string)($b['reason']??''));if(strlen($reason)<8)fail('A meaningful review reason is required');$db=$this->db;$t=$db->team($id);$results=[];
  foreach($db->sql("SELECT * FROM hunt_actions WHERE team_id=? AND result='REVIEW_REQUIRED' ORDER BY seq",[$id])->fetchAll() as $r){$value=applyAction($db,$e,$t,parse($r['data']),true);$db->sql('UPDATE hunt_actions SET result=? WHERE id=?',[$value,$r['id']]);$results[]=['id'=>$r['id'],'result'=>$value];}
  $t['data']['needs_review']=false;
  foreach(roundNumbers($e) as $n){$p=&progress($t,$n);if($e['rounds'][$n]['status']==='ENDED'&&$p['status']==='PLAYING')$p['status']=$n===1?'ELIMINATED':'TIME_OVER';}unset($p);
  $db->saveTeam($t);$e['leaderboard_revision']++;$db->saveEvent($e);$db->audit($actor,'LATE_EVIDENCE_REVIEWED',['team_id'=>$id,'reason'=>$reason,'results'=>$results]);return ['ok'=>true,'reviewed'=>$results];
 }
}

