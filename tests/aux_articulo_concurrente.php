<?php

declare(strict_types=1);

/**
 * Auxiliar de tests/casos_1b.php (solo CLI): espera hasta el instante indicado y crea un artículo
 * con ArticuloService, para que varios procesos compitan de verdad contra MariaDB (el servidor
 * embebido de PHP atiende de una petición en una).
 *
 * Uso: php tests/aux_articulo_concurrente.php <inicio_unix_ms> <usuario_id> <sku|auto> <nombre>
 * Imprime: ok:<id>:<sku> | err:<status>:<mensaje> | exc:<clase>:<mensaje>
 */
if (PHP_SAPI !== 'cli' || $argc !== 5) {
    exit(1);
}
$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';
require $raiz . '/src/autoload.php';

App\App::arrancarEntorno();
$pdo = App\Db::pdo(); // conectar antes de la barrera
$st = $pdo->prepare('SELECT id, sucursal_id FROM usuarios WHERE id = ?');
$st->execute([(int) $argv[2]]);
$actor = $st->fetch();
$permisos = ['articulos.gestionar' => true, 'inventario.ajustar' => true, 'inventario.ver_costos' => true];
$in = ['tipo' => 'producto', 'nombre' => $argv[4], 'unidad_medida' => 'und'];
if ($argv[3] === 'auto') {
    $in['sku_auto'] = '1';
} else {
    $in['sku'] = $argv[3];
}

$inicio = (int) $argv[1];
while ((int) floor(microtime(true) * 1000) < $inicio) {
    usleep(500);
}
try {
    $id = (new App\Services\ArticuloService())->crear($actor, $permisos, $in);
    $s = $pdo->prepare('SELECT sku FROM articulos WHERE id = ?');
    $s->execute([$id]);
    echo 'ok:' . $id . ':' . $s->fetchColumn();
} catch (App\Services\ErrorNegocio $e) {
    echo 'err:' . $e->status . ':' . $e->getMessage();
} catch (Throwable $e) {
    echo 'exc:' . get_class($e) . ':' . $e->getMessage();
}
