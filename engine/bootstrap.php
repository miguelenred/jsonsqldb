<?php
declare(strict_types=1);

/**
 * Carga del motor jsonSQLDB. Un único include:
 *   require_once __DIR__ . '/engine/bootstrap.php';
 */
spl_autoload_register(static function (string $clase): void {
    if (strncmp($clase, 'JsonSQLDB\\', 10) !== 0) {
        return;
    }
    $fichero = __DIR__ . '/' . str_replace('\\', '/', substr($clase, 10)) . '.php';
    if (is_file($fichero)) {
        require $fichero;
    }
});
