<?php
declare(strict_types=1);

/**
 * Operaciones al azar (INSERT de una a tres filas, UPDATE por clave y por
 * condición, DELETE) contra una tabla con clave primaria, un UNIQUE de texto y
 * un índice, comparando cada veinte con un modelo en memoria: la tabla entera,
 * cada índice contra un recorrido sin él, y el texto de cada parte contra el
 * que escribiría la escritura completa. Con partes muy pequeñas para cruzar
 * sus bordes a menudo. La llama tests/f3_escrituras.php:
 *
 *   php tests/_azar_escrituras.php <semilla> <filas por parte> <operaciones> <carpeta>
 *
 * https://miguelenred.es/jsonsqldb
 */
define('JSONSQLDB_CONEXION_DIRECTA', true); define('JSONSQLDB_CACHE_RESULTADOS', 0);
define('JSONSQLDB_FILAS_POR_PARTE', (int)($argv[2] ?? 50));
require dirname(__DIR__) . '/engine/bootstrap.php';
use JsonSQLDB\Database;
$r=(string)($argv[4] ?? sys_get_temp_dir() . '/jsonsqldb_azar'); @mkdir($r, 0775, true); if (is_dir("$r/b")) { Database::borrar('b', $r); } Database::crear('b',$r); $bd=new Database('b',$r);
$bd->consultar('CREATE TABLE t (id INTEGER PRIMARY KEY, u VARCHAR(20) UNIQUE, g INTEGER, s VARCHAR(40))');
$bd->consultar('CREATE INDEX ig ON t (g)');
mt_srand((int)$argv[1]); $modelo=[]; $sig=1; $raro=["a\"b", "c\\d", "e\nf", "ñ😀", '', "x,y}{"];
$fmt=function($filas){ $t="{\n  \"table\": \"t\",\n  \"rows\": ["; $d=[]; $sep="\n    "; foreach($filas as $f){ $d[]=strlen($t)+strlen($sep); $t.=$sep.json_encode($f, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION); $sep=",\n    "; } $t.=$filas===[]?"],\n":"\n  ],\n"; $a=strlen($t); return $t.'  "offsets": ['.implode(',',$d)."],\n  \"offsets_at\": $a\n}\n"; };
for ($op=0; $op<(int)($argv[3] ?? 400); $op++) {
  $k=mt_rand(0,9);
  try {
    if ($k<4 || !$modelo) { $n=mt_rand(1,3); $vals=[]; $par=[]; for($j=0;$j<$n;$j++){ $id=$sig++; $f=['id'=>$id,'u'=>"u$id",'g'=>mt_rand(0,5),'s'=>$raro[mt_rand(0,5)].$id]; $modelo[$id]=$f; $vals[]='(?,?,?,?)'; array_push($par,$id,$f['u'],$f['g'],$f['s']); } $bd->consultar('INSERT INTO t VALUES '.implode(',',$vals),$par); }
    elseif ($k<7) { $id=array_rand($modelo); $g=mt_rand(0,5); $s=$raro[mt_rand(0,5)].'m'; $bd->consultar('UPDATE t SET g = ?, s = ? WHERE id = ?',[$g,$s,$id]); $modelo[$id]['g']=$g; $modelo[$id]['s']=$s; }
    elseif ($k<8) { $g=mt_rand(0,5); $bd->consultar('UPDATE t SET s = ? WHERE g = ?',['masa',$g]); foreach($modelo as &$f){ if($f['g']===$g) $f['s']='masa'; } unset($f); }
    else { $id=array_rand($modelo); $bd->consultar('DELETE FROM t WHERE id = ?',[$id]); unset($modelo[$id]); }
  } catch (Throwable $e) { echo "op $op: ", $e->getMessage(), "\n"; exit(1); }
  if ($op % 20 === 19) {
    $esperado=array_values($modelo); usort($esperado, fn($a,$b)=>$a['id']<=>$b['id']);
    $real=$bd->consultar('SELECT * FROM t ORDER BY id'); if ($real!==$esperado) { echo "op $op: la tabla no coincide con el modelo\n"; exit(1); }
    foreach ([mt_rand(1,$sig)] as $id) { if ($bd->consultar('SELECT * FROM t WHERE id = ?',[$id]) !== $bd->consultar('SELECT * FROM t WHERE id + 0 = ?',[$id])) { echo "op $op: índice PK\n"; exit(1);} }
    $u='u'.mt_rand(1,$sig); if ($bd->consultar('SELECT id FROM t WHERE u = ?',[$u]) !== $bd->consultar("SELECT id FROM t WHERE u || '' = ?",[$u])) { echo "op $op: índice UNIQUE de texto\n"; exit(1); }
    for($g=0;$g<6;$g++){ if ($bd->consultar('SELECT id FROM t WHERE g = ? ORDER BY id',[$g]) !== $bd->consultar('SELECT id FROM t WHERE g + 0 = ? ORDER BY id',[$g])) { echo "op $op: índice g\n"; exit(1);} }
    foreach (glob("$r/b/t*.json") as $f) { if (!preg_match('/\/t(\.part\d+)?\.json$/',$f)) continue; $tx=file_get_contents($f); $filas=json_decode($tx,true)['rows']; if ($tx!==$fmt($filas)) { echo "op $op: ".basename($f)." mal formada\n"; exit(1);} }
  }
}
Database::borrar('b', $r);
echo "semilla {$argv[1]}, parte ".JSONSQLDB_FILAS_POR_PARTE.": ".count($modelo)." filas, todo coincide\n";
