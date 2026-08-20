<?php
// ============================================================
// jsonSQLDB - Configuración general
// Este fichero NO debe ser accesible desde el navegador.
// ============================================================

// ------------------------------------------------------------
// DATOS
// ------------------------------------------------------------
// Carpeta raíz donde vive una subcarpeta por cada base de datos.
// Mejor fuera del webroot. Si no puede ser, el .htaccess de esa
// carpeta la protege (ver docs/02-seguridad.md).
defined('JSONSQLDB_DATA_PATH') || define('JSONSQLDB_DATA_PATH', __DIR__ . '/data');

// Filas por fichero de datos antes de partir la tabla en varios ficheros.
// Más filas = menos ficheros pero cada lectura carga más de golpe.
defined('JSONSQLDB_FILAS_POR_PARTE') || define('JSONSQLDB_FILAS_POR_PARTE', 5000);

// Caché de tablas (APCu si está disponible, si no en <base>/.cache).
// Se invalida sola en cada escritura. Ponerlo a false solo para depurar.
defined('JSONSQLDB_CACHE_ACTIVA') || define('JSONSQLDB_CACHE_ACTIVA', true);

// ------------------------------------------------------------
// LOG DE CONSULTAS
// ------------------------------------------------------------
// Registra cada consulta ejecutada: fecha, IP, base de datos, tipo de
// operación, la SQL, los registros mostrados o afectados, el tiempo y
// el error si lo hubo.
defined('JSONSQLDB_LOG_ACTIVO') || define('JSONSQLDB_LOG_ACTIVO', true);

// Carpeta de los ficheros de log. Se crea sola si no existe.
defined('JSONSQLDB_LOG_PATH') || define('JSONSQLDB_LOG_PATH', __DIR__ . '/logs');

// Qué se registra:
//   'todo'        todas las consultas
//   'escrituras'  solo INSERT/UPDATE/DELETE y DDL
//   'errores'     solo las consultas que fallan
defined('JSONSQLDB_LOG_NIVEL') || define('JSONSQLDB_LOG_NIVEL', 'todo');

// Longitud máxima de la SQL guardada en el log (se trunca, no se parte).
defined('JSONSQLDB_LOG_MAX_SQL') || define('JSONSQLDB_LOG_MAX_SQL', 2000);

// ¿Se guardan también los valores de los parámetros ligados?
// A false por defecto: en esos valores viajan contraseñas, tokens y datos
// personales, y el log se conserva 90 días. Actívalo solo mientras depuras.
defined('JSONSQLDB_LOG_PARAMS') || define('JSONSQLDB_LOG_PARAMS', false);

// Tamaño máximo de cada fichero de log antes de rotar (bytes).
defined('JSONSQLDB_LOG_MAX_SIZE') || define('JSONSQLDB_LOG_MAX_SIZE', 5 * 1024 * 1024);

// Días que se conservan los ficheros de log.
//
// Se genera un fichero por día (consultas-2026-08-19.json) y, si uno supera
// JSONSQLDB_LOG_MAX_SIZE, se parte en consultas-2026-08-19.1.json, .2.json, etc.
// Lo mismo con el histórico de peticiones de la API (peticiones-2026-08-19.json).
//
//   90  → se borran los ficheros con más de 90 días. Es lo recomendado:
//         el log deja de crecer y siempre tienes los últimos 3 meses.
//    0  → no se borra nunca ningún log. Úsalo solo si necesitas conservarlos
//         de forma permanente por auditoría; vigila el espacio en disco.
//
// El borrado es automático: se comprueba de vez en cuando al escribir en el log,
// así que no hace falta ninguna tarea programada.
defined('JSONSQLDB_LOG_DIAS') || define('JSONSQLDB_LOG_DIAS', 90);

// ------------------------------------------------------------
// ORDEN ALFABÉTICO (solo afecta a ORDER BY)
// ------------------------------------------------------------
//
//   'general'  Ordena como una persona espera: sin distinguir mayúsculas ni
//              acentos, y con las letras propias en su sitio. 'Óscar' queda
//              entre las O, no al final. Es lo recomendado.
//   'binaria'  Ordena byte a byte, como SQLite por defecto: primero todas las
//              mayúsculas, luego las minúsculas y al final lo acentuado.
//
// Solo cambia el ORDER BY. Las comparaciones (=, <, >), las claves únicas, los
// GROUP BY y los DISTINCT siguen siendo exactos: 'Óscar' y 'oscar' son y
// seguirán siendo dos valores distintos.
defined('JSONSQLDB_COLACION') || define('JSONSQLDB_COLACION', 'general');

// Correcciones al orden, para las letras propias de cada idioma.
//
// El alfabeto no es igual en todas partes. El mapa base trata los acentos como
// variantes de la letra base (bien para español, francés, italiano, portugués,
// catalán...) y coloca la 'ñ' justo después de la 'n'. Pero en sueco, danés y
// noruego 'å', 'ä' y 'ö' son letras propias que van DESPUÉS de la 'z'.
//
// Aquí se corrige sin tocar el motor. La clave es la letra y el valor es su
// posición: '{' es el primer carácter posterior a la 'z', así que 'z{' va
// detrás de cualquier palabra que empiece por z, y 'n{' entre la n y la o.
//
//   Sueco:  ...'å' => 'z{', 'ä' => 'z{{', 'ö' => 'z{{{'
//   Alemán: ...'ä' => 'a',  'ö' => 'o',   'ü' => 'u'    (ya es el comportamiento base)
//
// Déjalo vacío si el mapa base te vale.
defined('JSONSQLDB_COLACION_MAPA') || define('JSONSQLDB_COLACION_MAPA', []);

// ------------------------------------------------------------
// ZONA HORARIA
// ------------------------------------------------------------
date_default_timezone_set('Europe/Madrid');
