<?php

declare(strict_types=1);

/**
 * Auxiliar de tests/run.php (solo CLI): espera hasta el instante indicado y llama a
 * AuthService::refrescar con el refresh dado, para que varios procesos compitan de
 * verdad contra MariaDB (el servidor embebido de PHP atiende de una en una).
 * Uso: php tests/aux_refresh_concurrente.php <refresh_plano> <inicio_unix_ms>
 * Imprime el estado resultante (ok | gracia | ...).
 */
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(1);
}
$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
require $raiz . '/src/autoload.php';

App\App::arrancarEntorno();
$config = App\Config::cargar();
$auth = new App\Services\AuthService($config, new App\Security\TokenAcceso($config));
App\Db::pdo(); // conectar antes de la barrera

$inicio = (int) $argv[2];
while ((int) floor(microtime(true) * 1000) < $inicio) {
    usleep(500);
}
$r = $auth->refrescar($argv[1], "\x7f\0\0\1", 'aux-concurrente');
echo $r['estado'];
