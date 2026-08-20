<?php
declare(strict_types=1);

/**
 * Auditoría del panel: quién hizo qué y desde dónde.
 * Un fichero por día, una línea JSON por evento. Nunca interrumpe la acción.
 */
final class Audit
{
    public static function registrar(string $accion, string $detalle = '', ?string $base = null): void
    {
        try {
            Store::anadirLinea(self::fichero(date('Y-m-d')), [
                'ts'      => date('Y-m-d H:i:s'),
                'usuario' => (string)(Auth::usuario()['usuario'] ?? '-'),
                'ip'      => util_ip(),
                'base'    => $base,
                'accion'  => $accion,
                'detalle' => $detalle,
            ]);
            if (mt_rand(1, 100) === 1) {
                self::purgar();
            }
        } catch (Throwable $e) {
            // La auditoría nunca puede tumbar la operación del usuario.
        }
    }

    /** Eventos de un día, del más reciente al más antiguo. */
    public static function dia(string $fecha): array
    {
        return array_reverse(Store::leerLineas(self::fichero($fecha)));
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
