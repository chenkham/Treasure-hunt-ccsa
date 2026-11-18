<?php
declare(strict_types=1);
// Public geographic artwork only. The parent Anazori site intentionally blocks .json URLs.
$file=__DIR__.'/atlas/map-data.json';
header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
if(!is_file($file)){http_response_code(404);header('Cache-Control: no-store');exit('{"detail":"Campus atlas is missing"}');}
$etag='"'.hash_file('sha256',$file).'"';header('ETag: '.$etag);header('Cache-Control: public, max-age=86400');
header('Vary: Accept-Encoding');
if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
if(extension_loaded('zlib')&&!ini_get('zlib.output_compression'))ob_start('ob_gzhandler');
readfile($file);
