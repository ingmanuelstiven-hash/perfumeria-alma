<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;

/**
 * Permisos por rol leídos de rol_permisos en CADA petición (con caché solo dentro
 * de la petición). No se confía en ningún claim del JWT para autorizar: si el admin
 * quita un permiso a un rol, el cambio rige en la siguiente petición.
 *
 * Si el usuario tiene debe_cambiar_password=1, sus permisos efectivos son vacíos:
 * solo puede cambiar su contraseña (docs/SEGURIDAD_AUTH.md §2.1 punto 6).
 */
final class Permisos
{
    /** @var array<int, array<string,true>> */
    private static array $cache = [];

    /** @return array<string,true> */
    public static function deRol(int $rolId): array
    {
        if (!isset(self::$cache[$rolId])) {
            $st = Db::pdo()->prepare(
                'SELECT p.codigo FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id
                  WHERE rp.rol_id = ? ORDER BY p.codigo'
            );
            $st->execute([$rolId]);
            self::$cache[$rolId] = array_fill_keys($st->fetchAll(\PDO::FETCH_COLUMN), true);
        }
        return self::$cache[$rolId];
    }

    /**
     * @param array{rol_id:int|string, debe_cambiar_password:int|string} $usuario
     * @return array<string,true>
     */
    public static function efectivos(array $usuario): array
    {
        if ((int) $usuario['debe_cambiar_password'] === 1) {
            return [];
        }
        return self::deRol((int) $usuario['rol_id']);
    }

    public static function limpiarCache(): void
    {
        self::$cache = [];
    }
}
