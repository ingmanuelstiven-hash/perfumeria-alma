<?php

declare(strict_types=1);

/**
 * Front controller. Todo pasa por aquí (Apache: public/.htaccess; servidor
 * embebido: php -S 127.0.0.1:8099 -t public public/index.php).
 */

// Servidor embebido de PHP: servir directamente los archivos estáticos que existan
// en public/ (CSS/JS/imagenes). Nunca .php distintos de este.
if (PHP_SAPI === 'cli-server') {
    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $archivo = realpath(__DIR__ . $ruta);
    if ($archivo !== false && is_file($archivo) && str_starts_with($archivo, __DIR__ . DIRECTORY_SEPARATOR)
        && preg_match('/\.(css|js|png|jpg|jpeg|webp|svg|ico|woff2?)$/i', $archivo)) {
        return false;
    }
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
require $raiz . '/src/autoload.php';

App\App::ejecutar();
