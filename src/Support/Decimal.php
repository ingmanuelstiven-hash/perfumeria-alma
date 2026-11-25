<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decimales como CADENA (nunca float): valida, normaliza y compara cantidades y costos
 * que irán a columnas DECIMAL. La aritmética (promedio ponderado) la hace MariaDB con
 * CAST(... AS DECIMAL), que es exacta; aquí solo se valida forma, rango y signo.
 *
 * Acepta '.' o ',' como separador decimal; NO acepta separador de miles, signo, notación
 * científica ni espacios internos. Un negativo, por tanto, no pasa nunca.
 */
final class Decimal
{
    /**
     * @param int $enteros  dígitos enteros máximos (p. ej. DECIMAL(14,2) => 12)
     * @param int $decimales dígitos decimales máximos
     * @return string|null cadena normalizada con exactamente $decimales decimales, o null si es inválida
     */
    public static function parsear(string $valor, int $enteros, int $decimales): ?string
    {
        $v = str_replace(',', '.', trim($valor));
        if (substr_count($v, '.') > 1 || !preg_match('/^\d{1,' . $enteros . '}(?:\.\d{1,' . $decimales . '})?$/D', $v)) {
            return null;
        }
        [$ent, $dec] = array_pad(explode('.', $v, 2), 2, '');
        $ent = ltrim($ent, '0');
        return ($ent === '' ? '0' : $ent) . ($decimales > 0 ? '.' . str_pad($dec, $decimales, '0') : '');
    }

    /** ¿Es cero? (acepta cadenas ya normalizadas o no). */
    public static function esCero(string $v): bool
    {
        return !preg_match('/[1-9]/', $v);
    }

    /** Compara dos cadenas decimales no negativas: -1, 0 o 1. */
    public static function comparar(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $ai = ltrim($ai, '0');
        $bi = ltrim($bi, '0');
        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) <=> strlen($bi);
        }
        if ($ai !== $bi) {
            return strcmp($ai, $bi) <=> 0; // strcmp: "10" <=> "9" compararía como números
        }
        $n = max(strlen($af), strlen($bf));
        return strcmp(str_pad($af, $n, '0'), str_pad($bf, $n, '0')) <=> 0;
    }

    /** Para mostrar: quita ceros decimales sobrantes ("12.500000" => "12.5", "3.000" => "3"). */
    public static function mostrar(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }
}
