<?php

declare(strict_types=1);

namespace App\Security;

use App\Db;

/**
 * Rate limit y bloqueo en MySQL (tabla limites_tasa), sin Redis.
 *
 * clave = SHA-256("<ámbito>|<valor>"): p. ej. "login_u|maria", "login_ip|127.0.0.1",
 * "refresh_ip|127.0.0.1". Ventana fija; al alcanzar el máximo se fija bloqueado_hasta.
 *
 * Concurrencia: el incremento es UNA sentencia INSERT ... ON DUPLICATE KEY UPDATE
 * (atómica por fila con el bloqueo de InnoDB) y devuelve el contador resultante.
 * Por eso el login RESERVA el intento ANTES de verificar la contraseña: 50 peticiones
 * en paralelo no pueden evaluar más de N contraseñas (las que superan N se cortan).
 * Nota: en ON DUPLICATE KEY UPDATE, MariaDB evalúa las asignaciones de izquierda a
 * derecha y cada una ve los valores ya asignados; el orden de las columnas importa.
 */
final class LimiteTasa
{
    public static function clave(string $ambito, string $valor): string
    {
        return hash('sha256', $ambito . '|' . $valor, true);
    }

    /** Segundos de bloqueo restantes, o 0 si no está bloqueada. */
    public static function bloqueo(string $clave): int
    {
        $st = Db::pdo()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) FROM limites_tasa
              WHERE clave = ? AND bloqueado_hasta > NOW()'
        );
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : max(1, (int) $v);
    }

    /**
     * Suma 1 en la ventana. Si el contador llega a $max, bloquea $bloqueoSeg.
     * @return int contador tras el incremento
     */
    public static function incrementar(string $clave, int $max, int $ventanaSeg, int $bloqueoSeg): int
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare(
            'INSERT INTO limites_tasa (clave, ventana_inicio, contador, bloqueado_hasta)
             VALUES (?, NOW(), 1, IF(1 >= ?, NOW() + INTERVAL ? SECOND, NULL))
             ON DUPLICATE KEY UPDATE
               contador        = IF(ventana_inicio <= NOW() - INTERVAL ? SECOND, 1, contador + 1),
               ventana_inicio  = IF(ventana_inicio <= NOW() - INTERVAL ? SECOND, NOW(), ventana_inicio),
               bloqueado_hasta = IF(contador >= ?, GREATEST(IFNULL(bloqueado_hasta, NOW()), NOW() + INTERVAL ? SECOND),
                                    IF(bloqueado_hasta <= NOW(), NULL, bloqueado_hasta))'
        );
        $st->execute([$clave, $max, $bloqueoSeg, $ventanaSeg, $ventanaSeg, $max, $bloqueoSeg]);
        $st = $pdo->prepare('SELECT contador FROM limites_tasa WHERE clave = ?');
        $st->execute([$clave]);
        return (int) $st->fetchColumn();
    }

    /** Resta 1 (intento que resultó legítimo) y levanta el bloqueo si queda por debajo del máximo. */
    public static function devolver(string $clave, int $max): void
    {
        $st = Db::pdo()->prepare(
            'UPDATE limites_tasa
                SET contador = IF(contador > 0, contador - 1, 0),
                    bloqueado_hasta = IF(contador < ?, NULL, bloqueado_hasta)
              WHERE clave = ?'
        );
        $st->execute([$max, $clave]);
    }

    public static function limpiar(string $clave): void
    {
        Db::pdo()->prepare('DELETE FROM limites_tasa WHERE clave = ?')->execute([$clave]);
    }
}
