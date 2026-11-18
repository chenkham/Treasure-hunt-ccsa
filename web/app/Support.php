<?php
declare(strict_types=1);
namespace Hunt;
final class HttpError extends \RuntimeException {
 public function __construct(public mixed $detail,public int $status=400) { parent::__construct(is_string($detail)?$detail:'Request rejected'); }
}
function fail(mixed $detail,int $status=400): never { throw new HttpError($detail,$status); }
function stamp(): int { return (int)floor(microtime(true)*1000); }
function json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function parse(string $value): mixed { return json_decode($value,true,128,JSON_THROW_ON_ERROR); }
function uid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
function pin(int $length): string { $s='';for($i=0;$i<$length;$i++)$s.=(string)random_int(0,9);return $s; }
function b64decode(string $value): string { $v=base64_decode($value,true);if($v===false)fail('Invalid base64');return $v; }
function verifier(string $value): array { $salt=random_bytes(16);return ['salt'=>base64_encode($salt),'iterations'=>310000,'value'=>base64_encode(hash_pbkdf2('sha256',$value,$salt,310000,32,true))]; }
function matches(string $value,array $saved): bool { return isset($saved['salt'],$saved['iterations'],$saved['value']) && hash_equals($saved['value'],base64_encode(hash_pbkdf2('sha256',$value,b64decode($saved['salt']),(int)$saved['iterations'],32,true))); }
// Only team login codes are recoverable. Administrator passwords remain one-way hashes.
function teamLoginSecret(Database $db,?string $code=null,?array $saved=null): array|string|null {
 if(!openssl_pkey_export($db->key,$pem))throw new \RuntimeException('Cannot access the private credential key');
 $key=hash('sha256',"hunt-team-login-v1\0".$pem,true);
 if($code!==null){$iv=random_bytes(12);$encrypted=openssl_encrypt($code,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'team-login');if($encrypted===false)throw new \RuntimeException('Cannot protect team login');return ['iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($encrypted)];}
 if(!$saved)return null;
 $value=openssl_decrypt(b64decode($saved['ciphertext']),'aes-256-gcm',$key,OPENSSL_RAW_DATA,b64decode($saved['iv']),b64decode($saved['tag']),'team-login');
 if($value===false)throw new \RuntimeException('Team login cannot be recovered with this server key');return $value;
}
function pem(string $der): string { return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(b64decode($der)),64,"\n")."-----END PUBLIC KEY-----\n"; }
function validPublic(string $public): \OpenSSLAsymmetricKey { $k=@openssl_pkey_get_public(pem($public));$d=$k?openssl_pkey_get_details($k):false;if(!$d||$d['type']!==OPENSSL_KEYTYPE_EC||($d['ec']['curve_name']??'')!=='prime256v1')fail('Expected a P-256 device key');return $k; }
function verify(string $public,array $envelope): array { $raw=b64decode((string)($envelope['payload']??''));if(strlen($raw)>200000||openssl_verify($raw,b64decode((string)($envelope['signature']??'')),validPublic($public),OPENSSL_ALGO_SHA256)!==1)fail('Invalid evidence signature');$p=parse($raw);if(!is_array($p))fail('Expected signed object');return $p; }
function signed(\OpenSSLAsymmetricKey $key,array $payload): array { $raw=json($payload);if(!openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256))throw new \RuntimeException('Cannot sign event data');return ['payload'=>base64_encode($raw),'signature'=>base64_encode($signature)]; }

final class Database {
 public \PDO $pdo;
 public bool $mysql;
 private bool $transaction=false;
 public \OpenSSLAsymmetricKey $key;
 public string $public;
 public function __construct(public array $config,bool $loadKey=true) {
  $this->pdo=new \PDO($config['dsn'],$config['username']??null,$config['password']??null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
  $this->mysql=$this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
  if(!$this->mysql && !($config['allow_sqlite_preview']??false))throw new \RuntimeException('Use PDO MySQL for production');
  if($this->mysql)$this->pdo->exec("SET time_zone='+00:00'");else $this->pdo->exec('PRAGMA foreign_keys=ON');
  if($loadKey) {
   $path=rtrim($config['private_dir'],'/\\').'/organizer-key.pem';
   $key=@openssl_pkey_get_private((string)@file_get_contents($path));if(!$key)throw new \RuntimeException('Initialize the organizer signing key first');$this->key=$key;
   $details=openssl_pkey_get_details($key);$this->public=preg_replace('/-----[^-]+-----|\s/','',$details['key']);
  }
 }
 public function sql(string $query,array $args=[]): \PDOStatement { $q=$this->pdo->prepare($query);$q->execute($args);return $q; }
 public function lock(): string { return $this->mysql?' FOR UPDATE':''; }
 public function begin(): void { if($this->transaction)throw new \RuntimeException('Nested transaction');if($this->mysql)$this->pdo->beginTransaction();else $this->pdo->exec('BEGIN IMMEDIATE');$this->transaction=true; }
 public function commit(): void { if($this->mysql)$this->pdo->commit();else $this->pdo->exec('COMMIT');$this->transaction=false; }
 public function rollback(): void { if($this->transaction){if($this->mysql)$this->pdo->rollBack();else $this->pdo->exec('ROLLBACK');$this->transaction=false;} }
 public function event(): array { $r=$this->sql('SELECT data FROM hunt_events WHERE id=1'.$this->lock())->fetch();if(!$r)fail('Initialize the event first',503);return parse($r['data']); }
 public function saveEvent(array $e): void { $this->sql('UPDATE hunt_events SET data=? WHERE id=1',[json($e)]); }
 public function team(string $id): array { $r=$this->sql('SELECT * FROM hunt_teams WHERE id=?'.$this->lock(),[$id])->fetch();if(!$r)fail('Team not found',404);$r['data']=parse($r['data']);return $r; }
 public function teams(): array { return array_map(function($r){$r['data']=parse($r['data']);return $r;},$this->sql('SELECT * FROM hunt_teams ORDER BY id'.$this->lock())->fetchAll()); }
 public function saveTeam(array $t): void {
  $d=$t['data'];$this->sql('UPDATE hunt_teams SET data=?,device_id=?,public_fingerprint=?,lives_remaining=?,eliminated_at=?,elimination_reason=?,last_seen=? WHERE id=?',
   [json($d),$d['device_id']??null,isset($d['public_key'])?hash('sha256',$d['public_key']):null,$d['lives_remaining']??3,$d['eliminated_at']??null,$d['elimination_reason']??null,$d['last_seen']??0,$t['id']]);
  foreach(($d['rounds']??[]) as $n=>$p) {
   $args=[$t['id'],(int)$n,$p['completed'],$p['score'],$p['status'],$p['finished_at'],json($p)];
   $suffix=$this->mysql?' ON DUPLICATE KEY UPDATE completed=VALUES(completed),score=VALUES(score),status=VALUES(status),finished_at=VALUES(finished_at),data=VALUES(data)':' ON CONFLICT(team_id,round_no) DO UPDATE SET completed=excluded.completed,score=excluded.score,status=excluded.status,finished_at=excluded.finished_at,data=excluded.data';
   $this->sql('INSERT INTO hunt_progress(team_id,round_no,completed,score,status,finished_at,data) VALUES(?,?,?,?,?,?,?)'.$suffix,$args);
  }
 }
 public function audit(string $actor,string $kind,array $data): void { $this->sql('INSERT INTO hunt_audit(at,actor,kind,data) VALUES(?,?,?,?)',[stamp(),$actor,$kind,json($data)]); }
}
