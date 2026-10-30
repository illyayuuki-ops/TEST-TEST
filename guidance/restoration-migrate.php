<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/config/app.php';
if(!g_ready())throw new RuntimeException('Install the existing Guidance baseline first.');
$sql=file_get_contents(__DIR__.'/migrations/002_restoration.sql');
$statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
foreach($statements as $q){
 if(!preg_match('/^CREATE TABLE IF NOT EXISTS (gd_plus_\w+)\s*\((.*)\) ENGINE=InnoDB$/s',$q,$m))throw new RuntimeException('Unexpected migration statement; stopped.');
 $table=$m[1];$present=gone('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
 if($present){
  preg_match_all('/(?:^|,)\s*(\w+)\s+(?:INT|VARCHAR|TEXT|DATETIME|TINYINT)\b/s',$m[2],$cols);
  $columnInfo=gall('SHOW COLUMNS FROM `'.$table.'`');$actual=array_column($columnInfo,'Field');
  preg_match_all('/(?:^|,)\s*(\w+)\s+(INT|VARCHAR\(\d+\)|TEXT|DATETIME|TINYINT)([^,]*)/s',$m[2],$specs,PREG_SET_ORDER);
  $byName=array_column($columnInfo,null,'Field');
  foreach($specs as $spec){$col=$byName[$spec[1]]??null;$type=$col?preg_replace('/(tinyint|int)\(\d+\)/','$1',strtolower($col['Type'])):'';
   $nullExpected=!str_contains($spec[3],'NOT NULL')&&!str_contains($spec[3],'PRIMARY KEY');
   if(!$col||$type!==strtolower($spec[2])||($col['Null']==='YES')!==$nullExpected||($col['Key']==='PRI')!==str_contains($spec[3],'PRIMARY KEY'))throw new RuntimeException('Existing column differs: '.$table.'.'.$spec[1]);
  }
  preg_match_all('/FOREIGN KEY\((\w+)\) REFERENCES (\w+)\((\w+)\)/',$m[2],$fk,PREG_SET_ORDER);$expectedKeys=array_map(fn($k)=>implode(':',array_slice($k,1)),$fk);
  $actualKeys=array_map(fn($k)=>implode(':',array_values($k)),gall('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$table]));sort($actualKeys);sort($expectedKeys);if($actualKeys!==$expectedKeys)throw new RuntimeException('Existing foreign keys differ: '.$table);

  if($present['ENGINE']!=='InnoDB'||array_diff($cols[1],$actual)||array_diff($actual,$cols[1]))throw new RuntimeException('Existing table has an unexpected shape: '.$table.'. No changes made by preflight.');
 }
}
if(!in_array('--apply',$argv,true)){echo "PLAN ONLY: ten additive gd_plus_* tables and a feature marker. No shared table alterations, data imports or deletes.\nBack up the shared database and uploads before applying. Then run this command with --apply.\n";foreach($statements as $q){preg_match('/EXISTS (\w+)/',$q,$m);echo $m[1]."\n";}exit;}
if((int)gq('SELECT GET_LOCK(?,10)',['guidance-write:'.DB_NAME])->fetchColumn()!==1)throw new RuntimeException('Guidance is busy.');
try{
 foreach($statements as $q)$pdo->exec($q);
 gq("INSERT INTO gd_settings(setting_key,setting_value) VALUES('restoration_plus','1') ON DUPLICATE KEY UPDATE setting_value='1'");
 gq("INSERT IGNORE INTO gd_settings(setting_key,setting_value) VALUES('plus_revision','0')");
 echo "Guidance restoration enabled. Ten additive tables; existing records and shared schema preserved. Re-running is safe. MySQL DDL is not transactional; if interrupted, keep the server stopped and rerun after resolving the error.\n";
}finally{gq('SELECT RELEASE_LOCK(?)',['guidance-write:'.DB_NAME]);}
