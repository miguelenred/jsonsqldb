<?php
declare(strict_types=1);

/**
 * Configuración inicial de jsonSQLDB.
 *
 *   php configurar.php            crea los dos ficheros de configuración
 *   php configurar.php --local    y además permite HTTP, para probar en tu máquina
 *
 * Copia los dos ficheros .dist y sustituye cada CHANGE_ME_ por un valor
 * aleatorio, cuidando de que la clave y el secreto del panel coincidan con los
 * de su cuenta en la API: si no coinciden, el panel no puede hablar con el
 * motor.
 *
 * Existe porque hacerlo a mano son nueve valores repartidos en dos ficheros,
 * dos de ellos repetidos, y equivocarse no da un error claro. Y porque los .dist
 * traen los mismos marcadores en los dos sitios: sin sustituirlos todo
 * funcionaría, con una clave y un secreto que están publicados en el
 * repositorio. Ahora ni la API ni el panel arrancan mientras queden.
 *
 * No sobrescribe nada: si un fichero ya existe, lo dice y no lo toca.
 *
 * https://miguelenred.es/jsonsqldb
 */

// Solo desde la línea de órdenes: por el navegador escribiría las claves de
// todo y diría cuáles son a quien lo abriera
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$raiz  = __DIR__;
$local = in_array('--local', $argv, true);

$ficheros = [
    'api/jsonsqldb_api_config.php'  => 'api/jsonsqldb_api_config.dist.php',
    'jsonsqldbadmin/config.php'     => 'jsonsqldbadmin/config.dist.php',
];


foreach ($ficheros as $destino => $plantilla) {
    if (is_file("$raiz/$destino")) {
        echo "Ya existe $destino: no se toca.\n";
        echo "Si quieres empezar de cero, bórralo y vuelve a ejecutar esto.\n";
        exit(1);
    }
    if (!is_file("$raiz/$plantilla")) {
        echo "Falta la plantilla $plantilla.\n";
        exit(1);
    }
}

/** Un valor aleatorio de 64 hexadecimales. */
function secreto(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Escribe un fichero con claves: el temporal, al lado, nace ya solo para su
 * dueño (tempnam, 0600) con un nombre que no se puede adivinar, y se pone en su
 * sitio con rename, que no sigue un enlace que hubiera allí (2.10: se escribía
 * con los permisos del umask y el chmod llegaba después).
 */
function escribirPrivado(string $destino, string $texto): bool
{
    $dir = dirname($destino);
    $tmp = @tempnam($dir, basename($destino) . '.');
    if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir)) {
        if ($tmp !== false) { @unlink($tmp); }   // sin sitio en $dir, tempnam lo crea en el temporal del sistema
        return false;
    }
    if (@file_put_contents($tmp, $texto) !== strlen($texto) || !@rename($tmp, $destino)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// Los marcadores del panel y los de su cuenta en la API son los mismos, así que
// se generan una vez y se sustituyen en los dos ficheros con el mismo valor.
$valores = [];
foreach (['CHANGE_ME_ADMIN_API_KEY', 'CHANGE_ME_ADMIN_SECRET',
          'CHANGE_ME_APP_API_KEY', 'CHANGE_ME_APP_SECRET',
          'CHANGE_ME_EXAMPLE_API_KEY', 'CHANGE_ME_EXAMPLE_SECRET'] as $marcador) {
    $valores[$marcador] = secreto();
}

// Los marcadores más largos primero: si no, uno que sea prefijo de otro lo
// dejaría a medias
$orden = array_keys($valores);
usort($orden, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

foreach ($ficheros as $destino => $plantilla) {
    $texto = (string)file_get_contents("$raiz/$plantilla");

    foreach ($orden as $marcador) {
        $texto = str_replace($marcador, $valores[$marcador], $texto);
    }

    if ($local) {
        $texto = str_replace(
            ["define('EXIGIR_HTTPS', true)", "define('ADMIN_EXIGIR_HTTPS', true)"],
            ["define('EXIGIR_HTTPS', false)", "define('ADMIN_EXIGIR_HTTPS', false)"],
            $texto
        );
    }

    if (!escribirPrivado("$raiz/$destino", $texto)) {
        echo "No se pudo escribir $destino. Comprueba los permisos de la carpeta.\n";
        exit(1);
    }
    // Estos dos ficheros son el secreto entero del sistema, y los demás
    // usuarios de la máquina, en un hosting compartido, son desconocidos: ya
    // nacen con 0600, y si el sistema de ficheros no admite ponérselos, se dice
    if (!@chmod("$raiz/$destino", 0600)) {
        echo "  aviso: no se pudieron restringir los permisos de $destino.\n";
        echo "  Hazlo a mano (chmod 600): contiene las claves de acceso.\n";
    }
    echo "Creado $destino\n";
}

// La clave de los clientes de ejemplo no se escribe en ellos: están en api/ y
// la web los serviría a quien los pidiera, con una clave que puede escribir en
// la base 'pruebas'. Se dice aquí, para pasarla por variables de entorno.
echo "\nClientes de ejemplo (api/cliente_ejemplo.*): cuenta 'Clientes de ejemplo', escritura sobre 'pruebas'.\n"
   . "Pásales la clave por variables de entorno, nunca escrita en un fichero que sirva la web:\n"
   . "  JSONSQLDB_API_KEY=" . $valores['CHANGE_ME_EXAMPLE_API_KEY'] . "\n"
   . "  JSONSQLDB_HMAC_SECRET=" . $valores['CHANGE_ME_EXAMPLE_SECRET'] . "\n"
   . "  JSONSQLDB_URL=https://tu-servidor/jsonsqldb/api/jsonsqldb_api.php\n";

// Comprobar que no ha quedado ningún marcador SIN SUSTITUIR. Se miran solo los
// que están entre comillas, que son valores; los .dist mencionan CHANGE_ME_ en
// sus comentarios para explicar qué hay que hacer, y eso no estorba.
$restos = [];
foreach (array_keys($ficheros) as $destino) {
    if (preg_match("/['\"]CHANGE_ME[A-Z_]*['\"]/", (string)file_get_contents("$raiz/$destino"))) {
        $restos[] = $destino;
    }
}
if ($restos !== []) {
    echo "\nATENCIÓN: quedan valores CHANGE_ME_ en " . implode(' y ', $restos) . ".\n";
    echo "Cámbialos a mano; ni la API ni el panel arrancan mientras estén.\n";
    exit(1);
}

echo "\nListo. Claves generadas al azar y coincidiendo entre panel y API.\n";
if ($local) {
    echo "\nHTTPS desactivado en los dos, para poder probar por HTTP en tu máquina.\n";
    echo "VUELVE A PONERLO a true en los dos ficheros antes de publicar esto.\n";
}
// El código que pide el panel para crear el administrador (el mismo fichero
// que crea el panel si no existe; ver jsonsqldbadmin/lib/Instalador.php)
$ficheroCodigo = "$raiz/jsonsqldbadmin/datos/codigo-instalacion.txt";
$codigo = is_file($ficheroCodigo) ? trim((string)file_get_contents($ficheroCodigo)) : '';
if (!preg_match('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $codigo)) {
    $codigo = implode('-', str_split(bin2hex(random_bytes(8)), 4));
    // Si no se puede guardar, se dice (2.10: se enseñaba un código que el
    // panel no iba a conocer). La carpeta, como la crea el panel si falta
    if (!is_dir(dirname($ficheroCodigo))) {
        @mkdir(dirname($ficheroCodigo), 0775, true);
    }
    if (!escribirPrivado($ficheroCodigo, $codigo . "\n")) {
        fwrite(STDERR, "\nNo se pudo guardar el código de instalación en $ficheroCodigo: comprueba los permisos de esa carpeta.\n");
        exit(1);
    }
}

echo "\nAhora abre jsonsqldbadmin/ en el navegador: te pedirá crear el usuario\n";
echo "administrador. No hay usuario por defecto ni contraseña que cambiar.\n";
echo "Te pedirá también este código de instalación: $codigo\n";
echo "(está en jsonsqldbadmin/datos/codigo-instalacion.txt y se borra al terminar)\n";
