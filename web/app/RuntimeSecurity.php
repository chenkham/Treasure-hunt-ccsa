<?php
declare(strict_types=1);
namespace Hunt;

/** An application check is a deployment guard, never a replacement for patching PHP. */
function phpBuildDate(): ?string {
 static $read=false,$date=null;if($read)return $date;$read=true;
 if(!function_exists('phpinfo'))return null;
 ob_start();try{phpinfo(INFO_GENERAL);$info=(string)ob_get_contents();}finally{ob_end_clean();}
 if(preg_match('~<td[^>]*>\s*Build Date\s*</td>\s*<td[^>]*>([^<]+)~i',$info,$m)
  ||preg_match('/^Build Date\s*=>\s*(.+)$/m',$info,$m)){
  $time=strtotime(trim(html_entity_decode($m[1])));if($time!==false)$date=gmdate('Y-m-d',$time);
 }
 return $date;
}
function runtimeSecurity(array $config=[],?string $version=null,?string $buildDate=null): array {
 $actual=$version===null;
 $version??=PHP_VERSION;$branch=implode('.',array_slice(explode('.',$version),0,2));
 $minimum=['8.2'=>'8.2.35','8.3'=>'8.3.36','8.4'=>'8.4.27','8.5'=>'8.5.12'];
 $upstream=isset($minimum[$branch])&&version_compare($version,$minimum[$branch],'>=');
 $vendor=$config['verified_php_backport']??[];
 // Hostinger documents patched builds retaining older version numbers. Trust only
 // private provider settings plus the observed runtime build, never request headers.
 $backport=isset($minimum[$branch])&&($vendor['provider']??'')==='hostinger'
  &&($vendor['php_version']??'')===$version
  &&preg_match('/^2026-\d{2}-\d{2}$/',(string)($vendor['build_date']??''))===1
  &&($vendor['build_date']??'')>='2026-10-21'
  &&preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($vendor['verified_on']??''))===1;
 $buildDate??=$actual?phpBuildDate():null;
 $managed=isset($minimum[$branch])&&($config['hosting_provider']??'')==='hostinger-managed'
  &&$buildDate!==null&&preg_match('/^2026-\d{2}-\d{2}$/',$buildDate)===1&&$buildDate>='2026-10-21';
 return ['patched'=>$upstream||$backport||$managed,'php_version'=>$version,'build_date'=>$buildDate,'minimum'=>$minimum[$branch]??null,
  'basis'=>$upstream?'upstream_release':($backport?'private_hostinger_build_confirmation':($managed?'hostinger_runtime_build':'update_required')),
  'advisory'=>'https://www.php.net/archive/2026.php#2026-10-21-5'];
}
function requirePatchedRuntime(array $config): void {
 if(!runtimeSecurity($config)['patched'])fail('Hostinger PHP patch could not be confirmed. Open hPanel → PHP Info and check that Build Date is 21 October 2026 or later, or select an updated PHP version in PHP Configuration.',503);
}
function validateConfig(array $config): void {
 requirePatchedRuntime($config);
 if(empty($config['dsn'])||empty($config['username'])||!isset($config['password'])||$config['password']===''
   ||str_contains($config['dsn'],'YOUR_DATABASE')) {
  if(!($config['allow_sqlite_preview']??false))fail('Treasure-hunt database is not configured yet. Complete the separate private MySQL configuration.',503);
 }
 if(empty($config['private_dir']))fail('Set the private storage directory outside public_html.',503);
 $private=realpath($config['private_dir']);$root=realpath($_SERVER['DOCUMENT_ROOT']??'');
 if(PHP_SAPI!=='cli'&&$private&&$root&&($private===$root||str_starts_with($private,$root.DIRECTORY_SEPARATOR)))fail('Private storage must be outside public_html.',503);
}
