<?php
require dirname(__DIR__).'/config/app.php';
require __DIR__.'/domain.php';
require __DIR__.'/workbench.php';
$u=g_actor();if(!g_head($u))g_denied();if(!gr_ready())g_denied('Restoration migration is required.',503);
try{$q=gr_query($_GET);gx(g_rate('guidance_audit_export:'.$u['id'],20),'Too many exports.',429);$rows=gr_audit_rows($q,true);g_audit($u,'audit_export','Filtered operational fields only');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="guidance-audit.csv"');echo g_csv(array_merge([['When','Actor','Action']],array_map('array_values',$rows)));}catch(RuntimeException $e){g_denied(in_array($e->getCode(),[400,429],true)?$e->getMessage():'Export unavailable.',in_array($e->getCode(),[400,429],true)?$e->getCode():500);}
