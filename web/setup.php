<?php
declare(strict_types=1);
ini_set('display_errors','0');header('Cache-Control: no-store');header('X-Frame-Options: DENY');
require __DIR__.'/app/bootstrap.php';require __DIR__.'/app/Install.php';
session_set_cookie_params(['httponly'=>true,'secure'=>(($_SERVER['HTTPS']??'off')==='on'),'samesite'=>'Strict']);session_start();
$_SESSION['hunt_setup_csrf']??=bin2hex(random_bytes(32));$message='';
try{
 $config=hunt_config();$db=new \Hunt\Database($config,false);
 try{$complete=(bool)$db->sql('SELECT username FROM hunt_admins LIMIT 1')->fetch();}catch(\PDOException $x){$complete=false;}
 if($complete){http_response_code(409);exit('Installation is locked because an administrator already exists. Open dashboard/.');}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(strlen($config['setup_token']??'')<32||!hash_equals($config['setup_token'],(string)($_POST['setup_token']??''))||!hash_equals($_SESSION['hunt_setup_csrf'],(string)($_POST['csrf']??'')))\Hunt\fail('Invalid setup authorization',403);
  \Hunt\install($config,trim((string)$_POST['username']),(string)$_POST['password']);$_SESSION=[];session_destroy();exit('Installed successfully. Remove setup.php from the public folder, then open dashboard/.');
 }
}catch(\Throwable $x){$message=$x instanceof \Hunt\HttpError?$x->getMessage():'Check the private configuration and server log.';http_response_code($x instanceof \Hunt\HttpError?$x->status:503);}
function esc($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Initialize Anazori Hunt</title><link rel="stylesheet" href="dashboard/style.css"><body><main><section class="card"><h1>Initialize your hunt</h1><p>This one-time form requires the secret token from the private configuration. It locks after the first administrator is created.</p><p role="alert"><?=esc($message)?></p><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['hunt_setup_csrf']??'')?>"><label>Setup token<input name="setup_token" type="password" required autocomplete="off"></label><label>Administrator username<input name="username" required autocomplete="username"></label><label>Administrator password<input name="password" type="password" minlength="12" required autocomplete="new-password"></label><button type="submit" class="primary">Initialize database</button></form></section></main></body></html>
