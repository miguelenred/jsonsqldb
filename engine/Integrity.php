<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Comprobación de integridad de las claves foráneas.
 *
 * El motor las respeta en cada INSERT, UPDATE y DELETE, así que trabajando por
 * SQL no pueden romperse. Pero los datos son ficheros JSON en disco: alguien
 * puede editarlos a mano, restaurar una copia de una tabla sin la otra, o
 * mezclar bases. Esto encuentra esos destrozos y, si se le pide, los arregla.
 *
 * Qué busca: filas cuyo valor de clave foránea no existe en la tabla padre
 * ("huérfanas"). Las filas con NULL en la columna no cuentan, igual que en la
 * comprobación normal.
 *
 * Qué NO toca: nunca borra filas. Si la columna admite nulos, la pone a NULL;
 * si no los admite, lo informa y lo deja como está, porque la decisión de qué
 * hacer con ese dato es tuya y no de un botón.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Integrity
{
    private Catalog $cat;

    public function __construct(Catalog $cat)
    {
        $this->cat = $cat;
    }

    /**
     * Los ficheros JSON de una tabla, o de todas, estropeados a mano (2.9):
     * CHECK TABLES los lista; REPAIR TABLES los repara, apartando las filas
     * que no se pueden arreglar con seguridad (ver StorageMantenimiento::revisarFicheros()).
     *
     * @return list<array{tabla: string, fichero: string, problema: string, arreglo: string}>
     */
    public function ficheros(?string $tabla = null, bool $corregir = false): array
    {
        if ($tabla !== null && !$this->cat->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $st  = $this->cat->storage();
        $out = [];
        foreach ($tabla !== null ? [$tabla] : $this->cat->tablas() as $t) {
            array_push($out, ...StorageMantenimiento::revisarFicheros($st, $t, $corregir ? $this->cat->indicesDe($t) : null, $corregir));
        }
        return $out;
    }

    /**
     * Revisa las claves foráneas y devuelve una fila por problema encontrado.
     *
     * @param string|null $tabla solo esa tabla; null = todas
     * @param bool $corregir true para poner a NULL lo que se pueda
     * @return array<int,array<string,mixed>>
     */
    public function claves(?string $tabla = null, bool $corregir = false): array
    {
        if ($tabla !== null && !$this->cat->existe($tabla)) {
            throw JsonSqlDbError::schema("La tabla '$tabla' no existe");
        }
        $tablas = $tabla !== null ? [$tabla] : $this->cat->tablas();
        $out    = [];

        foreach ($tablas as $t) {
            $meta = $this->cat->meta($t);
            if ($meta['foreign_keys'] === []) {
                continue;
            }

            // Las hijas, del disco, sin caché, y sin juntarlas (2.9: la tabla
            // entera en memoria): una pasada por clave foránea, y en memoria
            // solo las filas que se corrigen y las claves del padre (ver clavesDe())
            $cambios = [];

            foreach ($meta['foreign_keys'] as $fk) {
                // Con la colación de las columnas del padre, como al escribir (2.9)
                $plegar = $this->cat->existe((string)$fk['table'])
                    ? Catalog::plegarDe($this->cat->meta((string)$fk['table']), $fk['references']) : [];
                $padres = $this->clavesDe((string)$fk['table'], $fk['references'], $plegar);

                foreach ($this->cat->storage()->filas($t, true) as $i => $fila) {
                    $fila  = $cambios[$i] ?? $fila;
                    $clave = Indexes::claveFila($fila, $fk['columns'], $plegar);
                    if ($clave === null || isset($padres[$clave])) {
                        continue;                       // NULL o padre encontrado
                    }

                    $valores = [];
                    foreach ($fk['columns'] as $c) {
                        $valores[] = $c . '=' . json_encode($fila[$c] ?? null, JSON_UNESCAPED_UNICODE);
                    }

                    $anulable = true;
                    foreach ($fk['columns'] as $c) {
                        $col = Catalog::columna($meta, $c);
                        if ($col === null || $col['notnull'] || $col['pk']) {
                            $anulable = false;
                        }
                    }

                    $accion = 'ninguna';
                    if ($corregir && $anulable) {
                        foreach ($fk['columns'] as $c) {
                            $fila[$c] = null;
                        }
                        $cambios[$i] = $fila;
                        $accion      = 'puesta a NULL';
                    } elseif ($corregir) {
                        $accion = 'no se puede corregir sola: la columna no admite NULL';
                    }

                    $out[] = [
                        'tabla'       => $t,
                        'restriccion' => (string)$fk['name'],
                        'columnas'    => implode(', ', $fk['columns']),
                        'valor'       => implode(', ', $valores),
                        'apunta_a'    => $fk['table'] . '(' . implode(', ', $fk['references']) . ')',
                        'problema'    => 'la fila apunta a un valor que no existe en la tabla destino',
                        'corregible'  => $anulable ? 1 : 0,
                        'accion'      => $accion,
                    ];
                }
            }

            if ($cambios !== []) {
                // Las definiciones van siempre: sin ellas se daría por hecho que
                // la tabla no tiene índices y se borrarían sus ficheros. Los
                // resultados seguirían siendo correctos —sin índice se recorre
                // la tabla— pero reparar unas claves no tiene por qué dejar la
                // tabla más lenta.
                StorageCambios::modificarFilas($this->cat->storage(), $t, $cambios, [], Indexes::definiciones($this->cat->meta($t)));
            }
        }

        return $out;
    }

    /**
     * Claves existentes en la tabla padre, para buscar en O(1), con la misma
     * igualdad que al escribir (2.9: '5' y '05' eran claves distintas, y
     * REPAIR KEYS ponía a NULL una hija que sí tenía su fila). De sus filas,
     * no de su índice, aunque con un padre mucho mayor que la hija el índice
     * sería más rápido: no sabe de las ediciones a mano, que es lo que esto
     * busca, y con una fila padre añadida a mano REPAIR KEYS anularía sus hijas.
     *
     * @param string[]            $columnas
     * @param array<int, ?string> $plegar ver Catalog::plegarDe()
     * @return array<string,true>
     */
    private function clavesDe(string $tabla, array $columnas, array $plegar): array
    {
        if (!$this->cat->existe($tabla)) {
            return [];                                  // sin padre, todo es huérfano
        }
        $out = [];
        foreach ($this->cat->storage()->filas($tabla, true) as $fila) {
            $clave = Indexes::claveFila($fila, $columnas, $plegar);
            if ($clave !== null) {
                $out[$clave] = true;
            }
        }
        return $out;
    }
}
