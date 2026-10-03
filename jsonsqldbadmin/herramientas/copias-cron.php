<?php
declare(strict_types=1);

/**
 * Copias programadas desde el cron del sistema o el Programador de tareas de
 * Windows. Hace las que tocan según la programación de la página «Copias
 * programadas» del panel; las demás, no. Cada 15 minutos basta:
 *
 *   0,15,30,45 * * * * php /ruta/a/jsonsqldbadmin/herramientas/copias-cron.php
 *
 * Solo desde la línea de comandos: desde el navegador no se sirve (la
 * carpeta herramientas/ está bloqueada, y además se comprueba aquí).
 *
 * https://miguelenred.es/jsonsqldb
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/lib/arranque.php';

if (Instalador::faltaConfig()) {
    fwrite(STDERR, "jsonSQLDBadmin no está configurado todavía.\n");
    exit(1);
}
@set_time_limit(0);
$error = false;
foreach (Copias::ejecutarPendientes() as $linea) {
    echo date('Y-m-d H:i:s') . ' ' . $linea . "\n";
}
foreach (Copias::programaciones() as $p) {
    $error = $error || ($p['error'] ?? null) !== null;
}
exit($error ? 1 : 0);
