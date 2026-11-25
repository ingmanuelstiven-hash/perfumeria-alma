<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Piezas de inventario del catálogo (Sprint 1B): filas de `existencias` y SALDO INICIAL.
 *
 * El saldo NUNCA se edita desde el CRUD. La única vía en 1B es un movimiento 'saldo_inicial'
 * en el libro (movimientos_inventario o movimientos_esencia, de solo inserción) y, en la
 * misma transacción, la actualización de la caché `existencias` (cantidad y costo promedio
 * ponderado). Todos los métodos exigen estar DENTRO de una transacción.
 *
 * Orden de bloqueos (docs/BASE_DE_DATOS.md §6): consecutivos -> existencias -> recipientes.
 * La aritmética decimal la hace MariaDB con CAST(? AS DECIMAL(..)): el valor llega como
 * cadena y nunca pasa por float (con preparadas reales un parámetro de texto se evalúa
 * como DOUBLE en una operación aritmética, de ahí los CAST).
 */
final class InventarioService
{
    /** Crea (saldo 0) la fila de existencias del artículo en cada sucursal activa. */
    public static function crearFilasExistencias(\PDO $pdo, int $articuloId): int
    {
        $st = $pdo->prepare(
            'INSERT INTO existencias (sucursal_id, articulo_id)
             SELECT s.id, ? FROM sucursales s WHERE s.activa = 1'
        );
        $st->execute([$articuloId]);
        $n = $st->rowCount();
        if ($n < 1) {
            throw new ErrorNegocio('No hay sucursales activas: no se puede crear el inventario del artículo.', 422);
        }
        return $n;
    }

    /**
     * Garantiza y BLOQUEA la fila de existencias (sucursal, artículo). Si faltaba (sucursal
     * creada después del artículo) la crea en 0 sin pisar nada.
     * @return array{cantidad:string, cantidad_reservada:string, costo_promedio:string}
     */
    public static function bloquearExistencia(\PDO $pdo, int $sucursalId, int $articuloId): array
    {
        $pdo->prepare(
            'INSERT INTO existencias (sucursal_id, articulo_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE cantidad = cantidad'
        )->execute([$sucursalId, $articuloId]);
        $st = $pdo->prepare(
            'SELECT cantidad, cantidad_reservada, costo_promedio FROM existencias
              WHERE sucursal_id = ? AND articulo_id = ? FOR UPDATE'
        );
        $st->execute([$sucursalId, $articuloId]);
        return $st->fetch();
    }

    /**
     * Saldo inicial de un producto, envase o insumo (unidades o ml) en una sucursal.
     * @param string $cantidad DECIMAL(12,3) > 0 ya validada
     * @param string $costo    DECIMAL(14,6) >= 0 ya validado (costo por unidad)
     */
    public static function saldoInicialArticulo(
        \PDO $pdo,
        array $actor,
        int $articuloId,
        int $sucursalId,
        string $cantidad,
        string $costo,
    ): void {
        self::bloquearExistencia($pdo, $sucursalId, $articuloId);
        self::sumarEntrada($pdo, $sucursalId, $articuloId, $cantidad, $costo);
        $st = $pdo->prepare('SELECT cantidad, costo_promedio FROM existencias WHERE sucursal_id = ? AND articulo_id = ?');
        $st->execute([$sucursalId, $articuloId]);
        $ex = $st->fetch();
        $pdo->prepare(
            "INSERT INTO movimientos_inventario
               (sucursal_id, articulo_id, tipo, cantidad, saldo_despues, costo_unitario, costo_promedio_despues,
                referencia_tipo, motivo, usuario_id)
             VALUES (?, ?, 'saldo_inicial', ?, ?, ?, ?, 'manual', 'Saldo inicial', ?)"
        )->execute([$sucursalId, $articuloId, $cantidad, $ex['cantidad'], $costo, $ex['costo_promedio'], (int) $actor['id']]);
    }

    /**
     * Saldo inicial (gramos) de un recipiente recién creado, ya insertado con 0 g. La
     * existencia de la esencia (articulo_id) debe estar bloqueada por el llamador.
     */
    public static function saldoInicialRecipiente(
        \PDO $pdo,
        array $actor,
        int $recipienteId,
        int $esenciaId,
        int $articuloId,
        int $sucursalId,
        string $gramos,
        string $costoG,
    ): void {
        self::sumarEntrada($pdo, $sucursalId, $articuloId, $gramos, $costoG);
        $pdo->prepare("UPDATE recipientes_esencia SET gramos_actuales = ?, estado = 'activo' WHERE id = ?")
            ->execute([$gramos, $recipienteId]);
        $pdo->prepare(
            "INSERT INTO movimientos_esencia
               (sucursal_id, esencia_id, recipiente_id, tipo, gramos, saldo_recipiente, costo_g,
                referencia_tipo, motivo, usuario_id)
             VALUES (?, ?, ?, 'saldo_inicial', ?, ?, ?, 'manual', 'Saldo inicial', ?)"
        )->execute([$sucursalId, $esenciaId, $recipienteId, $gramos, $gramos, $costoG, (int) $actor['id']]);
    }

    /**
     * Suma una entrada a la caché: cantidad + costo promedio ponderado (6 decimales).
     * En un UPDATE de MariaDB las asignaciones se evalúan de izquierda a derecha con los
     * valores ya asignados: el costo se calcula ANTES de tocar `cantidad`.
     */
    private static function sumarEntrada(\PDO $pdo, int $sucursalId, int $articuloId, string $cantidad, string $costo): void
    {
        $pdo->prepare(
            'UPDATE existencias
                SET costo_promedio = ROUND((cantidad * costo_promedio + CAST(? AS DECIMAL(12,3)) * CAST(? AS DECIMAL(14,6)))
                                           / (cantidad + CAST(? AS DECIMAL(12,3))), 6),
                    ultimo_costo = CAST(? AS DECIMAL(14,6)),
                    cantidad = cantidad + CAST(? AS DECIMAL(12,3))
              WHERE sucursal_id = ? AND articulo_id = ?'
        )->execute([$cantidad, $costo, $cantidad, $costo, $cantidad, $sucursalId, $articuloId]);
    }
}
