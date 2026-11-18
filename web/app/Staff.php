<?php
declare(strict_types=1);
namespace Hunt;

function ensureStaffSchema(Database $db): void {
 ensureAdminAccessSchema($db);
 $suffix=$db->mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
 $db->pdo->exec('CREATE TABLE IF NOT EXISTS hunt_staff (username VARCHAR(100) PRIMARY KEY,role VARCHAR(20) NOT NULL,active INT NOT NULL DEFAULT 1,email VARCHAR(254) NOT NULL DEFAULT \'\')'.$suffix);
}

function staffProfile(Database $db,string $username): array {
 try{$row=$db->sql('SELECT role,active,email FROM hunt_staff WHERE username=?',[$username])->fetch();}
 catch(\PDOException $x){
  if(($db->mysql&&($x->errorInfo[1]??0)===1146)||(!$db->mysql&&str_contains($x->getMessage(),'no such table')))$row=false;
  else throw $x;
 }
 // Existing administrators predate roles and retain ownership. New accounts always have a role row.
 $role=$row?($row['role']):'owner';if(!in_array($role,['owner','organizer','volunteer'],true))$role='volunteer';
 $active=$row?(bool)$row['active']:true;
 return ['username'=>$username,'role'=>$role,'active'=>$active,'email'=>$row['email']??'',
  'permissions'=>$role==='owner'?['monitor','teams','content','rounds','audit','print','export','staff','mail']:($role==='organizer'?['monitor','teams','content','rounds','audit','print','export']:['monitor'])];
}

function authorizeStaff(Database $db,string $actor,string $method,string $path): array {
 $profile=staffProfile($db,$actor);if(!$profile['active'])fail('This account has been disabled',403);
 if($method==='GET'&&in_array($path,['/api/admin/overview','/api/admin/account'],true))return $profile;
 $permission=str_starts_with($path,'/api/admin/staff')?'staff':($path==='/api/admin/account/mail'?'mail':null);
 if($path==='/api/admin/reset'||str_starts_with($path,'/api/admin/backups/')){if($profile['role']!=='owner')fail('Only the owner can use this control',403);return $profile;}
 if(($permission!==null&&!in_array($permission,$profile['permissions'],true))||($permission===null&&$profile['role']==='volunteer'))fail('Your account does not have permission for this action',403);
 return $profile;
}

function staffAccounts(Database $db): array {
 ensureStaffSchema($db);$rows=$db->sql('SELECT a.username,COALESCE(s.role,\'owner\') AS role,COALESCE(s.active,1) AS active,COALESCE(s.email,\'\') AS email FROM hunt_admins a LEFT JOIN hunt_staff s ON a.username=s.username ORDER BY a.username')->fetchAll();
 foreach($rows as &$row)$row['active']=(bool)$row['active'];unset($row);return ['accounts'=>$rows];
}

function saveStaffAccount(Database $db,string $actor,?string $username,array $body): array {
 ensureStaffSchema($db);$creating=$username===null;$username??=trim((string)($body['username']??''));
 $role=(string)($body['role']??'volunteer');$active=$body['active']??true;$email=trim((string)($body['email']??''));$password=(string)($body['password']??'');
 if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$username)||!in_array($role,['owner','organizer','volunteer'],true)||!is_bool($active)||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))||strlen($email)>254)fail('Enter a valid username, role and recovery email');
 if(($creating||$password!=='')&&(strlen($password)<12||strlen($password)>200))fail('Use a password of 12–200 characters');
 if(strcasecmp($username,$actor)===0)fail('Use Forgot password to change your own password. Manage other accounts here.',409);
 $db->begin();try{
  // Serialize owner changes so simultaneous edits cannot remove the final active owner.
  $db->sql('SELECT username FROM hunt_admins ORDER BY username'.$db->lock())->fetchAll();
  $owner=staffProfile($db,$actor);if(!$owner['active']||$owner['role']!=='owner')fail('Only the owner can manage dashboard accounts',403);
  $exists=(bool)$db->sql('SELECT username FROM hunt_admins WHERE username=?',[$username])->fetch();
  if($creating&&$exists)fail('Username already exists',409);if(!$creating&&!$exists)fail('Account not found',404);
  if($creating)$db->sql('INSERT INTO hunt_admins VALUES(?,?)',[$username,password_hash($password,PASSWORD_DEFAULT)]);
  elseif($password!=='')$db->sql('UPDATE hunt_admins SET password=? WHERE username=?',[password_hash($password,PASSWORD_DEFAULT),$username]);
  $db->sql('DELETE FROM hunt_staff WHERE username=?',[$username]);$db->sql('INSERT INTO hunt_staff VALUES(?,?,?,?)',[$username,$role,$active?1:0,$email]);
  $db->sql("DELETE FROM hunt_sessions WHERE subject=? AND kind='admin'",[$username]);
  $db->sql('DELETE FROM hunt_admin_resets WHERE username=?',[$username]);
  $db->audit($actor,$creating?'STAFF_CREATED':'STAFF_UPDATED',['username'=>$username,'role'=>$role,'active'=>$active]);$db->commit();
  return ['ok'=>true,'username'=>$username,'role'=>$role,'active'=>$active];
 }catch(\Throwable $x){$db->rollback();throw $x;}
}
