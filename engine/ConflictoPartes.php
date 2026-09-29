<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Una escritura por partes (ver Storage::bloquearPartes()) se ha encontrado,
 * al ir a confirmarla, con que otra escritura ha cambiado alguna de las
 * partes que ella iba a reescribir. No es un error: la escritura se descarta
 * sin haber tocado nada y se repite con el bloqueo de toda la tabla, que es
 * justo «ponerse a la cola». Nunca llega al usuario.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class ConflictoPartes extends \RuntimeException
{
}
