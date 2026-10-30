<?php
require dirname(__DIR__).'/config/app.php';
require __DIR__.'/domain.php';
require __DIR__.'/workbench.php';
$u=g_actor();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $type=gw_choice($_GET,'type',['cases','reports'],'reports');
    if($type==='cases') {
        gx(in_array(g_staff($u),['head','counselor'],true));
        $f=gw_filters($_GET);$rows=[['Reference','Service','Status','Priority','Counselor','Created','Due target (not an active timer)','Updated']];
        foreach(gw_filter_cases(gw_cases($u),$f,$u) as $t)$rows[]=[$t['code'],$t['category'],$t['status'],$t['priority'],$t['counselor']?:'Unassigned',$t['created_at'],date('Y-m-d H:i:s',gw_due($t)),$t['updated_at']];
    } else {
        gx(g_staff($u)!==null);$f=gw_report_filters($_GET);$rows=[['Dimension','Group','Value (small cells suppressed)'],['Scope','Case intake from (inclusive)',$f['from']?:'All time'],['Scope','Case intake to (inclusive)',$f['to']?:'All time'],['Scope','Case service ID',$f['category']?:'All'],['Scope','Case counselor ID',$f['counselor']?:'All'],['Scope','Activity charts','Activity start-date filter only; not case service/counselor']];
        if (gr_ready()) foreach(['program','year_level','section'] as $field) $rows[]=['Scope','Current academic '.$field,$f[$field]?:'All'];
        foreach(gw_analytics($u,$f) as $dimension=>$items)foreach($items as [$label,$value])$rows[]=[$dimension,$label,$value];
    }
    gx(g_rate('guidance_workbench_export_'.$u['id'],30), 'Too many exports. Try again later.',429);
    g_audit($u,'workbench_'.$type.'_export',json_encode(['from'=>$f['from'],'to'=>$f['to'],'category'=>$f['category'],'counselor'=>$f['counselor']]));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="guidance-'.($type==='cases'?'authorized-queue':'filtered-aggregate').'.csv"');
    echo g_csv($rows);
} catch(RuntimeException $e) {
    $status=in_array($e->getCode(),[400,403,404,429],true)?$e->getCode():500;
    g_denied($status===500?'The export could not be completed.':$e->getMessage(),$status);
}
