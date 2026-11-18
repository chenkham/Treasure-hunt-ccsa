<?php
declare(strict_types=1);
// Test-only CLI bridge. The web router and Apache deny this directory.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$config=require (string)getenv('HUNT_TEST_CONFIG');
if(($config['test_only']??false)!==true||!str_starts_with($config['dsn'],'mysql:host=127.0.0.1;port=3339;dbname=hunt_local'))throw new RuntimeException('Disposable test database required');
$db=new \Hunt\Database($config);
$in=\Hunt\parse(stream_get_contents(STDIN));
$db->begin();
try {
 if($in['op']==='snapshot'){
  $out=['event'=>$db->event(),'teams'=>$db->teams(),'sessions'=>$db->sql("SELECT * FROM hunt_sessions WHERE kind='team'")->fetchAll()];
 }elseif($in['op']==='reset'){
  foreach(['hunt_life_events','hunt_actions','hunt_progress','hunt_commands'] as $table)$db->sql("DELETE FROM $table");
  $db->sql("DELETE FROM hunt_sessions WHERE kind='team'");
  $db->sql('DELETE FROM hunt_rate_limits');
  $db->sql('DELETE FROM hunt_teams');
  $v=$in['value'];$db->saveEvent($v['event']);
  foreach($v['teams'] as $t){$db->sql('INSERT INTO hunt_teams(id,code,name,pin,data) VALUES(?,?,?,?,?)',[$t['id'],$t['code'],$t['name'],$t['pin'],\Hunt\json($t['data'])]);$db->saveTeam($t);}
  foreach($v['sessions'] as $r)$db->sql('INSERT INTO hunt_sessions VALUES(?,?,?,?)',array_values($r));
  $out=['ok'=>true];
 }elseif($in['op']==='state'){$out=['event'=>$db->event(),'team'=>$db->team($in['id']),'lives_events'=>(int)$db->sql('SELECT COUNT(*) FROM hunt_life_events')->fetchColumn()];}
 elseif($in['op']==='event'){$db->saveEvent($in['value']);$out=['ok'=>true];}
 else throw new RuntimeException('Unsupported test operation');
 $db->commit();echo \Hunt\json($out);
}catch(Throwable $x){$db->rollback();throw $x;}
