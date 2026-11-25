<?php

declare(strict_types=1);

namespace App\Services;

/**
 * SKU de los artículos: formato y autogeneración.
 *
 * Formato: mayúsculas, dígitos y guion; 3 a 40 caracteres (VARCHAR(40) del esquema);
 * empieza y termina con letra o dígito. El UNIQUE de BD (uq_articulos_sku, ascii_general_ci,
 * es decir sin distinguir mayúsculas) es la única garantía de unicidad; la app solo
 * traduce el error 1062.
 *
 * Autogenerado: <PREFIJO_TIPO>-<n con ceros> (ESE-000001, PRD-000002...) con UNA secuencia
 * compartida: la fila `consecutivos` tipo 'SKU' (docs/BASE_DE_DATOS.md §7; el ENUM de
 * `consecutivos.tipo` no admite una secuencia por tipo de artículo sin migración). La fila
 * se bloquea con SELECT ... FOR UPDATE dentro de la transacción del alta: dos altas
 * simultáneas se serializan y, si la transacción falla, el número no se consume (sin saltos).
 */
final class SkuService
{
    public const FORMATO = '/^[A-Z0-9][A-Z0-9-]{1,38}[A-Z0-9]$/D';

    private const PREFIJOS = ['esencia' => 'ESE', 'producto' => 'PRD', 'envase' => 'ENV', 'insumo' => 'INS'];

    /** Mayúsculas y sin espacios en los extremos (solo ASCII: lo demás no pasará el formato). */
    public static function normalizar(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    public static function formatoValido(string $sku): bool
    {
        return (bool) preg_match(self::FORMATO, $sku);
    }

    /**
     * Siguiente SKU libre. Debe llamarse DENTRO de una transacción: bloquea la fila del consecutivo
     * hasta el commit. Omite los números cuyo SKU ya existe (alguien lo tecleó a mano).
     */
    public static function siguiente(\PDO $pdo, string $tipo): string
    {
        $prefijo = self::PREFIJOS[$tipo] ?? throw new \InvalidArgumentException('Tipo de artículo inválido');
        // Una sola secuencia global (los SKU son únicos en todo el sistema): la de la sucursal de menor id.
        $fila = $pdo->query(
            "SELECT id, prefijo, siguiente, relleno FROM consecutivos WHERE tipo = 'SKU' ORDER BY sucursal_id LIMIT 1 FOR UPDATE"
        )->fetch();
        if (!$fila) {
            throw new \RuntimeException("No hay consecutivo 'SKU' configurado (¿se aplicaron los seeds?)");
        }
        $existe = $pdo->prepare('SELECT 1 FROM articulos WHERE sku = ? LIMIT 1');
        $n = (int) $fila['siguiente'];
        for ($i = 0; $i < 1000; $i++, $n++) {
            $sku = $prefijo . '-' . $fila['prefijo'] . str_pad((string) $n, (int) $fila['relleno'], '0', STR_PAD_LEFT);
            $existe->execute([$sku]);
            if ($existe->fetchColumn() === false) {
                $pdo->prepare('UPDATE consecutivos SET siguiente = ? WHERE id = ?')->execute([$n + 1, (int) $fila['id']]);
                return $sku;
            }
        }
        throw new \RuntimeException('No se encontró un SKU libre tras 1000 intentos');
    }
}
