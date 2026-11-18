<?php
// Local PHP development router. Apache/LiteSpeed uses .htaccess in production.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if(preg_match('~(?:^|/)(?:app|vendor|database|bin|tests)(?:/|$)~',$path)||preg_match('~(?:composer\.(?:json|lock)|config[^/]*\.php|\.(?:sql|log|pem|db|zip))$~i',$path)||str_contains($path,'..')||str_contains($path,'/.')){http_response_code(403);exit;}
$prefix=getenv('HUNT_TEST_PREFIX')?:'';
if($prefix&&str_starts_with($path,$prefix))$path=substr($path,strlen($prefix))?:'/';
$file=realpath(__DIR__.$path);
if($file&&str_starts_with($file,realpath(__DIR__).DIRECTORY_SEPARATOR)&&is_file($file)&&pathinfo($file,PATHINFO_EXTENSION)!=='php'){
 $ext=pathinfo($file,PATHINFO_EXTENSION);$types=['js'=>'application/javascript','css'=>'text/css','html'=>'text/html','webp'=>'image/webp','ttf'=>'font/ttf','png'=>'image/png'];
 header('Content-Type: '.($types[$ext]??'application/octet-stream'));readfile($file);return true;
}
if($path==='/dashboard/'||$path==='/dashboard'){header('Content-Type: text/html');readfile(__DIR__.'/dashboard/index.html');return true;}
if($path==='/'){header('Content-Type: text/html');readfile(__DIR__.'/index.html');return true;}
if($path==='/preflight.php'){require __DIR__.'/preflight.php';return true;}
if($path==='/setup.php'){require __DIR__.'/setup.php';return true;}
$_SERVER['SCRIPT_NAME']=rtrim($prefix,'/').'/api.php';require __DIR__.'/api.php';
