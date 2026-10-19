<?php
declare(strict_types=1);

/**
 * Escritor de Excel (.xlsx) por partes (2.9): cada hoja se escribe fila a
 * fila en un fichero temporal y al final se empaqueta, así que en memoria
 * solo está la fila en curso. Necesita la extensión zip de PHP.
 *
 * Los números van como números y las fechas como fechas de Excel (con su
 * formato), para poder sumar, ordenar y filtrar; el resto, como texto. Excel
 * admite 1.048.576 filas por hoja: las que pasan van a otra hoja, con la
 * misma cabecera. Y 32.767 caracteres por celda: lo que pasa se corta, y
 * cada corte se apunta en una hoja «Avisos».
 *
 * https://miguelenred.es/jsonsqldb
 */
final class EscritorXlsx
{
    private const FILAS_HOJA  = 1048576;
    private const CARACTERES  = 32767;
    /** Enteros de más cifras pierden precisión en Excel (15 cifras): van como texto */
    private const CIFRAS      = 15;

    /** @var list<string> cabecera */
    private array $columnas;
    /** @var array<string, string> columna => tipo, si se sabe (exportar una tabla); si no, se mira cada valor */
    private array $tipos;
    /** @var list<string> ficheros temporales de cada hoja */
    private array $hojas = [];
    /** @var resource|null */
    private $actual = null;
    private int $fila = 0;
    /** @var list<array{0: string, 1: int, 2: string, 3: int}> hoja, fila, columna y longitud de cada texto cortado */
    private array $avisos = [];
    private int $raros = 0;

    /**
     * @param list<string>          $columnas
     * @param array<string, string> $tipos
     */
    public function __construct(array $columnas, array $tipos = [])
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(t('Exportar a Excel necesita la extensión zip de PHP.'));
        }
        $this->columnas = $columnas;
        $this->tipos    = array_change_key_case($tipos, CASE_LOWER);
        // Las hojas se borran también si la petición muere por el camino (la
        // memoria, el tiempo), cuando ningún finally llega a ejecutarse (2.9)
        register_shutdown_function([$this, 'borrarTemporales']);
        $this->nuevaHoja();
    }

    /** @param array<string|int, mixed> $valores una fila, en el orden de las columnas */
    public function fila(array $valores): void
    {
        if ($this->fila >= self::FILAS_HOJA) {
            $this->nuevaHoja();
        }
        $this->fila++;
        $celdas = '';
        foreach (array_values($valores) as $i => $v) {
            $celdas .= $this->celda($i, $v);
        }
        fwrite($this->actual, '<row r="' . $this->fila . '">' . $celdas . "</row>\n");
    }

    /**
     * Cierra el libro en el fichero $destino. Los temporales de las hojas se
     * borran siempre, haya ido bien o no.
     */
    public function guardar(string $destino): void
    {
        try {
            $this->cerrarHoja();
            $conAvisos = $this->avisos !== [] || $this->raros > 0;
            if ($conAvisos) {
                $this->hojaDeAvisos();          // la última hoja
            }
            $zip = new ZipArchive();
            if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(t('No se pudo crear el fichero de Excel.'));
            }
            $n = count($this->hojas);
            $hojas = $tipos = $rels = '';
            for ($i = 1; $i <= $n; $i++) {
                $nombre = $conAvisos && $i === $n ? t('Avisos') : t('Hoja {n}', ['n' => $i]);
                $hojas .= '<sheet name="' . h($nombre) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
                $tipos .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rels  .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
                $zip->addFile($this->hojas[$i - 1], 'xl/worksheets/sheet' . $i . '.xml');
            }
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $zip->addFromString('[Content_Types].xml', $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . $tipos . '</Types>');
            $zip->addFromString('_rels/.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>');
            $zip->addFromString('xl/workbook.xml', $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $hojas . '</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . $rels . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>');
            // Estilo 1: fecha; 2: fecha y hora; 3: cabecera en negrita
            $zip->addFromString('xl/styles.xml', $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm:ss"/></numFmts>'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
                . '<cellXfs count="4"><xf/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="165" applyNumberFormat="1"/><xf fontId="1" applyFont="1"/></cellXfs>'
                . '</styleSheet>');
            if (!$zip->close()) {
                throw new RuntimeException(t('No se pudo crear el fichero de Excel.'));
            }
        } finally {
            $this->borrarTemporales();
        }
    }

    /** Borra los ficheros temporales de las hojas (también si la exportación se para). */
    public function borrarTemporales(): void
    {
        // La hoja puede estar ya cerrada: si PHP se para justo después de
        // cerrarla en cerrarHoja(), aún sin olvidarla (2.10: fclose() daba un
        // error y las hojas temporales se quedaban en el disco)
        if (is_resource($this->actual)) {
            fclose($this->actual);
        }
        $this->actual = null;
        foreach ($this->hojas as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function nuevaHoja(): void
    {
        $this->cerrarHoja();
        $f = ficheroTemporal('jsonsqldb_hoja_');
        $fh = $f === false ? false : fopen($f, 'wb');
        if ($fh === false) {
            throw new RuntimeException(t('No se pudo crear el fichero de Excel.'));
        }
        $this->hojas[] = (string)$f;
        $this->actual = $fh;
        $this->fila = 1;
        fwrite($fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . "\n");
        $celdas = '';
        foreach ($this->columnas as $i => $c) {
            $celdas .= '<c r="' . self::letra($i) . '1" t="inlineStr" s="3"><is><t xml:space="preserve">' . h($this->limpio($c, $i)) . '</t></is></c>';
        }
        fwrite($fh, '<row r="1">' . $celdas . "</row>\n");
    }

    private function cerrarHoja(): void
    {
        if ($this->actual !== null) {
            fwrite($this->actual, "</sheetData></worksheet>\n");
            fclose($this->actual);
            $this->actual = null;
        }
    }

    private function celda(int $i, $v): string
    {
        if ($v === null) {
            return '';
        }
        $ref  = self::letra($i) . $this->fila;
        $tipo = $this->tipos[strtolower($this->columnas[$i] ?? '')] ?? null;
        if (is_bool($v)) {
            $v = $v ? 1 : 0;
        }
        if (is_int($v) && strlen((string)abs($v)) <= self::CIFRAS) {
            return '<c r="' . $ref . '"><v>' . $v . '</v></c>';
        }
        if (is_float($v) && is_finite($v)) {
            return '<c r="' . $ref . '"><v>' . json_encode($v) . '</v></c>';
        }
        $texto = is_string($v) ? $v : (string)json_encode($v);
        // Una fecha: la de una columna DATETIME o, si no se sabe el tipo, un texto con esa forma
        if (($tipo === null || $tipo === 'DATETIME') && is_string($v)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)
            && (int)$m[1] >= 1900 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && !($m[1] === '1900' && $m[2] < '03')) {
            $dias = (gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) / 86400) + 25569;
            $hora = isset($m[4]) ? ((int)$m[4] * 3600 + (int)$m[5] * 60 + (int)($m[6] ?? 0)) / 86400 : 0;
            return '<c r="' . $ref . '" s="' . (isset($m[4]) ? 2 : 1) . '"><v>' . json_encode($dias + $hora) . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . h($this->limpio($texto, $i)) . '</t></is></c>';
    }

    /**
     * Un texto que cabe en una celda: lo que pasa de 32.767 caracteres se
     * corta (y se apunta), y los caracteres de control que XML no admite se
     * quitan (y se cuentan).
     */
    private function limpio(string $texto, int $i): string
    {
        $sin = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto, -1, $quitados);
        $this->raros += $quitados;
        $largo = largoUtf8($sin);
        if ($largo > self::CARACTERES) {
            $this->avisos[] = [t('Hoja {n}', ['n' => count($this->hojas)]), $this->fila, $this->columnas[$i] ?? self::letra($i), $largo];
            $sin = (string)preg_replace('/^(.{' . self::CARACTERES . '}).*$/su', '$1', $sin);
        }
        return $sin;
    }

    /** La hoja «Avisos»: un texto cortado por fila, y los caracteres quitados */
    private function hojaDeAvisos(): void
    {
        $avisos = $this->avisos;
        $raros  = $this->raros;
        $this->columnas = [t('Hoja'), t('Fila'), t('Columna'), t('Caracteres que tenía'), t('Aviso')];
        $this->tipos = [];
        $this->avisos = [];
        $this->nuevaHoja();
        foreach ($avisos as [$hoja, $fila, $col, $largo]) {
            $this->fila([$hoja, $fila, $col, $largo, t('Excel admite 32.767 caracteres por celda: el texto está cortado')]);
        }
        if ($raros > 0) {
            $this->fila([null, null, null, null, t('{n} caracter(es) de control que Excel no admite se han quitado', ['n' => $raros])]);
        }
        $this->cerrarHoja();
    }

    /** A, B, … Z, AA, AB… */
    private static function letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

}
