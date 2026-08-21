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
 */
final class Integrity
{
    private Catalog $cat;

    public function __construct(Catalog $cat)
    {
        $this->cat = $cat;
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

            $filas    = $this->cat->storage()->leerFilas($t, true);   // del disco, sin caché
            $cambiada = false;

            foreach ($meta['foreign_keys'] as $fk) {
                $padres = $this->clavesDe((string)$fk['table'], $fk['references']);

                foreach ($filas as $i => $fila) {
                    $clave = self::clave($fila, $fk['columns']);
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
                        $filas[$i] = $fila;
                        $cambiada  = true;
                        $accion    = 'puesta a NULL';
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

            if ($cambiada) {
                $this->cat->storage()->guardarFilas($t, array_values($filas));
            }
        }

        return $out;
    }

    /**
     * Claves existentes en la tabla padre, para buscar en O(1).
     *
     * @param string[] $columnas
     * @return array<string,true>
     */
    private function clavesDe(string $tabla, array $columnas): array
    {
        if (!$this->cat->existe($tabla)) {
            return [];                                  // sin padre, todo es huérfano
        }
        $out = [];
        foreach ($this->cat->storage()->leerFilas($tabla, true) as $fila) {
            $clave = self::clave($fila, $columnas);
            if ($clave !== null) {
                $out[$clave] = true;
            }
        }
        return $out;
    }

    /** Clave comparable de una fila, o null si alguna columna es NULL. */
    private static function clave(array $fila, array $columnas): ?string
    {
        $partes = [];
        foreach ($columnas as $c) {
            $v = $fila[$c] ?? null;
            if ($v === null) {
                return null;
            }
            $partes[] = is_string($v) ? 's:' . $v : 'n:' . $v;
        }
        return implode('|', $partes);
    }
}
