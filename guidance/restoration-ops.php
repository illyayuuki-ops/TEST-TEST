<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/app.php';
if(!gr_ready())throw new RuntimeException('Restoration migration required.');
$path=realpath($argv[1]??'');$root=realpath(dirname(__DIR__));
if(!$path||str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR)))throw new RuntimeException('Provide an existing encrypted backup outside the application root.');
$key=base64_decode(getenv('LCCT_GUIDANCE_BACKUP_KEY')?:'',true);
if($key===false||strlen($key)!==32)throw new RuntimeException('Private backup key required.');
$raw=file_get_contents($path);if(substr($raw,0,8)!=="LCCTGD2\n")throw new RuntimeException('Wrong backup format.');
$clear=sodium_crypto_secretbox_open(substr($raw,32),substr($raw,8,24),$key);sodium_memzero($key);
if($clear===false)throw new RuntimeException('Backup authentication failed.');
$d=json_decode($clear,true,512,JSON_THROW_ON_ERROR);
if(($d['format']??'')!=='guidance-shared-v2'||!isset($d['tables']['gd_plus_students'],$d['tables']['gd_plus_cases'],$d['tables']['gd_plus_waitlist'],$d['tables']['gd_plus_privacy_log']))throw new RuntimeException('Restoration data missing from backup.');
foreach($d['files'] as $f)if(hash('sha256',base64_decode($f['bytes'],true))!==$f['sha256'])throw new RuntimeException('Attachment integrity failed.');
gq("INSERT INTO gd_settings(setting_key,setting_value) VALUES('last_plus_backup',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[date(DATE_ATOM)]);
echo "Verified Guidance subset backup recorded. No restore was performed.\n";
