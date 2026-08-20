<?php
declare(strict_types=1);

/**
 * Exportación de resultados a CSV o a sentencias INSERT.
 *
 * Escribe directamente en la salida y termina la petición, así que no debe
 * haberse enviado nada antes.
 */
final class Exportar
{
    /** @param array<int,array<string,mixed>> $filas */
    public static function csv(array $filas, string $nombre): void
    {
        self::cabeceras($nombre . '.csv', 'text/csv; charset=UTF-8');

        $salida = fopen('php://output', 'wb');
        // BOM para que Excel reconozca el UTF-8 y no rompa los acentos
        fwrite($salida, "\xEF\xBB\xBF");

        $sep = (string)ADMIN_CSV_SEPARADOR;
        if ($filas !== []) {
            fputcsv($salida, array_keys($filas[0]), $sep, '"', '\\');
            foreach ($filas as $f) {
                $linea = [];
                foreach ($f as $v) {
                    $linea[] = $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string)$v);
                }
                fputcsv($salida, $linea, $sep, '"', '\\');
            }
        }
        fclose($salida);
        exit;
    }

    /**
     * Copia de una base en ZIP, con la estructura de carpetas tal cual está en
     * disco: se descomprime dentro de data/ y la base queda restaurada.
     *
     * El ZIP se monta en un temporal que se borra siempre, tanto si la descarga
     * va bien como si falla a medio camino.
     */
    public static function zip(string $base, string $ruta): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'La extensión zip de PHP no está activada, así que no se puede generar el ZIP. '
                . 'Actívala en php.ini (extension=zip) o usa el volcado en SQL.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'jsonsqldb_');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el fichero temporal del ZIP.');
        }
        // Se borra pase lo que pase, también si la petición muere por el camino
        register_shutdown_function(static function () use ($tmp): void {
            if (is_file($tmp)) { @unlink($tmp); }
        });

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo abrir el ZIP temporal para escribir.');
            }

            $ruta  = rtrim(str_replace('\\', '/', $ruta), '/');
            $corte = strlen(dirname($ruta)) + 1;          // deja fuera todo lo anterior a la base
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $zip->addEmptyDir($base);
            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                $real    = str_replace('\\', '/', $item->getPathname());
                $interna = substr($real, $corte);
                if ($item->isDir()) {
                    $zip->addEmptyDir($interna);
                } elseif ($item->isFile()) {
                    $zip->addFile($real, $interna);
                }
            }
            if ($zip->close() !== true) {
                throw new RuntimeException('No se pudo cerrar el ZIP temporal.');
            }

            self::cabeceras($base . '.zip', 'application/zip');
            header('Content-Length: ' . (string)filesize($tmp));
            readfile($tmp);
        } finally {
            if (is_file($tmp)) { @unlink($tmp); }
        }
        exit;
    }

    /**
     * Volcado completo de una base: estructura y datos, en SQL.
     *
     * Las claves foráneas y los triggers van al final, después de que existan
     * todas las tablas, así que el fichero se puede ejecutar de arriba abajo.
     *
     * @param array<int,array{tabla:string,columnas:array,claves:array,filas:array}> $tablas
     * @param array<int,array<string,mixed>> $triggers
     */
    public static function base(string $base, array $tablas, array $triggers): void
    {
        self::cabeceras($base . '.sql', 'text/plain; charset=UTF-8');

        $filas = 0;
        foreach ($tablas as $t) { $filas += count($t['filas']); }

        echo "-- jsonSQLDB · volcado de la base '$base' · " . date('Y-m-d H:i:s') . "\n";
        echo '-- ' . count($tablas) . ' tabla(s), ' . $filas . " fila(s)\n";
        echo "-- Ejecuta las sentencias en orden: primero las tablas, luego las\n";
        echo "-- restricciones y los triggers.\n";

        $restricciones = [];
        foreach ($tablas as $t) {
            echo "\n-- ------------------------------------------------------------\n";
            echo '-- Tabla: ' . $t['tabla'] . "\n";
            echo "-- ------------------------------------------------------------\n";
            echo self::createTable($t['tabla'], $t['columnas'], $t['claves']);

            foreach (self::restricciones($t['tabla'], $t['columnas'], $t['claves']) as $r) {
                $restricciones[] = $r;
            }
            if ($t['filas'] !== []) {
                echo "\n";
                echo self::lineasInsert($t['filas'], $t['tabla']);
            }
        }

        if ($restricciones !== []) {
            echo "\n-- ------------------------------------------------------------\n";
            echo "-- Claves únicas y foráneas\n";
            echo "-- ------------------------------------------------------------\n";
            echo implode("\n", $restricciones) . "\n";
        }

        if ($triggers !== []) {
            echo "\n-- ------------------------------------------------------------\n";
            echo "-- Triggers\n";
            echo "-- ------------------------------------------------------------\n";
            foreach ($triggers as $trg) {
                echo trim((string)$trg['sql']) . ";\n";
            }
        }
        exit;
    }

    /** CREATE TABLE reconstruido a partir de la estructura. */
    private static function createTable(string $tabla, array $columnas, array $claves): string
    {
        $pk = [];
        foreach ($claves as $k) {
            if ($k['tipo'] === 'PRIMARY') {
                $pk = array_filter(explode(',', (string)$k['columnas']));
            }
        }
        $compuesta = count($pk) > 1;

        $partes = [];
        foreach ($columnas as $c) {
            $d = cita((string)$c['columna']) . ' ' . self::tipo($c);
            if ((int)$c['pk'] === 1 && !$compuesta) { $d .= ' PRIMARY KEY'; }
            if ((int)$c['auto'] === 1)              { $d .= ' AUTOINCREMENT'; }
            if ((int)$c['notnull'] === 1 && (int)$c['pk'] !== 1) { $d .= ' NOT NULL'; }
            if ((int)$c['unico'] === 1)             { $d .= ' UNIQUE'; }
            if ($c['defecto'] !== null)             { $d .= ' DEFAULT ' . self::literal($c['defecto']); }
            $partes[] = $d;
        }
        if ($compuesta) {
            $partes[] = 'PRIMARY KEY (' . implode(', ', array_map('cita', $pk)) . ')';
        }

        return 'CREATE TABLE ' . cita($tabla) . " (\n  " . implode(",\n  ", $partes) . "\n);\n";
    }

    /** Declaración del tipo, con longitud o decimales si los tiene. */
    private static function tipo(array $c): string
    {
        $tipo = (string)$c['tipo'];
        if ($c['longitud'] !== null) {
            return 'VARCHAR(' . (int)$c['longitud'] . ')';
        }
        if ($c['escala'] !== null) {
            return 'DECIMAL(10,' . (int)$c['escala'] . ')';
        }
        return $tipo;
    }

    /** ALTER TABLE de las claves únicas de varias columnas y de las foráneas. */
    private static function restricciones(string $tabla, array $columnas, array $claves): array
    {
        // Las únicas de una sola columna ya salen en la propia columna
        $sueltas = [];
        foreach ($columnas as $c) {
            if ((int)$c['unico'] === 1) { $sueltas[(string)$c['columna']] = true; }
        }

        $out = [];
        foreach ($claves as $k) {
            $cols = array_filter(explode(',', (string)$k['columnas']));

            if ($k['tipo'] === 'UNIQUE') {
                if (count($cols) === 1 && isset($sueltas[$cols[0] ?? ''])) {
                    continue;
                }
                $out[] = 'ALTER TABLE ' . cita($tabla) . ' ADD CONSTRAINT ' . cita((string)$k['nombre'])
                       . ' UNIQUE (' . implode(', ', array_map('cita', $cols)) . ');';
            } elseif ($k['tipo'] === 'FOREIGN') {
                $out[] = 'ALTER TABLE ' . cita($tabla) . ' ADD CONSTRAINT ' . cita((string)$k['nombre'])
                       . ' FOREIGN KEY (' . implode(', ', array_map('cita', $cols)) . ')'
                       . ' REFERENCES ' . cita((string)$k['tabla_destino'])
                       . ' (' . implode(', ', array_map('cita', array_filter(explode(',', (string)$k['columnas_destino'])))) . ')'
                       . ' ON DELETE ' . $k['on_delete'] . ' ON UPDATE ' . $k['on_update'] . ';';
            }
        }
        return $out;
    }

    /**
     * Una sentencia INSERT por fila, lista para volver a ejecutar.
     *
     * @param array<int,array<string,mixed>> $filas
     */
    public static function inserts(array $filas, string $tabla): void
    {
        self::cabeceras($tabla . '.sql', 'text/plain; charset=UTF-8');

        echo "-- jsonSQLDB · $tabla · " . date('Y-m-d H:i:s') . "\n";
        echo '-- ' . count($filas) . " fila(s)\n\n";
        echo self::lineasInsert($filas, $tabla);
        exit;
    }

    /** @param array<int,array<string,mixed>> $filas */
    private static function lineasInsert(array $filas, string $tabla): string
    {
        if ($filas === []) {
            return '';
        }
        $cols = array_keys($filas[0]);
        $cab  = 'INSERT INTO ' . cita($tabla) . ' ('
              . implode(', ', array_map('cita', $cols)) . ') VALUES (';

        $out = '';
        foreach ($filas as $f) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = self::literal($f[$c] ?? null);
            }
            $out .= $cab . implode(', ', $vals) . ");\n";
        }
        return $out;
    }

    /** Un valor escrito como literal SQL. */
    private static function literal($v): string
    {
        if ($v === null)    { return 'NULL'; }
        if (is_bool($v))    { return $v ? '1' : '0'; }
        if (is_int($v))     { return (string)$v; }
        if (is_float($v))   { return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.'); }
        return "'" . str_replace("'", "''", (string)$v) . "'";
    }

    private static function cabeceras(string $fichero, string $tipo): void
    {
        $fichero = preg_replace('/[^A-Za-z0-9_.-]/', '_', $fichero) . '';
        $sello   = date('Ymd-Hi');
        $punto   = strrpos($fichero, '.');
        $fichero = substr($fichero, 0, $punto) . '-' . $sello . substr($fichero, $punto);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: ' . $tipo);
        header('Content-Disposition: attachment; filename="' . $fichero . '"');
        header('Cache-Control: no-store');
    }
}
