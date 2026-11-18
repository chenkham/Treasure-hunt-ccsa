<?php
declare(strict_types=1);
namespace Hunt;

function adminInstalled(Database $db): bool {
 try{return (bool)$db->sql('SELECT username FROM hunt_admins LIMIT 1')->fetch();}
 catch(\PDOException $x){
  if(($db->mysql&&($x->errorInfo[1]??0)===1146)||(!$db->mysql&&str_contains($x->getMessage(),'no such table')))return false;
  throw $x;
 }
}

/** Only a successful private credential match may initialize an empty database. */
function initializeFromLogin(array $config,array $body): void {
 $first=$config['initial_admin']??[];
 if(empty($first['username'])||empty($first['password_hash']))return;
 $db=new Database($config,false);if(adminInstalled($db))return;
 $user=trim((string)($body['username']??''));$password=(string)($body['password']??'');
 if($user!==$first['username']||!password_verify($password,$first['password_hash']))fail('Invalid credentials',401);
 require_once __DIR__.'/Install.php';
 try{install($config,$user,$password);}catch(HttpError $x){if($x->status!==409)throw $x;}
}

function ensureAdminAccessSchema(Database $db): void {
 $suffix=$db->mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 $db->pdo->exec('CREATE TABLE IF NOT EXISTS hunt_admin_resets (username VARCHAR(100) PRIMARY KEY,nonce CHAR(64) NOT NULL,code_hash CHAR(64) NOT NULL,expires BIGINT NOT NULL,attempts INT NOT NULL,sent_at BIGINT NOT NULL)'.$suffix);
}

function accessSettings(array $config): array {
 $file=rtrim($config['private_dir'],'/\\').'/admin-access.json';
 if(is_file($file)){$saved=parse((string)file_get_contents($file));if(is_array($saved))return $saved;}
 return ['mail'=>$config['mail']??[]];
}

function saveAccessSettings(array $config,array $settings): void {
 $dir=rtrim($config['private_dir'],'/\\');$tmp=tempnam($dir,'access-');
 if($tmp===false)throw new \RuntimeException('Cannot save private mail settings');
 try{if(file_put_contents($tmp,json($settings),LOCK_EX)===false)throw new \RuntimeException('Cannot write private mail settings');
  @chmod($tmp,0600);if(!rename($tmp,$dir.'/admin-access.json'))throw new \RuntimeException('Cannot replace private mail settings');
 }finally{if(is_file($tmp))unlink($tmp);}
}

function recoveryAddress(array $config,string $user,?Database $db=null): ?string {
 $profile=$db?staffProfile($db,$user):null;if($profile&&!$profile['active'])return null;
 $address=(string)(($profile['email']??'')?:($config['admin_recovery'][$user]??''));
 return filter_var($address,FILTER_VALIDATE_EMAIL)?$address:null;
}

function sendAdminCode(array $config,string $to,string $code): void {
 $settings=accessSettings($config)['mail']??[];
 $mailer=new \PHPMailer\PHPMailer\PHPMailer(true);$mailer->CharSet='UTF-8';
 $mailer->Timeout=12;$mailer->SMTPDebug=0;
 $transport=$settings['transport']??'mail';
 if($transport==='smtp'){
  $mailer->isSMTP();$mailer->Host=(string)($settings['host']??'');$mailer->Port=(int)($settings['port']??587);
  $security=$settings['encryption']??'tls';
  $loopback=($config['test_only']??false)===true&&$mailer->Host==='127.0.0.1';
  if(!in_array($security,['tls','ssl'],true)&&!$loopback)throw new \RuntimeException('SMTP requires TLS');
  $mailer->SMTPSecure=$security;$mailer->SMTPAutoTLS=!$loopback;
  $mailer->SMTPAuth=($settings['username']??'')!=='';$mailer->Username=(string)($settings['username']??'');$mailer->Password=(string)($settings['password']??'');
 }elseif($transport==='mail'){$mailer->isMail();$settings['from_address']=getenv('ANAZORI_PHP_MAIL_OTP_1')?:'noreply1@ccsdu.in';}
 else throw new \RuntimeException('Unsupported mail transport');
 $mailer->setFrom((string)($settings['from_address']??'noreply1@ccsdu.in'),'Anazori Treasure Hunt');
 $mailer->Sender=$mailer->From;$mailer->Encoding='base64';
 $mailer->addAddress($to);$mailer->Subject='Your Anazori admin reset code';
 $mailer->Body="Your password reset code is: $code\n\nIt expires in 10 minutes. If you did not request this, ignore this email.\nAnazori Treasure Hunt";
 $mailer->send();
}

final class AdminAccess {
 public function __construct(private Database $db,private string $ip) {}
 private function throttle(string $purpose,string $identity,int $limit,int $window): void {
  $db=$this->db;$id=hash('sha256',"access:$purpose:$identity");$now=stamp();$db->begin();
  try{
   $db->sql($db->mysql?'INSERT IGNORE INTO hunt_rate_limits VALUES(?,?,0)':'INSERT OR IGNORE INTO hunt_rate_limits VALUES(?,?,0)',[$id,$now]);
   $r=$db->sql('SELECT * FROM hunt_rate_limits WHERE id=?'.$db->lock(),[$id])->fetch();
   if($now-(int)$r['window_start']>=$window){$r['window_start']=$now;$r['attempts']=0;}
   if((int)$r['attempts']>=$limit){$db->commit();fail('Too many attempts. Please wait 15 minutes.',429);}
   $db->sql('UPDATE hunt_rate_limits SET attempts=?,window_start=? WHERE id=?',[(int)$r['attempts']+1,$r['window_start'],$id]);$db->commit();
  }catch(\Throwable $x){$db->rollback();throw $x;}
 }
 public function request(array $body): array {
  $db=$this->db;ensureAdminAccessSchema($db);$user=trim((string)($body['username']??''));
  $this->throttle('request-ip',$this->ip,8,900000);$this->throttle('request-account',strtolower($user),3,900000);
  $reply=['ok'=>true,'message'=>'If this administrator has recovery enabled, a code will arrive by email.','retry_after'=>60];
  $email=recoveryAddress($db->config,$user,$db);$secret=(string)($db->config['recovery_secret']??'');
  if(!$email||strlen($secret)<32||!$db->sql('SELECT username FROM hunt_admins WHERE username=?',[$user])->fetch())return $reply;
  $code=pin(6);$nonce=bin2hex(random_bytes(32));$hash=hash_hmac('sha256',$nonce.':'.$code,$secret);$now=stamp();
  $db->begin();try{
   $db->sql('SELECT username FROM hunt_admins WHERE username=?'.$db->lock(),[$user])->fetch();
   $previous=$db->sql('SELECT sent_at FROM hunt_admin_resets WHERE username=?'.$db->lock(),[$user])->fetch();
   if($previous&&$now-(int)$previous['sent_at']<60000){$db->commit();fail('Wait 60 seconds before requesting another code.',429);}
   $db->sql('DELETE FROM hunt_admin_resets WHERE username=?',[$user]);
   $db->sql('INSERT INTO hunt_admin_resets VALUES(?,?,?,?,?,?)',[$user,$nonce,$hash,$now+600000,0,$now]);$db->commit();
  }catch(\Throwable $x){$db->rollback();throw $x;}
  try{sendAdminCode($db->config,$email,$code);}
  catch(\Throwable $x){$db->sql('DELETE FROM hunt_admin_resets WHERE username=? AND nonce=?',[$user,$nonce]);
   error_log('CampusHunt: recovery email delivery failed (check private mail settings).');
   fail('The hosting mail service could not send the code. Please try again later or contact the event owner.',503);
  }
  $db->audit($user,'ADMIN_RESET_REQUESTED',[]);return $reply;
 }
 public function reset(array $body): array {
  $db=$this->db;ensureAdminAccessSchema($db);$this->throttle('verify-ip',$this->ip,20,900000);
  $user=trim((string)($body['username']??''));$code=(string)($body['code']??'');$password=(string)($body['password']??'');
  if(strlen($password)<12||strlen($password)>200)fail('Choose a password of 12–200 characters');
  if(!preg_match('/^[0-9]{6}$/',$code))$code='invalid';
  $db->begin();try{
   $db->sql('SELECT username FROM hunt_admins WHERE username=?'.$db->lock(),[$user])->fetch();
   $r=$db->sql('SELECT * FROM hunt_admin_resets WHERE username=?'.$db->lock(),[$user])->fetch();
   $secret=(string)($db->config['recovery_secret']??'');
   $valid=$r&&strlen($secret)>=32&&(int)$r['expires']>stamp()&&(int)$r['attempts']<5
    &&hash_equals($r['code_hash'],hash_hmac('sha256',$r['nonce'].':'.$code,$secret));
   if(!$valid){if($r)$db->sql('UPDATE hunt_admin_resets SET attempts=attempts+1 WHERE username=?',[$user]);$db->commit();fail('Code is incorrect or expired. Request a new code.',400);}
   $db->sql('UPDATE hunt_admins SET password=? WHERE username=?',[password_hash($password,PASSWORD_DEFAULT),$user]);
   $db->sql("DELETE FROM hunt_sessions WHERE subject=? AND kind='admin'",[$user]);
   $db->sql('DELETE FROM hunt_admin_resets WHERE username=?',[$user]);$db->audit($user,'ADMIN_PASSWORD_RESET',[]);$db->commit();
   return ['ok'=>true,'message'=>'Password updated. Sign in with your new password.'];
  }catch(\Throwable $x){$db->rollback();throw $x;}
 }
 public function account(string $user): array {
  $profile=staffProfile($this->db,$user);$mail=$profile['role']==='owner'?(accessSettings($this->db->config)['mail']??[]):[];unset($mail['password']);
  return ['username'=>$user,'role'=>$profile['role'],'recovery_email'=>recoveryAddress($this->db->config,$user,$this->db),'mail'=>$mail];
 }
 public function saveMail(string $user,array $body): array {
  $this->throttle('mail-settings',$this->ip,8,900000);
  $hash=$this->db->sql('SELECT password FROM hunt_admins WHERE username=?',[$user])->fetchColumn();
  if(!$hash||!password_verify((string)($body['current_password']??''),$hash))fail('Enter your current admin password',403);
  $old=accessSettings($this->db->config);$mail=$body['mail']??[];
  if(!is_array($mail)||!in_array($mail['transport']??'', ['mail','smtp'],true)||!filter_var($mail['from_address']??'',FILTER_VALIDATE_EMAIL))fail('Enter a valid sending email address');
  $clean=['transport'=>$mail['transport'],'from_address'=>$mail['from_address']];
  if($mail['transport']==='smtp'){
   if(!preg_match('/^[a-zA-Z0-9.-]{1,253}$/',(string)($mail['host']??''))||!in_array((int)($mail['port']??0),[465,587],true)||!in_array($mail['encryption']??'',['tls','ssl'],true))fail('Use a valid SMTP host and TLS on port 465 or 587');
   foreach(['host','username','encryption'] as $k)$clean[$k]=substr((string)($mail[$k]??''),0,253);
   $clean['port']=(int)$mail['port'];$clean['password']=(string)($mail['password']??'');
   if($clean['password']===''&&($old['mail']['host']??'')===$clean['host']&&($old['mail']['username']??'')===$clean['username'])$clean['password']=$old['mail']['password']??'';
   if($clean['username']===''||$clean['password']==='')fail('Enter the sending mailbox username and password');
  }
  saveAccessSettings($this->db->config,['mail'=>$clean]);$this->db->audit($user,'ADMIN_MAIL_UPDATED',[]);return ['ok'=>true];
 }
}
