<?php

declare(strict_types=1);

namespace App;

/**
 * Registro de errores en logs/app-AAAAMMDD.log (fuera de public/).
 * Nunca se registran contraseñas, tokens ni cookies: los llamadores pasan solo
 * mensajes y contexto escalar ya saneado.
 */
final class Log
{
    public static ?string $requestId = null;

    public static function error(string $mensaje, array $contexto = []): void
    {
        self::escribir('ERROR', $mensaje, $contexto);
    }

    public static function aviso(string $mensaje, array $contexto = []): void
    {
        self::escribir('AVISO', $mensaje, $contexto);
    }

    private static function escribir(string $nivel, string $mensaje, array $contexto): void
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Una línea por evento: se quitan saltos de línea (evita inyección en el log).
        $linea = sprintf(
            "[%s] %s req=%s %s %s\n",
            date('Y-m-d H:i:s'),
            $nivel,
            self::$requestId ?? '-',
            str_replace(["\r", "\n"], ' ', $mensaje),
            $contexto ? str_replace(["\r", "\n"], ' ', (string) json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) : ''
        );
        $archivo = $dir . DIRECTORY_SEPARATOR . 'app-' . date('Ymd') . '.log';
        if (@file_put_contents($archivo, $linea, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($linea));
        }
    }
}
