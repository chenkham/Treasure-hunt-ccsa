<?php
declare(strict_types=1);
require_once __DIR__.'/Support.php';
require_once __DIR__.'/RuntimeSecurity.php';
require_once __DIR__.'/Atlas.php';
require_once __DIR__.'/Competition.php';
require_once __DIR__.'/EventV3.php';
require_once __DIR__.'/PrintPack.php';
require_once __DIR__.'/Game.php';
require_once __DIR__.'/Api.php';
require_once __DIR__.'/AdminAccess.php';
require_once __DIR__.'/Staff.php';
require_once __DIR__.'/PreparedContent.php';
require_once __DIR__.'/AdminControls.php';
$autoload=dirname(__DIR__).'/vendor/autoload.php';
if(is_file($autoload))require_once $autoload;
function hunt_config(bool $validate=true): array {
 $path=getenv('HUNT_CONFIG')?:dirname($_SERVER['DOCUMENT_ROOT']?:dirname(__DIR__)).'/hunt-private/config.php';
 if(!is_file($path))throw new \Hunt\HttpError('Server not configured. Follow HOSTINGER_SETUP.md and create the private config.',503);
 $config=require $path;if(!is_array($config))throw new \RuntimeException('Invalid private configuration');
 if(PHP_SAPI!=='cli'&&($config['require_https']??true)&&($_SERVER['HTTPS']??'off')!=='on')throw new \Hunt\HttpError('HTTPS is required',400);
 if($validate)\Hunt\validateConfig($config);
 return $config;
}
