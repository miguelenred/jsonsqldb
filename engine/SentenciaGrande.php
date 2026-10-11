<?php
declare(strict_types=1);

namespace JsonSQLDB;

/**
 * Una escritura cambia más filas de las que caben en memoria de una vez y no
 * va dentro de una transacción (ver Writer::tramo()). No es un error: aún no
 * ha escrito nada, y Database la repite en una transacción, donde puede
 * escribir por tramos sin dejar de ser todo o nada. Nunca llega al usuario.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class SentenciaGrande extends \RuntimeException
{
}
