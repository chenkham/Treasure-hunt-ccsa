<?php
declare(strict_types=1);
namespace Hunt;
function install(array $config,string $username,string $password): void {
 if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$username)||strlen($password)<12)fail('Choose an administrator name and a password of at least 12 characters');
 $db=new Database($config,false);$schema=file_get_contents(dirname(__DIR__).'/database/schema.mysql.sql');
 if(!$db->mysql){
  $schema=preg_replace('/ ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/','',$schema);
  $schema=preg_replace('/id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY/','id INTEGER PRIMARY KEY AUTOINCREMENT',$schema);
  $schema=preg_replace('/UNIQUE KEY action_sequence\\(team_id,seq\\)/','UNIQUE(team_id,seq)',$schema);
  $schema=preg_replace('/,INDEX [a-z_]+\\([^)]*\\)/','',$schema);
 }
 // MySQL DDL commits implicitly. Serialize installation separately; never drop existing tables.
 $dir=rtrim($config['private_dir'],'/\\');if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new \RuntimeException('Cannot create private directory');
 $lock=fopen($dir.'/install.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Cannot lock installation');
 try{
  foreach(explode(';',$schema) as $statement)if(trim($statement)!=='')$db->pdo->exec($statement);
  if($db->sql('SELECT username FROM hunt_admins LIMIT 1')->fetch())fail('Installation already completed; existing data was preserved',409);
  $keyFile=$dir.'/organizer-key.pem';
  if(!is_file($keyFile)){$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$key||!openssl_pkey_export($key,$pem))throw new \RuntimeException('OpenSSL cannot generate a P-256 key');file_put_contents($keyFile,$pem,LOCK_EX);@chmod($keyFile,0600);}
  $db->begin();
  try{
   $seed=parse(file_get_contents(dirname(__DIR__).'/database/seed.json'));
   foreach($seed['checkpoints'] as &$c){$c['nonce']=token();$c['kind']??='department';$c['description']??='';}unset($c);
   foreach($seed['riddles'] as &$r)unset($r['answers'],$r['validators']);unset($r);
   $round=fn($minutes)=>['status'=>'DRAFT','duration_ms'=>$minutes*60000,'started_at'=>null,'paused_at'=>null,'paused_ms'=>0,'pause_intervals'=>[],'control_version'=>0];
   $event=['id'=>uid(),'revision'=>1,'protocol'=>2,'name'=>'Anazori Campus Expedition','published'=>false,'public_leaderboard'=>false,'leaderboard_revision'=>0,
    'settings'=>array_merge(['route_tolerance'=>.2,'start_node'=>'ccsa','require_kiosk'=>true,'organizer_phone'=>'','max_lives'=>3,'workflow'=>'QR_ONLY','life_cooldown_ms'=>900000,'opponent_map'=>'live'],$seed['settings']??[]),
    'checkpoints'=>$seed['checkpoints'],'riddles'=>$seed['riddles'],'anchors'=>[],'edges'=>$seed['edges']??[],'rounds'=>[1=>$round(60),2=>$round(90)]];
   if(threeRound($event)){$event['rounds']=defaultRounds();$event['protocol']=3;$event['settings']['assignment_seed']=token();}
   if(sharedPool($event)){prepareV3Content($event,[]);$event['content_bundle']=$seed['content_version']??'';}
   if(!$db->sql('SELECT id FROM hunt_events WHERE id=1')->fetch())$db->sql('INSERT INTO hunt_events(id,data,schema_version) VALUES(1,?,2)',[json($event)]);
   $db->sql('INSERT INTO hunt_admins VALUES(?,?)',[$username,password_hash($password,PASSWORD_DEFAULT)]);$db->audit($username,'INSTALLED',['protocol'=>2]);$db->commit();
  }catch(\Throwable $x){$db->rollback();throw $x;}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
