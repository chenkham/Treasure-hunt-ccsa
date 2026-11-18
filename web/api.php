<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
require_once __DIR__.'/app/bootstrap.php';
$db=null;
try {
 $config=hunt_config();
 $method=$_SERVER['REQUEST_METHOD'];$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/');
 $url=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$path=substr($url,strlen($base));$path='/'.ltrim($path,'/');
 $body=[];if(!in_array($method,['GET','HEAD'],true)){
  if((int)($_SERVER['CONTENT_LENGTH']??0)>1000000)\Hunt\fail('Request too large',413);
  $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,1000001);fclose($stream);
  if(strlen($raw)>1000000)\Hunt\fail('Request too large',413);
  if(strtolower($_SERVER['HTTP_CONTENT_ENCODING']??'')==='gzip'){$raw=@gzdecode($raw,1000001);if($raw===false||strlen($raw)>1000000)\Hunt\fail('Invalid/oversized gzip body',413);}
  if($raw!==''){$body=\Hunt\parse($raw);if(!is_array($body))\Hunt\fail('Expected a JSON object');}
 }
 if($method==='POST'&&$path==='/api/admin/login')\Hunt\initializeFromLogin($config,$body);
 if($method==='POST'&&str_starts_with($path,'/api/admin/password/')&&!\Hunt\adminInstalled(new \Hunt\Database($config,false)))\Hunt\fail('Sign in once with your saved admin password to finish setup before using recovery.',409);
 $db=new \Hunt\Database($config);
 $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
 $reply=(new \Hunt\Api($db,$auth,$_SERVER['REMOTE_ADDR']??''))->dispatch($method,$path,$body);
 if($reply instanceof \Hunt\BinaryReply){header('Content-Type: '.$reply->type);header('Content-Disposition: attachment; filename="'.$reply->name.'"');
  if(str_starts_with($reply->type,'text/html'))header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
  echo $reply->body;exit;}
 if($path==='/api/leaderboard'){$tag='"'.$reply['revision'].'"';header('ETag: '.$tag);if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$tag){http_response_code(304);exit;}}
 if(is_array($reply)&&array_key_exists('server_time',$reply))$reply['server_time']=\Hunt\stamp();
 $output=\Hunt\json($reply);
 if(strlen($output)>400&&str_contains($_SERVER['HTTP_ACCEPT_ENCODING']??'','gzip')&&!ini_get('zlib.output_compression')){header('Content-Encoding: gzip');header('Vary: Accept-Encoding');$output=gzencode($output,6);}
 echo $output;
}catch(\Hunt\HttpError $x){if($db)$db->rollback();http_response_code($x->status);echo \Hunt\json(['detail'=>$x->detail]);}
catch(\JsonException $x){if($db)$db->rollback();http_response_code(400);echo '{"detail":"Invalid JSON"}';}
catch(\Throwable $x){if($db)$db->rollback();error_log('CampusHunt: '.$x->getMessage());http_response_code(503);echo '{"detail":"Server unavailable. Ask the organizer to check the private configuration and server log."}';}
