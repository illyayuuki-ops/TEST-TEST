<?php
// CLI-only read-only schema checks used before applying the Guidance base migration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function gm_parts(string $body): array {
    $out=[]; $start=0; $depth=0; $quote=false;
    for ($i=0,$n=strlen($body);$i<$n;$i++) {
        $c=$body[$i];
        if ($c==="'" && ($i===0 || $body[$i-1]!=="\\")) $quote=!$quote;
        if ($quote) continue;
        if ($c==='(') $depth++;
        if ($c===')') $depth--;
        if ($c===',' && $depth===0) { $out[]=trim(substr($body,$start,$i-$start)); $start=$i+1; }
    }
    $out[]=trim(substr($body,$start)); return $out;
}
function gm_plan(PDO $pdo, string $file): array {
    $sql=preg_replace('/^--.*$/m','',file_get_contents($file));
    $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
    $plan=[];
    foreach ($statements as $sql) {
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS (gd_\w+)\s*\((.*)\) ENGINE=InnoDB$/s',$sql,$m)) throw new RuntimeException('Unexpected migration SQL; stopped.');
        $table=$m[1]; $cols=[]; $unique=[]; $foreign=[];
        foreach (gm_parts($m[2]) as $part) {
            if (preg_match('/^FOREIGN KEY\((\w+)\) REFERENCES (\w+)\((\w+)\) ON DELETE (RESTRICT|CASCADE)$/',$part,$f)) { $foreign[]=implode(':',array_slice($f,1)); continue; }
            if (preg_match('/^UNIQUE\(([^)]+)\)$/',$part,$u)) { $unique[]=str_replace(' ','',$u[1]); continue; }
            if (preg_match('/^INDEX\([^)]+\)$/',$part)) continue;
            if (!preg_match('/^(\w+)\s+(ENUM\([^)]+\)|DECIMAL\([^)]+\)|VARCHAR\(\d+\)|CHAR\(\d+\)|INT|TINYINT|TEXT|DATETIME)(.*)$/s',$part,$c)) throw new RuntimeException('Unknown migration column: '.$table);
            $primary=str_contains($c[3],'PRIMARY KEY');
            $cols[$c[1]]=['type'=>strtolower(str_replace(' ','',$c[2])),'null'=>!$primary&&!str_contains($c[3],'NOT NULL'),'primary'=>$primary,'auto'=>str_contains($c[3],'AUTO_INCREMENT')];
            if ($primary || str_contains($c[3],'UNIQUE')) $unique[]=$c[1];
        }
        $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$engine=$q->fetchColumn();
        if ($engine!==false) {
            if (strtolower($engine)!=='innodb') throw new RuntimeException('Incompatible engine: '.$table);
            $actual=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            if (count($actual)!==count($cols)) throw new RuntimeException('Unexpected columns: '.$table);
            foreach ($actual as $col) {
                $expected=$cols[$col['Field']]??null;
                $type=preg_replace('/\b(tinyint|int)\(\d+\)/','$1',strtolower(str_replace(' ','',$col['Type'])));
                if (!$expected || $type!==$expected['type'] || ($col['Null']==='YES')!==$expected['null'] || ($col['Key']==='PRI')!==$expected['primary'] || str_contains($col['Extra'],'auto_increment')!==$expected['auto']) throw new RuntimeException('Incompatible column: '.$table.'.'.$col['Field']);
            }
            $indexes=[];
            foreach ($pdo->query('SHOW INDEX FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $ix) if ((int)$ix['Non_unique']===0) $indexes[$ix['Key_name']][(int)$ix['Seq_in_index']]=$ix['Column_name'];
            $actualUnique=[];foreach ($indexes as $index) { ksort($index);$actualUnique[]=implode(',',$index); }sort($unique);sort($actualUnique);
            if ($unique!==$actualUnique) throw new RuntimeException('Incompatible uniqueness constraints: '.$table);
            $q=$pdo->prepare('SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');$q->execute([$table]);
            $actualForeign=array_map(fn($row)=>implode(':',array_values($row)),$q->fetchAll(PDO::FETCH_ASSOC));sort($foreign);sort($actualForeign);
            if ($foreign!==$actualForeign) throw new RuntimeException('Incompatible foreign keys: '.$table);
        }
        $plan[]=['table'=>$table,'exists'=>$engine!==false,'sql'=>$sql];
    }
    if (count($plan)!==14) throw new RuntimeException('Unexpected base migration inventory.');
    return $plan;
}
