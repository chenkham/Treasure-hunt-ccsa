<?php
// Save the completed file OUTSIDE public_html, normally ../hunt-private/config.php.
return [
 'dsn'=>'', // mysql:host=localhost;dbname=YOUR_SEPARATE_HUNT_DATABASE;charset=utf8mb4
 'username'=>'', 'password'=>'',
 'private_dir'=>'', // Absolute path to ../hunt-private, OUTSIDE public_html.
 'setup_token'=>'', // Generate a random 32+ character one-time token.
 'require_https'=>true, 'allow_sqlite_preview'=>false,
 'hosting_provider'=>'hostinger-managed', // Checks the running Build Date for managed Hostinger backports.
 'initial_admin'=>['username'=>'Chenkham','password_hash'=>''], // Private password_hash(); first login initializes empty DB only.
 'admin_recovery'=>['Chenkham'=>''], // Recovery email; never supplied by the reset request.
 'recovery_secret'=>'', // Random 32+ character private secret for hashing reset codes.
 'mail'=>['transport'=>'mail','from_address'=>'noreply1@ccsdu.in'], // Same PHP-mail OTP sender as Anazori.
 // Normally leave empty. If Hostinger retained an older version number, verify
 // PHP Info's Build Date (21 Oct 2026 or newer) in hPanel before recording:
 // ['provider'=>'hostinger','php_version'=>'exact PHP_VERSION',
 //  'build_date'=>'2026-10-21','verified_on'=>'2026-10-23']
 'verified_php_backport'=>[],
];
