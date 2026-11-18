<?php
declare(strict_types=1);
// CLI-only capture: PHPMailer's unqualified mail() resolves here, never to an MTA.
namespace PHPMailer\PHPMailer {
 function mail(string $to,string $subject,string $body,string $headers,string $parameters=''): bool {
  $GLOBALS['captured_mail']=compact('to','subject','body','headers','parameters');return true;
 }
}
namespace {
 if(PHP_SAPI!=='cli')exit(1);
 require dirname(__DIR__).'/app/bootstrap.php';
 $sender=getenv('ANAZORI_PHP_MAIL_OTP_1')?:'noreply1@ccsdu.in';
 \Hunt\sendAdminCode(['private_dir'=>__DIR__,'mail'=>['transport'=>'mail','from_address'=>'obsolete@example.test']],'organizer@example.test','123456');
 $message=$GLOBALS['captured_mail']??[];
 if(($message['to']??'')!=='organizer@example.test'||!str_contains($message['headers']??'',$sender)||!str_contains(base64_decode($message['body']??''),'123456'))throw new \RuntimeException('No-reply mail wiring failed');
 echo "No-reply sender and reset message passed a local mail capture. No email was sent.\n";
}
