<?php
declare(strict_types=1);

/**
 * Auditoría del panel: quién hizo qué y desde dónde.
 * Un fichero por día, una línea JSON por evento. Nunca interrumpe la acción.
 *
 * https://miguelenred.es/jsonsqldb
 */
final class Audit
{
    public static function registrar(string $accion, string $detalle = '', ?string $base = null): void
    {
        try {
            // El primer evento de cada día quita los de los días que ya no se
            // conservan (2.10: uno de cada cien al azar, y en un día flojo los
            // viejos podían seguir semanas)
            if (!is_file(Store::ruta(self::fichero(date('Y-m-d'))))) {
                self::purgar();
            }
            Store::anadirLinea(self::fichero(date('Y-m-d')), [
                'ts'      => date('Y-m-d H:i:s'),
                'usuario' => (string)(Auth::usuario()['usuario'] ?? '-'),
                'ip'      => util_ip(),
                'base'    => $base,
                'accion'  => $accion,
                'detalle' => $detalle,
            ]);
        } catch (Throwable) {
            // La auditoría nunca puede tumbar la operación del usuario.
        }
    }

    /**
     * Los eventos de un día que contienen $busca (en minúsculas), del más
     * reciente al más antiguo, $n a partir del $desde, y si hay más antiguos
     * (ver Store::ultimasLineas()).
     *
     * @return array{0: list<array>, 1: bool}
     */
    public static function dia(string $fecha, int $n, string $busca = '', int $desde = 0): array
    {
        return Store::ultimasLineas(self::fichero($fecha), $n, $busca === '' ? null
            : static fn(array $e): bool => str_contains(minus(implode(' ', array_map('strval', $e))), $busca), $desde);
    }

    /** Días con auditoría, de más reciente a más antiguo. */
    public static function dias(): array
    {
        $dias = [];
        foreach ((array)glob(Store::ruta('auditoria-*.json')) as $f) {
            if (preg_match('/auditoria-(\d{4}-\d{2}-\d{2})\.json$/', (string)$f, $m)) {
                $dias[] = $m[1];
            }
        }
        rsort($dias);
        return $dias;
    }

    private static function fichero(string $fecha): string
    {
        return 'auditoria-' . $fecha . '.json';
    }

    private static function purgar(): void
    {
        $dias = (int)ADMIN_AUDIT_DIAS;
        if ($dias <= 0) {
            return;
        }
        $corte = date('Y-m-d', time() - $dias * 86400);
        foreach (self::dias() as $d) {
            if ($d < $corte) {
                @unlink(Store::ruta(self::fichero($d)));
            }
        }
    }
}
