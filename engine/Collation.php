<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Colación de texto para ORDER BY.
 *
 * Con la colación 'binaria' se ordena byte a byte (UTF-8), que es lo que hace
 * SQLite por defecto: las mayúsculas van antes que las minúsculas y todo lo
 * acentuado va al final. Correcto para una máquina, raro para una persona.
 *
 * Con 'general' se convierte cada texto en una clave de ordenación donde las
 * mayúsculas y los acentos no cuentan, y las letras propias de un idioma van
 * en el sitio que les toca. Si dos textos dan la misma clave, se desempata
 * byte a byte, así que el orden es estable y determinista.
 *
 * El alfabeto NO es igual en todos los idiomas: en español la 'ñ' va después
 * de la 'n', pero en sueco 'å', 'ä' y 'ö' son letras propias que van DESPUÉS
 * de la 'z', no variantes de 'a' y 'o'. Por eso el mapa base se puede ampliar
 * o corregir desde config.php con JSONSQLDB_COLACION_MAPA, sin tocar el motor.
 *
 * No usa mbstring ni intl: en hosting compartido no siempre están.
 *
 * Solo afecta a ORDER BY. Las comparaciones (=, <, >), las claves únicas, los
 * GROUP BY y los DISTINCT siguen siendo exactos: 'Óscar' y 'oscar' son y
 * seguirán siendo dos valores distintos.
 */
final class Collation
{
    /**
     * Variantes de cada letra base, en minúscula y mayúscula.
     * Se reducen a la letra base, así que ordenan junto a ella.
     */
    private const VARIANTES = [
        'a' => 'áàäâãåāăąÁÀÄÂÃÅĀĂĄ',
        'c' => 'çćčĉÇĆČĈ',
        'd' => 'ďđðĎĐÐ',
        'e' => 'éèëêēĕęėÉÈËÊĒĔĘĖ',
        'g' => 'ğĝģĞĜĢ',
        'h' => 'ĥĤ',
        'i' => 'íìïîīįıÍÌÏÎĪĮİ',
        'j' => 'ĵĴ',
        'k' => 'ķĶ',
        'l' => 'łĺľļŁĹĽĻ',
        'n' => 'ńňņŃŇŅ',
        'o' => 'óòöôõōøőÓÒÖÔÕŌØŐ',
        'r' => 'řŕŘŔ',
        's' => 'śšşŝșŚŠŞŜȘ',
        't' => 'ťţțŤŢȚ',
        'u' => 'úùüûūůűųÚÙÜÛŪŮŰŲ',
        'w' => 'ŵŴ',
        'y' => 'ýÿÝŸ',
        'z' => 'źżžŹŻŽ',
    ];

    /**
     * Letras que no son variantes de otra: ocupan una posición propia o
     * equivalen a varias letras.
     *
     * El sufijo '{' es el primer carácter ASCII posterior a la 'z', así que
     * "ñu" queda entre "nz" y "oa", que es donde le toca en español.
     */
    private const PROPIAS = [
        'ñ' => 'n{', 'Ñ' => 'n{',
        'ß' => 'ss',
        'æ' => 'ae', 'Æ' => 'ae',
        'œ' => 'oe', 'Œ' => 'oe',
        'þ' => 'th', 'Þ' => 'th',
    ];

    /** @var array<string,string>|null mapa completo, montado una sola vez */
    private static ?array $mapa = null;

    /** ¿Está activa la colación por idioma? */
    public static function activa(): bool
    {
        return Config::colacion() !== 'binaria';
    }

    /**
     * Clave de ordenación de un texto. Dos textos que solo se diferencian en
     * mayúsculas o acentos dan la misma clave.
     */
    public static function clave(string $texto): string
    {
        // Primero las variantes, que ya contemplan ambas cajas; después
        // strtolower, que a esas alturas solo tiene que bajar ASCII.
        return strtolower(strtr($texto, self::mapa()));
    }

    /** @return array<string,string> */
    private static function mapa(): array
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }
        $mapa = [];
        foreach (self::VARIANTES as $base => $letras) {
            foreach ((array)preg_split('//u', $letras, -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $mapa[$c] = $base;
            }
        }
        // Las propias y las del config van al final: pueden corregir lo anterior
        return self::$mapa = array_merge($mapa, self::PROPIAS, Config::colacionMapa());
    }
}
