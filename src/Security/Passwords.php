<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Hash de contraseñas: Argon2id (parámetros por defecto de PHP: 64 MiB, t=4, p=1);
 * bcrypt cost 12 si PHP no trae Argon2. Mismo criterio que scripts/crear_admin.php.
 */
final class Passwords
{
    /**
     * Hash ficticio (Argon2id con los parámetros por defecto, de una contraseña
     * aleatoria desconocida). Se verifica contra él cuando el usuario no existe,
     * para que el tiempo de respuesta sea el mismo que con una clave incorrecta.
     */
    private const FICTICIO_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$bGp1Y1VJVUZlSW1iY1VnNg$xdghIIBeCmXzO4+c96jfhva3rahnurX67yBxacsHxDE';
    private const FICTICIO_BCRYPT = '$2y$12$nlBoqNChDxZNSIfYOyPNn.17SXedpZp4Eml7FCkH1E.rm9lfyagPm';

    /** Tope antes de hashear: evita DoS con contraseñas de megabytes. */
    public const MAX_BYTES = 1024;

    public static function esBcrypt(): bool
    {
        return !defined('PASSWORD_ARGON2ID');
    }

    public static function hash(#[\SensitiveParameter] string $pass): string
    {
        return self::esBcrypt()
            ? password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])
            : password_hash($pass, PASSWORD_ARGON2ID);
    }

    public static function verificar(#[\SensitiveParameter] string $pass, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            $hash = self::esBcrypt() ? self::FICTICIO_BCRYPT : self::FICTICIO_ARGON2ID;
            password_verify($pass, $hash);
            return false;
        }
        return password_verify($pass, $hash);
    }

    /** Verificación contra el hash ficticio (usuario inexistente o caso que no debe distinguirse). */
    public static function verificarFicticio(#[\SensitiveParameter] string $pass): void
    {
        self::verificar($pass, null);
    }

    public static function necesitaRehash(string $hash): bool
    {
        return self::esBcrypt()
            ? password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])
            : password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
