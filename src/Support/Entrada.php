<?php

declare(strict_types=1);

namespace App\Support;

/** Validaciones de texto e identificadores de formularios (puras, sin BD). */
final class Entrada
{
    /** Texto UTF-8 válido, de $min a $max caracteres, sin caracteres de control (salvo saltos si $multilinea). */
    public static function textoValido(string $s, int $min, int $max, bool $multilinea = false): bool
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return false;
        }
        $n = mb_strlen($s, 'UTF-8');
        if ($n < $min || $n > $max) {
            return false;
        }
        return !preg_match($multilinea ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $s);
    }

    /** Id positivo de hasta 10 dígitos, o null. */
    public static function id(string $s): ?int
    {
        $s = trim($s);
        return preg_match('/^[1-9]\d{0,9}$/D', $s) ? (int) $s : null;
    }

    /** Recorta para devolver al formulario un valor conservado. */
    public static function recortar(string $s, int $max): string
    {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
}
