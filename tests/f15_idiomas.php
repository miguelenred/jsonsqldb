<?php
declare(strict_types=1);

/**
 * Idiomas del panel. Ejecutar: php tests/f15_idiomas.php
 *
 * - Cada texto que el panel pasa por t() tiene su traducción en
 *   jsonsqldbadmin/idiomas/en.php, y en ese fichero no sobra ninguna.
 * - Cada traducción conserva los {marcadores} y las etiquetas HTML de su texto.
 * - El idioma del navegador se elige bien: el primero de su lista que haya,
 *   por orden de preferencia; si no hay ninguno, inglés.
 *
 * Que el panel se vea en inglés, que el selector guarde el idioma en el
 * usuario y que le siga en otra sesión lo comprueba tests/f11_asistente.php,
 * con el panel funcionando.
 *
 * https://miguelenred.es/jsonsqldb
 */
$panel = dirname(__DIR__) . '/jsonsqldbadmin';
require $panel . '/lib/Idioma.php';

$ok = 0; $ko = 0;
function chk(string $titulo, callable $fn): void
{
    global $ok, $ko;
    $r = $fn();
    if ($r === true) { $ok++; echo "  OK   $titulo\n"; }
    else { $ko++; echo "  FALLO $titulo -> $r\n"; }
}

/** Las claves de t(…) del código del panel: literales entre comillas simples o dobles. */
function clavesDelCodigo(string $panel): array
{
    $claves = [];
    $ficheros = array_merge(glob("$panel/*.php"), glob("$panel/lib/*.php"), glob("$panel/vistas/*.php"));
    foreach ($ficheros as $f) {
        $s = (string)file_get_contents($f);
        preg_match_all('/(?<![A-Za-z0-9_>$])t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $s, $m);
        foreach ($m[1] as $lit) {
            // El literal tal como lo vería PHP: se evalúa, sin ejecutar nada más
            $claves[eval('return ' . $lit . ';')] = basename($f);
        }
    }
    return $claves;
}

$en = require $panel . '/idiomas/en.php';
$codigo = clavesDelCodigo($panel);

echo "\n== Traducciones ==\n";
chk('cada texto del panel tiene traducción al inglés', function () use ($codigo, $en) {
    $faltan = array_diff_key($codigo, $en);
    return $faltan === [] ?: count($faltan) . ' sin traducir, como: «' . array_key_first($faltan) . '» (' . reset($faltan) . ')';
});
chk('en el fichero de inglés no sobra ninguna', function () use ($codigo, $en) {
    $sobran = array_diff_key($en, $codigo);
    return $sobran === [] ?: count($sobran) . ' que ya no se usan, como: «' . array_key_first($sobran) . '»';
});
chk('cada traducción conserva sus marcadores y sus etiquetas', function () use ($en) {
    foreach ($en as $es => $ingles) {
        foreach (['/\{[a-z0-9]+\}/', '/<\/?[a-z]+/'] as $patron) {
            preg_match_all($patron, $es, $a);
            preg_match_all($patron, $ingles, $b);
            sort($a[0]);
            sort($b[0]);
            if ($a[0] !== $b[0]) { return "«$es»"; }
        }
    }
    return true;
});
chk('ninguna traducción está vacía', function () use ($en) {
    foreach ($en as $es => $ingles) {
        if (trim($ingles) === '') { return "«$es» está vacía"; }
    }
    return true;
});

echo "\n== Idioma del navegador ==\n";
chk('el primero de su lista que el panel tenga; si no hay ninguno, inglés', function () {
    $casos = [
        'es-ES,es;q=0.9,en;q=0.8' => 'es',
        'es-MX'                   => 'es',
        'en-GB,en;q=0.9'          => 'en',
        'fr-FR,fr;q=0.9'          => 'en',
        'fr-FR,es;q=0.8,en;q=0.5' => 'es',
        'de,en;q=0.7,es;q=0.9'    => 'es',
        'sv-SE'                   => 'en',
        'es;q=0'                  => 'en',
        ''                        => 'en',
        'basura;;,'               => 'en',
    ];
    foreach ($casos as $cabecera => $esperado) {
        $r = Idioma::delNavegador($cabecera);
        if ($r !== $esperado) { return "«$cabecera» dio $r y no $esperado"; }
    }
    return true;
});
chk('los textos y los números salen en el idioma elegido', function () {
    Idioma::elegir('en');
    $a = [t('Guardar'), t('{n} fila(s)', ['n' => 3]), Idioma::numero(1234.5, 1)];
    Idioma::elegir('es');
    $b = [t('Guardar'), t('{n} fila(s)', ['n' => 3]), Idioma::numero(1234.5, 1)];
    return $a === ['Save', '3 row(s)', '1,234.5'] && $b === ['Guardar', '3 fila(s)', '1.234,5'] ?: json_encode([$a, $b]);
});

echo "\n---------------------------------------\n";
echo "OK: $ok   FALLOS: $ko\n";
exit($ko === 0 ? 0 : 1);
