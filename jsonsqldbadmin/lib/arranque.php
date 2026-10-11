<?php
declare(strict_types=1);

/**
 * Carga la configuración y las clases del panel: lo comparten index.php y
 * herramientas/copias-cron.php.
 *
 * Primero config.php, si existe; después la plantilla, que solo define lo que
 * falte. Así un config.php de una versión anterior, sin las opciones nuevas,
 * sigue funcionando con sus valores por defecto, y sin config.php se arranca
 * con los de la plantilla, lo justo para que el asistente pueda pintarse.
 *
 * https://miguelenred.es/jsonsqldb
 */
$rutaConfig = (string)(getenv('JSONSQLDBADMIN_CONFIG') ?: dirname(__DIR__) . '/config.php');
if (is_file($rutaConfig)) {
    require_once $rutaConfig;
}
require_once dirname(__DIR__) . '/config.dist.php';
foreach (['Store', 'Auth', 'Audit', 'Api', 'Exportar', 'Lotes', 'Subidas', 'Copias', 'Traductor', 'Importar', 'JsonFlujo', 'EscritorXlsx',
          'LectorXlsx', 'util', 'acciones', 'iconos', 'Instalador', 'Idioma'] as $fichero) {
    require_once __DIR__ . "/$fichero.php";
}
// La zona horaria del motor, si está en esta máquina: la que fija su config.php
// (2.9). Sin esto, la auditoría y las copias programadas usaban la de php.ini,
// salvo en las peticiones que cargaban el motor, y un mismo día quedaba
// repartido entre dos ficheros de auditoría. Sin avisos si open_basedir no
// deja llegar al motor: entonces se queda la de php.ini
$configMotor = Api::rutaMotor() . '/config.php';
if (@is_file($configMotor) && preg_match("/^date_default_timezone_set\\('([^']+)'\\);/m", (string)@file_get_contents($configMotor), $zona)) {
    @date_default_timezone_set($zona[1]);
}
unset($configMotor, $zona);
