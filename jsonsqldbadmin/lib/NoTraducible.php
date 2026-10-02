<?php
declare(strict_types=1);

/**
 * Lo que no tiene ninguna forma de escribirse en otro motor (al exportar) o
 * aquí (al importar): una vista o un trigger que se salta, con el motivo.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class NoTraducible extends RuntimeException
{
}
