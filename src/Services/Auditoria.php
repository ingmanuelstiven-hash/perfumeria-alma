<?php

declare(strict_types=1);

namespace App\Services;

use App\Db;

/**
 * Registro en `auditoria` (tabla de solo inserción). NUNCA se guardan contraseñas,
 * hashes, tokens ni cookies: los llamadores solo pasan datos de negocio.
 * El contexto de la petición (IP, user agent, request_id) lo fija el kernel.
 */
final class Auditoria
{
    public static ?string $ip = null;          // binaria (VARBINARY(16))
    public static ?string $userAgent = null;
    public static ?string $requestId = null;   // 16 bytes

    /**
     * @param array<string,mixed>|null $antes
     * @param array<string,mixed>|null $despues
     */
    public static function registrar(
        string $accion,
        ?int $usuarioId,
        ?int $sucursalId = null,
        ?string $entidad = null,
        ?int $entidadId = null,
        ?array $antes = null,
        ?array $despues = null,
    ): void {
        $st = Db::pdo()->prepare(
            'INSERT INTO auditoria (sucursal_id, usuario_id, accion, entidad, entidad_id, antes, despues, ip, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $sucursalId,
            $usuarioId,
            $accion,
            $entidad,
            $entidadId,
            $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            self::$ip,
            self::$userAgent,
            self::$requestId,
        ]);
    }
}
