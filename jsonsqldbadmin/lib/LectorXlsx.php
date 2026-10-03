<?php
declare(strict_types=1);

/**
 * Lee la primera hoja de un .xlsx fila a fila, sin cargarla entera: con
 * ZipArchive y XMLReader, que vienen con PHP, y sin librerías externas.
 *
 * Los textos compartidos (sharedStrings.xml) se apartan a un temporal y en
 * memoria solo queda dónde empieza cada uno, 4 bytes por texto: una hoja con
 * millones de textos distintos no agota la memoria.
 *
 * Los valores salen como texto, número o null; una celda con formato de fecha
 * sale como 'yyyy-MM-dd' o 'yyyy-MM-dd HH:mm:ss', y un booleano como 1 o 0.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class LectorXlsx
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;
    /** @var resource|null los textos compartidos, uno detrás de otro */
    private $textos = null;
    /** Dónde empieza cada texto compartido en $textos, empaquetado (pack 'N') */
    private string $inicios = '';
    /** @var array<int,bool> qué estilos de celda (índice de cellXfs) son de fecha */
    private array $esFecha = [];
    private bool $fecha1904 = false;
    private string $hoja;
    /** Copia del fichero si su ruta lleva '#', que zip:// toma por el separador de la entrada */
    private ?string $copia = null;

    public function __construct(string $fichero)
    {
        if (strpos($fichero, '#') !== false) {
            $copia = tempnam(sys_get_temp_dir(), 'jsonsqldb_xlsx_');
            if ($copia === false || !@copy($fichero, $copia)) {
                if ($copia !== false) { @unlink($copia); }
                throw new RuntimeException(t('No se puede leer el fichero subido.'));
            }
            $this->copia = $copia;
            // Se borra pase lo que pase, también si la petición muere por el camino
            register_shutdown_function(static function () use ($copia): void {
                if (is_file($copia)) { @unlink($copia); }
            });
            $fichero = $copia;
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($fichero) !== true) {
            $this->borrarCopia();
            throw new RuntimeException(t('El fichero no es un Excel (.xlsx) válido.'));
        }
        $this->hoja = $this->primeraHoja();
        $this->leerEstilos();
        $this->leerTextos();
    }

    public function cerrar(): void
    {
        if ($this->textos !== null) {
            fclose($this->textos);
            $this->textos = null;
        }
        $this->zip->close();
        $this->borrarCopia();
    }

    private function borrarCopia(): void
    {
        if ($this->copia !== null && is_file($this->copia)) {
            @unlink($this->copia);
        }
    }

    /**
     * Las filas de la hoja: número de fila de Excel => valores por columna (A
     * es 0), con null en las celdas vacías de en medio. Las filas vacías no salen.
     *
     * @return Generator<int,list<mixed>>
     */
    public function filas(): Generator
    {
        $xml = $this->abrir($this->hoja);
        $fila = [];
        $num  = 0;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                $fila = [];
                $num  = (int)$xml->getAttribute('r') ?: $num + 1;
                if ($xml->isEmptyElement) {
                    continue;
                }
            } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c') {
                $col = self::columna((string)$xml->getAttribute('r'), count($fila));
                $valor = $this->celda($xml);
                if ($valor !== null) {
                    $fila = array_pad($fila, $col, null);
                    $fila[$col] = $valor;
                }
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'row' && $fila !== []) {
                yield $num => $fila;
            }
        }
        $xml->close();
    }

    /** El valor de la celda en la que está $xml, ya convertido; avanza hasta su cierre. */
    private function celda(XMLReader $xml)
    {
        $tipo   = (string)$xml->getAttribute('t');
        $estilo = (int)$xml->getAttribute('s');
        if ($xml->isEmptyElement) {
            return null;
        }
        $v = null;
        $texto = '';
        $profundidad = $xml->depth;
        while ($xml->read() && !($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $profundidad)) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'v') {
                $v = $xml->readString();
            } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 't' && $tipo === 'inlineStr') {
                $texto .= $xml->readString();          // <is><t> o varios <r><t>
            }
        }
        switch ($tipo) {
            case 'inlineStr': return $texto;
            case 's':         return $v === null ? null : $this->texto((int)$v);
            case 'str':       return $v;              // resultado de una fórmula de texto
            case 'b':         return $v === null ? null : (int)$v;
            case 'e':         return null;            // #N/A, #DIV/0!…
        }
        if ($v === null || !is_numeric($v)) {
            return $v;
        }
        if (!empty($this->esFecha[$estilo])) {
            return $this->fecha((float)$v);
        }
        return preg_match('/^-?\d+$/', $v) ? (int)$v : (float)$v;
    }

    /** Un número de serie de Excel como fecha, y con hora si la tiene. */
    private function fecha(float $serie): string
    {
        // 25569 días entre el 30-12-1899 de Excel y el 01-01-1970; 24107 con el sistema de 1904
        $segundos = (int)round(($serie - ($this->fecha1904 ? 24107 : 25569)) * 86400);
        return gmdate($segundos % 86400 === 0 ? 'Y-m-d' : 'Y-m-d H:i:s', $segundos);
    }

    /** Columna de una referencia 'AB12' (A = 0); sin referencia, la siguiente. */
    private static function columna(string $ref, int $siguiente): int
    {
        if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
            return $siguiente;
        }
        $n = 0;
        foreach (str_split($m[1]) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }
        return $n - 1;
    }

    /** La ruta dentro del ZIP de la primera hoja del libro, y si usa fechas de 1904. */
    private function primeraHoja(): string
    {
        $libro = $this->abrir('xl/workbook.xml');
        $id = null;
        while ($libro->read()) {
            if ($libro->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($libro->localName === 'workbookPr') {
                $this->fecha1904 = in_array((string)$libro->getAttribute('date1904'), ['1', 'true'], true);
            } elseif ($libro->localName === 'sheet' && $id === null) {
                $id = (string)$libro->getAttributeNs('id', self::NS_REL);
            }
        }
        $libro->close();
        if ($id !== null && $this->zip->locateName('xl/_rels/workbook.xml.rels') !== false) {
            $rels = $this->abrir('xl/_rels/workbook.xml.rels');
            while ($rels->read()) {
                if ($rels->nodeType === XMLReader::ELEMENT && $rels->localName === 'Relationship' && $rels->getAttribute('Id') === $id) {
                    $destino = ltrim((string)$rels->getAttribute('Target'), '/');
                    $rels->close();
                    return str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
                }
            }
            $rels->close();
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** Qué estilos de celda son de fecha: los formatos de fecha de Excel y los propios con d, m, y, h o s. */
    private function leerEstilos(): void
    {
        if ($this->zip->locateName('xl/styles.xml') === false) {
            return;
        }
        $xml = $this->abrir('xl/styles.xml');
        $propios = [];
        $enXfs = false;
        $i = 0;
        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'numFmt') {
                // Sin lo que va entre comillas o corchetes ([Red], "kg"): ahí una d o una m no es fecha
                $codigo = (string)preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string)$xml->getAttribute('formatCode'));
                $propios[(int)$xml->getAttribute('numFmtId')] = (bool)preg_match('/[dmyhs]/i', $codigo);
            } elseif ($xml->localName === 'cellXfs') {
                $enXfs = $xml->nodeType === XMLReader::ELEMENT && !$xml->isEmptyElement;
            } elseif ($enXfs && $xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'xf') {
                $fmt = (int)$xml->getAttribute('numFmtId');
                $this->esFecha[$i++] = ($fmt >= 14 && $fmt <= 22) || ($fmt >= 45 && $fmt <= 47) || ($propios[$fmt] ?? false);
            }
        }
        $xml->close();
    }

    /** Aparta los textos compartidos a un temporal. */
    private function leerTextos(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        $this->textos = fopen('php://temp', 'w+b');
        $xml = $this->abrir('xl/sharedStrings.xml');
        $pos = 0;
        $actual = null;
        while ($xml->read()) {
            if ($xml->localName === 'si' && $xml->nodeType === XMLReader::ELEMENT) {
                $actual = '';
                if ($xml->isEmptyElement) {
                    $this->inicios .= pack('N', $pos);
                    $actual = null;
                }
            } elseif ($actual !== null && $xml->localName === 't' && $xml->nodeType === XMLReader::ELEMENT) {
                $actual .= $xml->readString();      // <si><t> o varios <r><t> con formato
            } elseif ($actual !== null && $xml->localName === 'rPh' && $xml->nodeType === XMLReader::ELEMENT) {
                $xml->next();                       // la lectura fonética japonesa no es parte del texto
            } elseif ($xml->localName === 'si' && $xml->nodeType === XMLReader::END_ELEMENT) {
                $this->inicios .= pack('N', $pos);
                $pos += (int)fwrite($this->textos, (string)$actual);
                $actual = null;
            }
        }
        $xml->close();
        $this->inicios .= pack('N', $pos);           // el final del último
    }

    private function texto(int $i): ?string
    {
        if ($this->textos === null || 4 * ($i + 1) >= strlen($this->inicios)) {
            return null;
        }
        [, $desde, $hasta] = unpack('N2', $this->inicios, 4 * $i);
        if ($hasta <= $desde) {
            return '';
        }
        fseek($this->textos, $desde);
        return (string)fread($this->textos, $hasta - $desde);
    }

    private function abrir(string $ruta): XMLReader
    {
        $xml = new XMLReader();
        if ($this->zip->locateName($ruta) === false || !$xml->open('zip://' . $this->zip->filename . '#' . $ruta, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException(t('El fichero no es un Excel (.xlsx) válido.'));
        }
        return $xml;
    }
}
