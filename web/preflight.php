<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('{"status":"method_not_allowed"}');}
$out=['status'=>'setup_required','php_version'=>PHP_VERSION,'php_patched'=>false,'database'=>'not_configured','installed'=>false,'message'=>''];
$required=['pdo_mysql','openssl','mbstring','xmlwriter','zlib'];$out['extensions_ready']=count(array_filter($required,'extension_loaded'))===count($required);
try {
 $config=hunt_config(false);$security=\Hunt\runtimeSecurity($config);$out['php_patched']=$security['patched'];$out['php_build_date']=$security['build_date'];
 \Hunt\validateConfig($config);$out['local_preview']=($config['test_only']??false)===true;
 $db=new \Hunt\Database($config,false);$out['database']='connected';
 try{$out['installed']=(bool)$db->sql('SELECT username FROM hunt_admins LIMIT 1')->fetch();}catch(\PDOException $x){}
 if($out['installed']&&$out['extensions_ready'])$out['status']='ready';
 elseif(!$out['extensions_ready']){$out['status']='extensions_required';$out['message']='Enable PDO MySQL, OpenSSL, mbstring, XMLWriter and zlib in hPanel PHP Configuration.';}
 elseif(!empty($config['initial_admin']['password_hash'])){$out['status']='first_login_ready';$out['message']='Sign in with your saved admin credentials. First login completes setup automatically.';}
 else $out['message']='Create your administrator through setup.php.';
}catch(\Hunt\HttpError $x){$out['status']='configuration_required';$out['message']=$x->getMessage();}
catch(\Throwable $x){$out['status']='database_unavailable';$out['message']='Unable to connect to MySQL. Check that hunt-private/config.php is beside public_html and the database user is assigned to the hunt database.';}
echo \Hunt\json($out);
