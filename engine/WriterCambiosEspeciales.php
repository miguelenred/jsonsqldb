<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Los UPDATE y DELETE fuera de lo corriente: TRUNCATE TABLE, y los que se
 * cruzan con otras tablas (UPDATE … FROM / JOIN, DELETE … USING / JOIN)
 * (2.10, aparte de WriterCambios para que un UPDATE o un DELETE de una tabla
 * no tenga que compilarlos: sin OPcache, PHP compila en cada petición lo que
 * carga). Métodos de Writer que reciben el ejecutor en $w. Hereda de Writer
 * solo para llegar a lo protegido; no se instancia.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class WriterCambiosEspeciales extends Writer
{
    private function __construct()
    {
    }

    /**
     * TRUNCATE TABLE de MySQL (2.9): todas las filas, sin triggers y con el
     * autoincremento otra vez en 1; si otra tabla la referencia, error, y una
     * clave foránea hacia sí misma no lo impide, como en MySQL. Sin leer las
     * filas: la tabla se escribe vacía, y con el contador en su estructura,
     * que guarda el mayor de los dos (ver Storage::leerMeta()), en la misma
     * escritura. Antes se borraba fila a fila, la clave hacia sí misma daba
     * error, y la estructura se guardaba antes y aparte: escrita por partes,
     * la sentencia se repetía entera.
     */
    public static function vaciar(Writer $w, string $tabla, array $meta): int
    {
        foreach ($w->clavesHacia($tabla) as [$otra]) {
            if (strcasecmp($otra, $tabla) !== 0) {
                throw JsonSqlDbError::constraint("TRUNCATE TABLE '$tabla': '$otra' tiene una clave foránea hacia ella; usa DELETE FROM");
            }
        }
        $n = isset($w->datos[$tabla]) ? \count($w->datos[$tabla])
            : StorageLectura::contarFilas($w->cat->storage(), $tabla) + \count($w->anexo[$tabla] ?? []) - \count($w->borradas[$tabla] ?? []);
        $auto = Catalog::columnaAutoincremento($meta) !== null;
        if ($n === 0 && !$auto) {
            return 0;                            // nada que escribir
        }
        $w->datos[$tabla] = [];
        unset($w->anexo[$tabla], $w->cambios[$tabla], $w->borradas[$tabla], $w->rowid[$tabla], $w->unicas[$tabla],
              $w->idxCambios[$tabla], $w->cambiosSinMirar[$tabla]);
        // Sus filas se van: las hijas que esperaban a su padre al acabar la
        // sentencia se buscan entonces (2.10: en un trigger, un error falso)
        foreach ($w->foraneasPendientes[$tabla] ?? [] as $id => $p) {
            foreach (array_keys($p['claves']) as $clave) {
                $w->foraneasPendientes[$tabla][$id]['claves'][$clave]['buscar'] = true;
            }
        }
        $w->olvidarIndices($tabla);
        $w->sucioDatos[$tabla] = true;
        $w->sabe[$tabla]       = false;       // entera (ver Writer::volcar())
        if ($auto) {
            $w->autoinc[$tabla] = 1;
            $w->metas[$tabla]['autoincrement']['next'] = 1;
            $w->conMeta[$tabla] = true;
        }
        return $n;
    }

    /**
     * UPDATE … FROM / JOIN y DELETE … USING / JOIN (2.9): el cruce se hace una
     * vez con el motor del SELECT, con los datos de antes de la sentencia, y
     * da cada fila de la tabla que se escribe por su posición, que es por
     * donde se escribe después. Si sale varias veces cuenta la primera, como
     * en SQLite y PostgreSQL.
     *
     * Lee del disco, con sus índices, salvo que la sentencia tenga algo a
     * medias en memoria en alguna de sus tablas (un trigger): entonces de ahí.
     *
     * @param list<array|null> $exprs lo que asigna el SET (null: DEFAULT, no sale del cruce)
     * @return array<int, array<string, mixed>> posición => ["v$i" => valor de $exprs[$i]], por posición
     */
    public static function cruce(Writer $w, array $ast, array $exprs): array
    {
        $sel = Parser::analizar('SELECT 1');
        $sel['cols'] = [['star' => false, 'alias' => 'p',
                         'expr' => ['k' => 'col', 'tabla' => $ast['alias'] ?? $ast['tabla'], 'nombre' => Select::POSICION]]];
        foreach ($exprs as $i => $e) {
            if ($e !== null) {
                $sel['cols'][] = ['star' => false, 'alias' => "v$i", 'expr' => $e];
            }
        }
        $sel['from']  = $ast['desde'];
        $sel['where'] = $ast['where'];

        $tablas  = Select::tablasDe($sel, $w->cat);
        $memoria = $tablas === null;
        $sucias  = array_change_key_case($w->sucioDatos);
        foreach ($tablas ?? [] as $t) {
            $memoria = $memoria || isset($sucias[strtolower($t)]);
        }
        $select = $memoria ? new Select($w->cat, $w->lectorSub()) : new Select($w->cat);

        $out     = [];
        $guardar = static function (array $f) use (&$out): void {
            $p = $f['p'];
            if ($p !== null && !isset($out[$p])) {
                unset($f['p']);
                $out[$p] = $f;
            }
        };
        foreach ($select->ejecutarHacia($sel, $guardar) ?? [] as $f) {
            $guardar($f);
        }
        ksort($out);
        return $out;
    }
}
