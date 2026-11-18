<?php
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/Install.php';
if(PHP_SAPI!=='cli')exit;
try{
 $config=hunt_config();echo "Administrator username: ";$user=trim(fgets(STDIN));echo "Administrator password (12+ characters): ";$password=trim(fgets(STDIN));
 \Hunt\install($config,$user,$password);echo "Installed. Open dashboard/. Keep private configuration and key outside the website.\n";
}catch(\Throwable $x){fwrite(STDERR,$x->getMessage()."\n");exit(1);}
