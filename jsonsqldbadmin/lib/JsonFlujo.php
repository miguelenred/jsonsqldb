<?php
declare(strict_types=1);

/**
 * Lector de JSON por partes, en PHP puro (2.9): recorre un fichero JSON
 * leyéndolo a trozos de 64 KB, sin cargarlo entero, para importar ficheros
 * más grandes que la memoria de PHP.
 *
 * Se va por la estructura con objeto() y lista(), que dan cada clave o cada
 * elemento; quien los recorre lee entonces el valor con valor() (que lo
 * decodifica entero: una fila, la estructura de una tabla) o se mete dentro
 * con objeto() o lista(). Lo que no interesa se salta con saltar().
 *
 *   $j = new JsonFlujo($fh, 8 << 20);          // un valor, como mucho 8 MB
 *   foreach ($j->lista() as $i) {
 *       $fila = $j->valor();
 *   }
 *
 * https://miguelenred.es/jsonsqldb
 */
final class JsonFlujo
{
    /** Lo que puede ocupar un solo valor decodificado de una vez */
    private int $tope;
    /** @var resource */
    private $fh;
    private string $buf = '';
    private int $pos = 0;

    /** Bytes que se leen de cada vez */
    private int $trozo;

    /** @param resource $fh */
    public function __construct($fh, int $tope, int $trozo = 65536)
    {
        $this->fh    = $fh;
        $this->tope  = $tope;
        $this->trozo = max(1, $trozo);
        // Sin la marca de UTF-8 delante, si la trae
        while (strlen($this->buf) < 3 && $this->leerMas()) {
            // hasta tener los tres bytes de la marca, si los hay
        }
        if (strncmp($this->buf, "\xEF\xBB\xBF", 3) === 0) {
            $this->pos = 3;
        }
    }

    /**
     * Las claves de un objeto, una a una. Tras cada una hay que leer su valor
     * (valor(), objeto(), lista() o saltar()) antes de pedir la siguiente.
     *
     * @return \Generator<int, string>
     */
    public function objeto(): \Generator
    {
        $this->esperar('{');
        if ($this->comer('}')) {
            return;
        }
        do {
            if ($this->mirar() !== '"') {
                throw new RuntimeException(t('JSON mal formado cerca del byte {pos}: se esperaba el nombre de una clave', ['pos' => $this->posicion()]));
            }
            $clave = $this->valor();
            $this->esperar(':');
            yield (string)$clave;
        } while ($this->comer(','));
        $this->esperar('}');
    }

    /**
     * Los elementos de una lista, uno a uno: da su número (desde 1). Tras cada
     * uno hay que leerlo antes de pedir el siguiente.
     *
     * @return \Generator<int, int>
     */
    public function lista(): \Generator
    {
        $this->esperar('[');
        if ($this->comer(']')) {
            return;
        }
        $n = 0;
        do {
            yield ++$n;
        } while ($this->comer(','));
        $this->esperar(']');
    }

    /** El siguiente valor, decodificado entero (texto, número, objeto o lista). */
    public function valor()
    {
        $texto = $this->extension();
        $v = json_decode($texto, true, 512, JSON_BIGINT_AS_STRING);
        if ($v === null && strtolower(trim($texto)) !== 'null') {
            throw new RuntimeException(t('JSON mal formado cerca del byte {pos}: {error}', ['pos' => $this->posicion(), 'error' => json_last_error_msg()]));
        }
        return $v;
    }

    /** Se salta el siguiente valor sin decodificarlo. */
    public function saltar(): void
    {
        $this->extension();
    }

    /** ¿Queda algo más que espacios? (al final del documento no debe) */
    public function quedaAlgo(): bool
    {
        return $this->mirar() !== '';
    }

    /** El carácter con que empieza lo siguiente: { [ " un dígito… ('' al final) */
    public function siguiente(): string
    {
        return $this->mirar();
    }

    /**
     * El texto del siguiente valor, y avanza tras él: se recorre contando las
     * llaves y los corchetes, sin contar los que van dentro de un texto.
     */
    private function extension(): string
    {
        $c = $this->mirar();
        if ($c === '') {
            throw new RuntimeException(t('El JSON se acaba antes de tiempo.'));
        }
        $ini = $this->pos;
        $nivel = 0;
        $enTexto = false;
        $escapado = false;
        for ($i = $this->pos; ; $i++) {
            if ($i >= strlen($this->buf)) {
                // Se queda corto: se lee más, conservando lo que ya se ha recorrido
                $hecho = $i - $ini;
                if ($hecho > $this->tope) {
                    throw new RuntimeException(t('Un valor del JSON, cerca del byte {pos}, ocupa más de {mb} MB y no cabe en la memoria de PHP.',
                        ['pos' => $this->posicion(), 'mb' => max(1, intdiv($this->tope, 1048576))]));
                }
                $this->buf = substr($this->buf, $ini);
                $this->pos = $ini = 0;
                $i = $hecho;
                if (!$this->leerMas()) {
                    if ($nivel === 0 && !$enTexto && $hecho > 0) {
                        break;                                     // un número o un literal al final
                    }
                    throw new RuntimeException(t('El JSON se acaba antes de tiempo.'));
                }
            }
            $ch = $this->buf[$i];
            if ($enTexto) {
                if ($escapado) {
                    $escapado = false;
                } elseif ($ch === '\\') {
                    $escapado = true;
                } elseif ($ch === '"') {
                    $enTexto = false;
                    if ($nivel === 0) { $i++; break; }
                }
                continue;
            }
            if ($ch === '"') {
                $enTexto = true;
            } elseif ($ch === '{' || $ch === '[') {
                $nivel++;
            } elseif ($ch === '}' || $ch === ']') {
                if ($nivel === 0) { break; }
                $nivel--;
                if ($nivel === 0) { $i++; break; }
            } elseif ($nivel === 0 && ($ch === ',' || ctype_space($ch))) {
                break;
            }
        }
        $texto = substr($this->buf, $ini, $i - $ini);
        $this->pos = $i;
        return $texto;
    }

    private function esperar(string $c): void
    {
        if (!$this->comer($c)) {
            $visto = $this->mirar();
            throw new RuntimeException(t('JSON mal formado cerca del byte {pos}: se esperaba «{esperado}» y hay «{visto}»',
                ['pos' => $this->posicion(), 'esperado' => $c, 'visto' => $visto === '' ? t('el final') : $visto]));
        }
    }

    private function comer(string $c): bool
    {
        if ($this->mirar() !== $c) {
            return false;
        }
        $this->pos++;
        return true;
    }

    /** El siguiente carácter que no es espacio, sin avanzar; '' al final. */
    private function mirar(): string
    {
        while (true) {
            $largo = strlen($this->buf);
            while ($this->pos < $largo && ctype_space($this->buf[$this->pos])) {
                $this->pos++;
            }
            if ($this->pos < $largo) {
                return $this->buf[$this->pos];
            }
            if (!$this->cargar()) {
                return '';
            }
        }
    }

    /** Descarta lo leído y trae otro trozo; false al final del fichero. */
    private function cargar(): bool
    {
        $this->buf = (string)substr($this->buf, $this->pos);
        $this->pos = 0;
        return $this->leerMas();
    }

    private function leerMas(): bool
    {
        if (feof($this->fh)) {
            return false;
        }
        $trozo = fread($this->fh, $this->trozo);
        if ($trozo === false || $trozo === '') {
            return false;
        }
        $this->buf .= $trozo;
        return true;
    }

    /** Byte del fichero por el que va, para los mensajes */
    private function posicion(): int
    {
        return (int)ftell($this->fh) - strlen($this->buf) + $this->pos;
    }
}
