<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 mínimo (sin Composer): App\Foo\Bar -> src/Foo/Bar.php
 * Si en el futuro se adopta Composer, este archivo se sustituye por
 * vendor/autoload.php con "autoload": {"psr-4": {"App\\": "src/"}}.
 */
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'App\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $relativa = substr($clase, strlen($prefijo));
    // Solo identificadores válidos: evita rutas con "..", "/" u otros caracteres.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relativa)) {
        return;
    }
    $archivo = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativa) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
