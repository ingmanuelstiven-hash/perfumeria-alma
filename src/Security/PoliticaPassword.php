<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Política de contraseñas compartida por scripts/crear_admin.php y la app web
 * (alta de usuarios por el admin y cambio de contraseña propia).
 *
 * Mínimo 12 caracteres, máx. 1024 bytes (72 con bcrypt), sin caracteres de control,
 * distinta del usuario/email/nombre, sin contener el usuario, fuera de la lista de
 * comunes y con al menos 4 caracteres distintos.
 */
final class PoliticaPassword
{
    /** Lista corta de contraseñas comunes de 12+ caracteres (las de menos ya fallan por longitud). */
    public const COMUNES = [
        '123456789012', '1234567890123', '12345678901234', '123456789123', '111111111111',
        '000000000000', 'qwertyuiopas', 'qwerty123456', '123456qwerty', 'password1234',
        'password123!', 'passw0rd1234', 'contraseña123', 'contrasena123', 'contraseña1234',
        'administrador', 'administrator', 'admin1234567', 'admin12345678', 'perfumeria123',
        'perfumeria2024', 'perfumeria2025', 'perfumeria2026', 'iloveyou1234', '1q2w3e4r5t6y',
        'abcdefghijkl', 'abc123456789', 'colombia1234', 'colombia2024', 'colombia2025',
        'bienvenido123', 'bienvenido1234', 'qazwsxedcrfv', 'zaq12wsxcde3',
    ];

    /** @return list<string> errores (vacío = válida) */
    public static function validar(
        #[\SensitiveParameter] string $pass,
        string $usuario,
        string $email,
        string $nombre,
        bool $esBcrypt = false,
    ): array {
        $errores = [];
        if (!mb_check_encoding($pass, 'UTF-8')) {
            return ['La contraseña contiene caracteres no válidos (UTF-8).'];
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pass)) {
            $errores[] = 'La contraseña no puede contener caracteres de control.';
        }
        if (mb_strlen($pass, 'UTF-8') < 12) {
            $errores[] = 'La contraseña debe tener al menos 12 caracteres.';
        }
        if (strlen($pass) > Passwords::MAX_BYTES) {
            $errores[] = 'La contraseña es demasiado larga (máx. 1024 bytes).';
        }
        if ($esBcrypt && strlen($pass) > 72) {
            $errores[] = 'Con bcrypt la contraseña no puede superar 72 bytes (se truncaría).';
        }
        $p = self::min($pass);
        $localEmail = self::min((string) strstr($email, '@', true));
        foreach ([self::min($usuario), self::min($email), $localEmail, self::min($nombre)] as $prohibido) {
            if ($prohibido !== '' && $p === $prohibido) {
                $errores[] = 'La contraseña no puede ser igual al usuario, email o nombre.';
                break;
            }
        }
        if (self::min($usuario) !== '' && mb_strlen($usuario) >= 4 && str_contains($p, self::min($usuario))) {
            $errores[] = 'La contraseña no puede contener el nombre de usuario.';
        }
        if (in_array($p, self::COMUNES, true)) {
            $errores[] = 'La contraseña está en la lista de contraseñas comunes.';
        }
        if (count(array_unique(mb_str_split($pass, 1, 'UTF-8'))) < 4) {
            $errores[] = 'La contraseña tiene muy poca variedad de caracteres.';
        }
        return $errores;
    }

    private static function min(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }
}
