<?php

declare(strict_types=1);

/**
 * Casos del Sprint 1B (catálogo interno de inventario). Lo incluye tests/run.php tras casos.php,
 * con la misma BD temporal, el mismo servidor php -S y las mismas funciones (Nav, caso, afirmar, db...).
 * Usa usuarios propios (adm1b, emp1b, con1b) para no depender del estado que dejaron las pruebas de 1A.
 */

use App\Services\ArticuloService;
use App\Support\Decimal;

echo "\n== Sprint 1B: catálogo de inventario ==\n";

// ------------------------------------------------------------------ preparación
$P1B = [
    'adm1b' => 'Adm1b-Prueba-' . bin2hex(random_bytes(4)),
    'emp1b' => 'Emp1b-Prueba-' . bin2hex(random_bytes(4)),
    'con1b' => 'Con1b-Prueba-' . bin2hex(random_bytes(4)),
];
$insU = db()->prepare(
    'INSERT INTO usuarios (sucursal_id, rol_id, usuario, email, nombre, password_hash, activo, debe_cambiar_password)
     VALUES (1, (SELECT id FROM roles WHERE codigo = ?), ?, NULL, ?, ?, 1, 0)'
);
foreach (['adm1b' => 'admin', 'emp1b' => 'empleado', 'con1b' => 'contador'] as $u => $rol) {
    $insU->execute([$rol, $u, "Usuario $u", password_hash($P1B[$u], PASSWORD_ARGON2ID)]);
}
// Una segunda sucursal activa (debe recibir existencias) y una inactiva (no).
db()->exec("INSERT INTO sucursales (id, codigo, nombre, activa) VALUES (2, 'SEC', 'Sede secundaria', 1), (3, 'CERRADA', 'Sede cerrada', 0)");
limpiarLimites();
$A = navLogueado('adm1b', $P1B['adm1b']);
$E = navLogueado('emp1b', $P1B['emp1b']);
$C = navLogueado('con1b', $P1B['con1b']);
$S = [];   // estado compartido entre casos (ids creados)

function f1bArt(int $id): array
{
    $st = db()->prepare('SELECT * FROM articulos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

function f1bCuenta(string $tabla): int
{
    return (int) db()->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
}

/** @return array<string,mixed> respuesta de una alta JSON que debe salir bien */
function f1bCrear(Nav $n, array $datos, int $esperado = 201): array
{
    $r = $n->post('/inventario/articulos', $datos);
    afirmar($r['status'] === $esperado, "POST /inventario/articulos => {$r['status']}: {$r['body']}");
    return $r;
}

/** Datos mínimos válidos por tipo. */
function f1bDatos(string $tipo, array $extra = []): array
{
    $base = ['tipo' => $tipo, 'sku_auto' => '1', 'nombre' => "Prueba $tipo " . bin2hex(random_bytes(3))];
    $por = [
        'esencia' => ['genero' => 'femenino', 'inspiracion' => 'Inspirada en algo', 'densidad_g_ml' => '0.9500', 'familia_olfativa' => 'Floral'],
        'producto' => ['unidad_medida' => 'und', 'contenido_ml' => '100'],
        'envase' => ['capacidad_ml' => '50', 'material' => 'vidrio', 'tipo_cierre' => 'atomizador', 'color' => 'Transparente'],
        'insumo' => ['tipo_insumo' => 'alcohol', 'unidad_medida' => 'ml'],
    ];
    return array_merge($base, $por[$tipo], $extra);
}

function f1bPost(Nav $n, string $ruta, array $body): array
{
    return $n->pedir('POST', $ruta, $body + ['_csrf' => $n->csrf()], [], false, true);
}

/** Lanza N procesos hijos a la vez y devuelve su salida. @param list<list<string>> $argumentos */
function f1bConcurrentes(array $argumentos): array
{
    global $RAIZ;
    $procs = [];
    foreach ($argumentos as $i => $args) {
        $p = proc_open(array_merge([PHP, 'tests/aux_articulo_concurrente.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t, $RAIZ, entornoHijo());
        if (!is_resource($p)) {
            throw new RuntimeException('No se pudo lanzar el proceso concurrente');
        }
        $procs[$i] = [$p, $t];
    }
    $salidas = [];
    foreach ($procs as $i => [$p, $t]) {
        $salidas[$i] = trim((string) stream_get_contents($t[1])) . trim((string) stream_get_contents($t[2]));
        fclose($t[1]);
        fclose($t[2]);
        proc_close($p);
    }
    return $salidas;
}

// =============================================================== marcas y categorías
caso('Marcas y categorías: alta, unicidad (sin importar mayúsculas), renombrar, desactivar y escape', function () use ($A, $E, &$S) {
    $r = $A->post('/admin/marcas', ['nombre' => 'Marca <b>Uno</b>']);
    afirmar($r['status'] === 201 && ($r['json']['ok'] ?? false), 'alta marca: ' . $r['body']);
    $S['marca'] = (int) $r['json']['id'];
    $r = $A->post('/admin/marcas', ['nombre' => 'marca <B>uno</B>']);
    afirmar($r['status'] === 409 && isset($r['json']['errores']['nombre']), 'duplicada: ' . $r['status'] . $r['body']);
    $r = $A->post('/admin/marcas', ['nombre' => 'Marca Dos']);
    afirmar($r['status'] === 201, 'segunda marca');
    $S['marca2'] = (int) $r['json']['id'];
    $r = $A->post('/admin/marcas', ['nombre' => 'x']);
    afirmar($r['status'] === 422 && isset($r['json']['errores']['nombre']), 'nombre corto: ' . $r['status']);
    $r = $A->post("/admin/marcas/{$S['marca2']}", ['nombre' => 'Marca <B>Uno</B>']);
    afirmar($r['status'] === 409, 'renombrar a una existente: ' . $r['status']);
    $r = $A->post("/admin/marcas/{$S['marca2']}", ['nombre' => 'Marca Dos Renombrada']);
    afirmar($r['status'] === 200, 'renombrar: ' . $r['status']);
    $html = $A->pedir('GET', '/admin/marcas', null, [], false);
    afirmar($html['status'] === 200 && str_contains($html['body'], 'Marca &lt;b&gt;Uno&lt;/b&gt;') && !str_contains($html['body'], '<b>Uno'), 'nombre sin escapar');
    $r = $A->post("/admin/marcas/{$S['marca2']}/activo", ['activo' => '0']);
    afirmar($r['status'] === 200, 'desactivar marca');
    afirmar((int) db()->query("SELECT activa FROM marcas WHERE id = {$S['marca2']}")->fetchColumn() === 0, 'marca sigue activa');
    afirmar($A->post("/admin/marcas/{$S['marca2']}/activo", ['activo' => 'x'])['status'] === 422, 'valor activo inválido');
    afirmar($A->post('/admin/marcas/99999/activo', ['activo' => '0'])['status'] === 404, 'marca inexistente');
    // Categorías
    $r = $A->post('/admin/categorias', ['nombre' => 'Árabes', 'tipo' => 'general']);
    afirmar($r['status'] === 201, 'alta categoría: ' . $r['body']);
    $S['cat'] = (int) $r['json']['id'];
    afirmar(db()->query("SELECT slug FROM categorias WHERE id = {$S['cat']}")->fetchColumn() === 'arabes', 'slug');
    afirmar($A->post('/admin/categorias', ['nombre' => 'Arabes', 'tipo' => 'general'])['status'] === 409, 'categoría equivalente sin tilde');
    $r = $A->post('/admin/categorias', ['nombre' => 'Solo esencias', 'tipo' => 'esencia', 'padre_id' => (string) $S['cat']]);
    afirmar($r['status'] === 201, 'categoría hija: ' . $r['body']);
    $S['cat_esencia'] = (int) $r['json']['id'];
    afirmar($A->post("/admin/categorias/{$S['cat']}", ['nombre' => 'Árabes', 'tipo' => 'general', 'padre_id' => (string) $S['cat_esencia']])['status'] === 422, 'ciclo padre-hijo');
    afirmar($A->post("/admin/categorias/{$S['cat']}", ['nombre' => 'Árabes', 'tipo' => 'general', 'padre_id' => (string) $S['cat']])['status'] === 422, 'su propio padre');
    afirmar($A->post('/admin/categorias', ['nombre' => 'Mala', 'tipo' => 'otro'])['status'] === 422, 'tipo inválido');
    afirmar($A->post('/admin/categorias', ['nombre' => '!!!', 'tipo' => 'general'])['status'] === 422, 'nombre sin letras');
    afirmar($A->post('/admin/categorias', ['nombre' => 'Huérfana', 'padre_id' => '88888'])['status'] === 422, 'padre inexistente');
    $r = $A->pedir('GET', '/admin/categorias');
    afirmar($r['status'] === 200 && count($r['json']['categorias']) === 2, 'listado JSON');
    // El empleado lee pero no escribe.
    afirmar($E->pedir('GET', '/admin/marcas')['status'] === 200, 'empleado lista marcas');
    afirmar($E->post('/admin/marcas', ['nombre' => 'Intrusa'])['status'] === 403, 'empleado crea marca');
    afirmar($E->post('/admin/categorias', ['nombre' => 'Intrusa', 'tipo' => 'general'])['status'] === 403, 'empleado crea categoría');
});

// ======================================================================= alta de ítems
caso('Alta de esencia, producto, envase e insumo: subtipo y existencias en 0 por sucursal activa', function () use ($A, $P1B, &$S) {
    $adm = idUsuario('adm1b');
    $prefijos = ['esencia' => 'ESE-', 'producto' => 'PRD-', 'envase' => 'ENV-', 'insumo' => 'INS-'];
    $sub = ['esencia' => 'esencias', 'producto' => 'productos', 'envase' => 'envases_vacios', 'insumo' => 'insumos'];
    foreach ($prefijos as $tipo => $pref) {
        $extra = ['categoria_id' => (string) $S['cat']];
        if ($tipo === 'esencia' || $tipo === 'producto') {
            $extra['marca_id'] = (string) $S['marca'];
        }
        $r = f1bCrear($A, f1bDatos($tipo, $extra));
        $id = (int) $r['json']['id'];
        $S[$tipo] = $id;
        $a = f1bArt($id);
        afirmar($a['tipo'] === $tipo && str_starts_with($a['sku'], $pref) && (int) $a['activo'] === 1 && (int) $a['creado_por'] === $adm, "$tipo: fila articulos " . json_encode($a));
        $n = (int) db()->query("SELECT COUNT(*) FROM {$sub[$tipo]} WHERE articulo_id = $id")->fetchColumn();
        afirmar($n === 1, "$tipo: sin fila de subtipo");
        foreach ($sub as $otro => $tabla) {
            if ($otro !== $tipo) {
                afirmar((int) db()->query("SELECT COUNT(*) FROM $tabla WHERE articulo_id = $id")->fetchColumn() === 0, "$tipo: fila en $tabla");
            }
        }
        $ex = db()->query("SELECT sucursal_id, cantidad, cantidad_reservada, costo_promedio FROM existencias WHERE articulo_id = $id ORDER BY sucursal_id")->fetchAll();
        afirmar(count($ex) === 2 && (int) $ex[0]['sucursal_id'] === 1 && (int) $ex[1]['sucursal_id'] === 2, "$tipo: existencias " . json_encode($ex));
        foreach ($ex as $x) {
            afirmar($x['cantidad'] === '0.000' && $x['cantidad_reservada'] === '0.000' && $x['costo_promedio'] === '0.000000', "$tipo: saldo inicial no es 0");
        }
        afirmar((int) db()->query("SELECT COUNT(*) FROM movimientos_inventario WHERE articulo_id = $id")->fetchColumn() === 0, "$tipo: movimientos sin saldo inicial");
    }
    $e = db()->query("SELECT * FROM esencias WHERE articulo_id = {$S['esencia']}")->fetch();
    afirmar($e['densidad_g_ml'] === '0.9500' && $e['genero'] === 'femenino' && (int) $e['marca_id'] === $S['marca'], 'datos de esencia ' . json_encode($e));
    $v = db()->query("SELECT * FROM envases_vacios WHERE articulo_id = {$S['envase']}")->fetch();
    afirmar($v['capacidad_ml'] === '50.00' && $v['color'] === 'Transparente', 'datos de envase');
    afirmar(f1bArt($S['esencia'])['unidad_medida'] === 'g', 'unidad de la esencia');
    afirmar(f1bArt($S['envase'])['unidad_medida'] === 'und', 'unidad del envase');
    // Una categoría de otro tipo se rechaza; una inactiva también.
    $r = f1bCrear($A, f1bDatos('producto', ['categoria_id' => (string) $S['cat_esencia']]), 422);
    afirmar(isset($r['json']['errores']['categoria_id']), 'categoría de otro tipo');
    $r = f1bCrear($A, f1bDatos('producto', ['marca_id' => (string) $S['marca2']]), 422);
    afirmar(isset($r['json']['errores']['marca_id']), 'marca inactiva');
    $r = f1bCrear($A, f1bDatos('producto', ['marca_id' => '98765']), 422);
    afirmar(isset($r['json']['errores']['marca_id']), 'marca inexistente');
    return 'existencias en 2 sucursales activas, 0 en la inactiva';
});

caso('Alta con datos inválidos (campo por campo): 422 con errores por campo y nada se crea', function () use ($A) {
    $antes = f1bCuenta('articulos') . '/' . f1bCuenta('existencias');
    $casos = [
        ['nombre', ['nombre' => 'a']],
        ['nombre', ['nombre' => str_repeat('x', 151)]],
        ['nombre', ['nombre' => "Con\x01control"]],
        ['tasa_iva', ['tasa_iva' => '101']],
        ['tasa_iva', ['tasa_iva' => '-1']],
        ['tasa_iva', ['tasa_iva' => 'abc']],
        ['stock_minimo', ['stock_minimo' => '-1']],
        ['stock_minimo', ['stock_minimo' => '1.2345']],
        ['unidad_medida', ['unidad_medida' => 'kg']],
        ['categoria_id', ['categoria_id' => 'abc']],
        ['descripcion', ['descripcion' => str_repeat('d', 1001)]],
        ['saldo_inicial', ['saldo_inicial' => '-5', 'costo_unitario' => '10']],
        ['saldo_inicial', ['saldo_inicial' => '1e3', 'costo_unitario' => '10']],
        ['saldo_inicial', ['saldo_inicial' => '1.2345', 'costo_unitario' => '10']],
        ['saldo_inicial', ['saldo_inicial' => '0', 'costo_unitario' => '10']],
        ['saldo_inicial', ['saldo_inicial' => '', 'costo_unitario' => '10']],
        ['saldo_inicial', ['saldo_inicial' => '1,000.5', 'costo_unitario' => '10']],
        ['costo_unitario', ['saldo_inicial' => '10', 'costo_unitario' => '']],
        ['costo_unitario', ['saldo_inicial' => '10', 'costo_unitario' => '-1']],
        ['costo_unitario', ['saldo_inicial' => '10', 'costo_unitario' => '0.1234567']],
        ['costo_unitario', ['saldo_inicial' => '10', 'costo_unitario' => '99999999.9999999']],
        ['costo_unitario', ['saldo_inicial' => '10', 'costo_unitario' => '1e2']],
    ];
    foreach ($casos as [$campo, $extra]) {
        $r = f1bCrear($A, f1bDatos('producto', $extra), 422);
        afirmar(isset($r['json']['errores'][$campo]), "campo $campo no reportado para " . json_encode($extra) . ': ' . $r['body']);
    }
    $r = f1bCrear($A, f1bDatos('envase', ['capacidad_ml' => '']), 422);
    afirmar(isset($r['json']['errores']['capacidad_ml']), 'envase sin capacidad');
    $r = f1bCrear($A, f1bDatos('envase', ['unidad_medida' => 'ml']), 422);
    afirmar(isset($r['json']['errores']['unidad_medida']), 'envase en ml');
    $r = f1bCrear($A, f1bDatos('insumo', ['tipo_insumo' => 'nada']), 422);
    afirmar(isset($r['json']['errores']['tipo_insumo']), 'tipo de insumo');
    $r = f1bCrear($A, f1bDatos('esencia', ['densidad_g_ml' => '0']), 422);
    afirmar(isset($r['json']['errores']['densidad_g_ml']), 'densidad 0');
    $r = f1bCrear($A, f1bDatos('esencia', ['saldo_inicial' => '10', 'costo_unitario' => '5']), 422);
    afirmar(isset($r['json']['errores']['saldo_inicial']), 'esencia con saldo en el alta');
    $r = f1bCrear($A, ['tipo' => 'otra', 'nombre' => 'Zzz', 'sku_auto' => '1'], 422);
    afirmar(isset($r['json']['errores']['tipo']), 'tipo inválido');
    afirmar(f1bCuenta('articulos') . '/' . f1bCuenta('existencias') === $antes, 'se creó algo a pesar de los errores');
    return count($casos) + 6 . ' entradas inválidas rechazadas';
});

// ======================================================================== SKU
caso('SKU: formato validado, normalizado a mayúsculas y duplicado => 409 limpio', function () use ($A) {
    foreach (['ab', 'abc def', 'ÑANDU-1', '-ABC1', 'ABC1-', 'A_B-1', str_repeat('A', 41), "ABC\n123", 'ABC;DROP'] as $sku) {
        $r = f1bCrear($A, f1bDatos('producto', ['sku_auto' => '0', 'sku' => $sku]), 422);
        afirmar(isset($r['json']['errores']['sku']), "SKU «{$sku}» aceptado");
    }
    $r = f1bCrear($A, f1bDatos('producto', ['sku_auto' => '0', 'sku' => ' tst-0001 ']));
    $id = (int) $r['json']['id'];
    afirmar(f1bArt($id)['sku'] === 'TST-0001', 'SKU no normalizado');
    foreach (['TST-0001', 'tst-0001', ' Tst-0001'] as $dup) {
        $r = f1bCrear($A, f1bDatos('envase', ['sku_auto' => '0', 'sku' => $dup]), 409);
        afirmar(isset($r['json']['errores']['sku']) && str_contains($r['json']['errores']['sku'], 'SKU'), 'mensaje de duplicado: ' . $r['body']);
        afirmar(!str_contains($r['body'], 'uq_articulos') && !str_contains($r['body'], 'SQLSTATE') && !str_contains($r['body'], 'Duplicate'), 'filtra detalle de BD');
    }
    afirmar((int) db()->query("SELECT COUNT(*) FROM articulos WHERE sku = 'TST-0001'")->fetchColumn() === 1, 'SKU repetido en BD');
    afirmar((int) db()->query("SELECT COUNT(*) FROM articulos a WHERE NOT EXISTS (SELECT 1 FROM existencias x WHERE x.articulo_id = a.id)")->fetchColumn() === 0,
        'el rechazo dejó un artículo sin existencias');
    // Un SKU manual con forma de autogenerado: el consecutivo lo salta sin duplicar.
    $sig = (int) db()->query("SELECT siguiente FROM consecutivos WHERE tipo = 'SKU' ORDER BY sucursal_id LIMIT 1")->fetchColumn();
    $manual = 'PRD-' . str_pad((string) $sig, 6, '0', STR_PAD_LEFT);
    f1bCrear($A, f1bDatos('producto', ['sku_auto' => '0', 'sku' => $manual]));
    $r = f1bCrear($A, f1bDatos('producto'));
    $esperado = 'PRD-' . str_pad((string) ($sig + 1), 6, '0', STR_PAD_LEFT);
    afirmar(f1bArt((int) $r['json']['id'])['sku'] === $esperado, "esperaba $esperado y salió " . f1bArt((int) $r['json']['id'])['sku']);
});

caso('SKU duplicado CONCURRENTE (2 procesos a la vez): uno crea, el otro falla limpio', function () use ($A) {
    $uid = (string) idUsuario('adm1b');
    $inicio = (string) ((int) floor(microtime(true) * 1000) + 3500);
    $s = f1bConcurrentes([[$inicio, $uid, 'CONC-DUP-1', 'Concurrente A'], [$inicio, $uid, 'CONC-DUP-1', 'Concurrente B']]);
    $ok = array_values(array_filter($s, fn ($x) => str_starts_with($x, 'ok:')));
    $err = array_values(array_filter($s, fn ($x) => str_starts_with($x, 'err:409:')));
    afirmar(count($ok) === 1 && count($err) === 1, 'resultados: ' . json_encode($s));
    afirmar(!str_contains($err[0], 'SQLSTATE') && !str_contains($err[0], 'uq_articulos'), 'filtra detalle de BD');
    afirmar((int) db()->query("SELECT COUNT(*) FROM articulos WHERE sku = 'CONC-DUP-1'")->fetchColumn() === 1, 'SKU repetido');
    $id = (int) explode(':', $ok[0])[1];
    afirmar((int) db()->query("SELECT COUNT(*) FROM existencias WHERE articulo_id = $id")->fetchColumn() === 2, 'sin existencias del ganador');
    return $s[0] . ' | ' . $s[1];
});

caso('SKU autogenerado: 10 altas concurrentes sin duplicados ni saltos', function () {
    $uid = (string) idUsuario('adm1b');
    $antes = (int) db()->query("SELECT siguiente FROM consecutivos WHERE tipo = 'SKU' ORDER BY sucursal_id LIMIT 1")->fetchColumn();
    $inicio = (string) ((int) floor(microtime(true) * 1000) + 6000);
    $args = [];
    for ($i = 0; $i < 10; $i++) {
        $args[] = [$inicio, $uid, 'auto', "Auto concurrente $i"];
    }
    $s = f1bConcurrentes($args);
    $skus = [];
    foreach ($s as $x) {
        afirmar(str_starts_with($x, 'ok:'), "alta fallida: $x");
        $skus[] = explode(':', $x)[2];
    }
    afirmar(count(array_unique($skus)) === 10, 'SKU repetidos: ' . implode(',', $skus));
    $nums = array_map(fn ($k) => (int) substr($k, 4), $skus);
    sort($nums);
    afirmar($nums === range($antes, $antes + 9), 'números no consecutivos: ' . implode(',', $nums) . " (esperaba desde $antes)");
    $despues = (int) db()->query("SELECT siguiente FROM consecutivos WHERE tipo = 'SKU' ORDER BY sucursal_id LIMIT 1")->fetchColumn();
    afirmar($despues === $antes + 10, "consecutivo $antes -> $despues");
    return 'PRD-' . $nums[0] . '..' . $nums[9];
});

// ============================================================= saldo inicial y libro
caso('Saldo inicial de un producto: movimiento en el libro + existencias cuadradas (sin UPDATE directo)', function () use ($A, &$S) {
    $r = f1bCrear($A, f1bDatos('producto', ['saldo_inicial' => '12,5', 'costo_unitario' => '18500.50', 'marca_id' => (string) $S['marca']]));
    $id = (int) $r['json']['id'];
    $S['prod_saldo'] = $id;
    $m = db()->query("SELECT * FROM movimientos_inventario WHERE articulo_id = $id")->fetchAll();
    afirmar(count($m) === 1, 'movimientos: ' . count($m));
    $m = $m[0];
    afirmar($m['tipo'] === 'saldo_inicial' && (int) $m['sucursal_id'] === 1 && $m['cantidad'] === '12.500' && $m['saldo_despues'] === '12.500'
        && $m['costo_unitario'] === '18500.500000' && $m['costo_promedio_despues'] === '18500.500000'
        && (int) $m['usuario_id'] === idUsuario('adm1b') && $m['referencia_tipo'] === 'manual', 'movimiento ' . json_encode($m));
    $x = db()->query("SELECT sucursal_id, cantidad, costo_promedio, ultimo_costo FROM existencias WHERE articulo_id = $id ORDER BY sucursal_id")->fetchAll();
    afirmar($x[0]['cantidad'] === '12.500' && $x[0]['costo_promedio'] === '18500.500000' && $x[0]['ultimo_costo'] === '18500.500000', 'existencias suc.1 ' . json_encode($x[0]));
    afirmar($x[1]['cantidad'] === '0.000', 'la otra sucursal debe seguir en 0');
    $suma = db()->query("SELECT SUM(cantidad) FROM movimientos_inventario WHERE articulo_id = $id AND sucursal_id = 1")->fetchColumn();
    afirmar($suma === '12.500', 'libro y existencias no cuadran');
    // Un decimal con 3 decimales exactos no pasa por float.
    $r = f1bCrear($A, f1bDatos('insumo', ['saldo_inicial' => '0.001', 'costo_unitario' => '0.000001']));
    $i2 = (int) $r['json']['id'];
    $x = db()->query("SELECT cantidad, costo_promedio FROM existencias WHERE articulo_id = $i2 AND sucursal_id = 1")->fetch();
    afirmar($x['cantidad'] === '0.001' && $x['costo_promedio'] === '0.000001', 'precisión ' . json_encode($x));
    // El libro es de solo inserción.
    foreach (['UPDATE movimientos_inventario SET motivo = \'x\' WHERE articulo_id = ' . $id, 'DELETE FROM movimientos_inventario WHERE articulo_id = ' . $id] as $sql) {
        $rechazado = false;
        try {
            db()->exec($sql);
        } catch (PDOException) {
            $rechazado = true;
        }
        afirmar($rechazado, "el libro aceptó: $sql");
    }
    // Costos de BD: ninguna fila negativa.
    afirmar((int) db()->query('SELECT COUNT(*) FROM existencias WHERE cantidad < 0 OR costo_promedio < 0')->fetchColumn() === 0, 'saldos negativos');
});

// ================================================================= permisos y costos
caso('El empleado NO ve costos: ni en HTML ni en JSON, ni en listado ni en detalle', function () use ($E, &$S) {
    $id = $S['prod_saldo'];
    $sku = f1bArt($id)['sku'];
    $vistas = [
        'lista JSON' => $E->pedir('GET', '/inventario/articulos?q=' . $sku),
        'lista HTML' => $E->pedir('GET', '/inventario/articulos?q=' . $sku, null, [], false),
        'detalle JSON' => $E->pedir('GET', "/inventario/articulos/$id"),
        'detalle HTML' => $E->pedir('GET', "/inventario/articulos/$id", null, [], false),
    ];
    foreach ($vistas as $que => $r) {
        afirmar($r['status'] === 200, "$que: {$r['status']}");
        afirmar(stripos($r['body'], 'costo') === false, "$que menciona costos");
        afirmar(!str_contains($r['body'], '18500') && !str_contains($r['body'], '18.500'), "$que contiene el valor del costo");
    }
    $fila = $vistas['lista JSON']['json']['articulos'][0];
    afirmar($fila['sku'] === $sku && $fila['cantidad'] === '12.500', 'el empleado sí ve cantidades: ' . json_encode($fila));
    afirmar(!array_key_exists('costo_promedio', $fila) && !array_key_exists('ultimo_costo', $fila), 'claves de costo');
    $ex = $vistas['detalle JSON']['json']['articulo']['existencias'][0];
    afirmar(isset($ex['cantidad']) && !isset($ex['costo_promedio']) && !isset($ex['ultimo_costo']), 'existencias del detalle: ' . json_encode($ex));
    // Capa de servicio: sin ver_costos el resultado no trae costos, aunque el llamador los pida.
    $svc = new ArticuloService();
    $res = $svc->listar(['q' => $sku], 1, ['articulos.ver' => true, 'inventario.ver' => true]);
    afirmar(!str_contains(json_encode($res), 'costo'), 'servicio devuelve costos sin permiso');
    $res = $svc->listar(['q' => $sku], 1, ['articulos.ver' => true]);
    afirmar(!str_contains(json_encode($res), 'costo') && !str_contains(json_encode($res), 'cantidad'), 'servicio devuelve existencias sin inventario.ver');
    $det = $svc->obtener($id, ['articulos.ver' => true]);
    afirmar(!isset($det['existencias']), 'detalle con existencias sin permiso');
});

caso('Admin y contador SÍ ven costos', function () use ($A, $C, &$S) {
    $id = $S['prod_saldo'];
    $sku = f1bArt($id)['sku'];
    foreach (['admin' => $A, 'contador' => $C] as $quien => $n) {
        $fila = $n->pedir('GET', '/inventario/articulos?q=' . $sku)['json']['articulos'][0];
        afirmar($fila['costo_promedio'] === '18500.500000', "$quien lista: " . json_encode($fila));
        $ex = $n->pedir('GET', "/inventario/articulos/$id")['json']['articulo']['existencias'][0];
        afirmar($ex['costo_promedio'] === '18500.500000' && $ex['ultimo_costo'] === '18500.500000', "$quien detalle");
        $h = $n->pedir('GET', '/inventario/articulos?q=' . $sku, null, [], false)['body'];
        afirmar(str_contains($h, '18500.5') && str_contains($h, 'Costo promedio'), "$quien HTML");
    }
});

caso('El empleado y el contador no pueden mutar (403) y la BD no cambia', function () use ($E, $C, &$S) {
    $id = $S['prod_saldo'];
    $antes = f1bCuenta('articulos') . '/' . f1bCuenta('marcas') . '/' . f1bCuenta('categorias') . '/' . f1bCuenta('recipientes_esencia') . '/' . f1bCuenta('auditoria');
    $nombre = f1bArt($id)['nombre'];
    foreach (['empleado' => $E, 'contador' => $C] as $quien => $n) {
        $intentos = [
            ['POST', '/inventario/articulos', f1bDatos('producto')],
            ['POST', "/inventario/articulos/$id", ['nombre' => 'Hackeado', 'tipo' => 'producto']],
            ['POST', "/inventario/articulos/$id/activo", ['activo' => '0']],
            ['POST', '/inventario/recipientes', ['esencia_id' => (string) $S['esencia'], 'gramos_iniciales' => '5', 'costo_g' => '1']],
            ['POST', '/admin/marcas', ['nombre' => 'Intrusa']],
            ['POST', '/admin/categorias', ['nombre' => 'Intrusa', 'tipo' => 'general']],
        ];
        foreach ($intentos as [$m, $ruta, $body]) {
            $r = $n->post($ruta, $body);
            afirmar($r['status'] === 403 && ($r['json']['error'] ?? '') === 'sin_permiso', "$quien $m $ruta => {$r['status']}");
        }
        foreach (['/inventario/articulos/nuevo', "/inventario/articulos/$id/editar"] as $ruta) {
            afirmar($n->pedir('GET', $ruta)['status'] === 403, "$quien GET $ruta");
        }
        afirmar($n->pedir('GET', '/inventario/articulos')['status'] === 200, "$quien debe poder leer");
    }
    afirmar(f1bArt($id)['nombre'] === $nombre && (int) f1bArt($id)['activo'] === 1, 'el artículo cambió');
    $despues = f1bCuenta('articulos') . '/' . f1bCuenta('marcas') . '/' . f1bCuenta('categorias') . '/' . f1bCuenta('recipientes_esencia') . '/' . f1bCuenta('auditoria');
    [$a1, $m1, $c1, $r1] = explode('/', $antes);
    [$a2, $m2, $c2, $r2] = explode('/', $despues);
    afirmar("$a1/$m1/$c1/$r1" === "$a2/$m2/$c2/$r2", "cambió la BD: $antes -> $despues");
    afirmar((int) db()->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'acceso.denegado' AND despues LIKE '%articulos.gestionar%'")->fetchColumn() >= 1, 'sin auditoría de acceso denegado');
});

caso('Sin CSRF (o con CSRF falso) => 403 y no se crea nada', function () use ($A, $P1B) {
    $antes = f1bCuenta('articulos');
    $r = $A->pedir('POST', '/inventario/articulos', f1bDatos('producto'));
    afirmar($r['status'] === 403 && ($r['json']['error'] ?? '') === 'csrf_invalido', 'sin cabecera: ' . $r['status'] . $r['body']);
    $r = $A->pedir('POST', '/inventario/articulos', f1bDatos('producto'), ['X-CSRF-Token' => str_repeat('a', 64)]);
    afirmar($r['status'] === 403, 'token falso: ' . $r['status']);
    $r = $A->pedir('POST', '/inventario/articulos', f1bDatos('producto'), [], false, true);
    afirmar($r['status'] === 403, 'formulario sin _csrf: ' . $r['status']);
    foreach (['/admin/marcas' => ['nombre' => 'SinCsrf'], '/admin/categorias' => ['nombre' => 'SinCsrf', 'tipo' => 'general'],
        '/inventario/recipientes' => ['esencia_id' => '1'], '/inventario/articulos/1/activo' => ['activo' => '0']] as $ruta => $body) {
        $r = $A->pedir('POST', $ruta, $body);
        afirmar($r['status'] === 403 && ($r['json']['error'] ?? '') === 'csrf_invalido', "$ruta sin CSRF: " . $r['status']);
    }
    // El token de OTRA sesión tampoco vale.
    $otro = navLogueado('emp1b', $P1B['emp1b']);
    $r = $A->pedir('POST', '/inventario/articulos', f1bDatos('producto'), ['X-CSRF-Token' => $otro->csrf()]);
    afirmar($r['status'] === 403, 'token de otra sesión: ' . $r['status']);
    afirmar(f1bCuenta('articulos') === $antes, 'se creó un artículo');
});

// ========================================================================= XSS / SQLi
caso('XSS en nombre y descripción: escapado en listado, detalle y formulario', function () use ($A, &$S) {
    $xss = '<script>alert(1)</script>';
    $desc = '"><img src=x onerror=alert(1)>';
    $r = f1bCrear($A, f1bDatos('producto', ['nombre' => $xss, 'descripcion' => $desc]));
    $id = (int) $r['json']['id'];
    $S['xss'] = $id;
    foreach (['/inventario/articulos?q=alert' => 'listado', "/inventario/articulos/$id" => 'detalle', "/inventario/articulos/$id/editar" => 'formulario'] as $ruta => $que) {
        $h = $A->pedir('GET', $ruta, null, [], false);
        afirmar($h['status'] === 200, "$que: {$h['status']}");
        afirmar(!str_contains($h['body'], '<script>alert') && !str_contains($h['body'], '<img src=x'), "$que sin escapar");
        afirmar(!preg_match('/<[^>]*\sonerror=/i', $h['body']), "$que: atributo onerror inyectado");
        if ($que !== 'listado') {
            afirmar(str_contains($h['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), "$que no muestra el nombre escapado");
        }
    }
    // Reflejo en el formulario de error y en el filtro de búsqueda.
    $h = f1bPost($A, '/inventario/articulos', ['tipo' => 'producto', 'sku' => '"><script>x()</script>', 'nombre' => '<b onmouseover=alert(1)>x']);
    afirmar($h['status'] === 422 && !str_contains($h['body'], '<script>x()') && !str_contains($h['body'], '<b onmouseover'), 'reflejo en formulario');
    $h = $A->pedir('GET', '/inventario/articulos?q=' . rawurlencode('"><script>q()</script>'), null, [], false);
    afirmar($h['status'] === 200 && !str_contains($h['body'], '<script>q()'), 'reflejo en el filtro');
});

caso('SQL injection en la búsqueda y los filtros: consultas preparadas, comodines escapados', function () use ($A, &$S) {
    f1bCrear($A, f1bDatos('producto', ['nombre' => 'Descuento 50% oferta']));
    $total = (int) db()->query('SELECT COUNT(*) FROM articulos WHERE activo = 1')->fetchColumn();
    afirmar($total > 5, 'pocos artículos para la prueba');
    $q = fn (string $s, string $extra = '') => $A->pedir('GET', '/inventario/articulos?q=' . rawurlencode($s) . $extra);
    $r = $q("' OR '1'='1");
    afirmar($r['status'] === 200 && $r['json']['total'] === 0, "OR 1=1: total {$r['json']['total']}");
    $r = $q("x%' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13 -- ");
    afirmar($r['status'] === 200 && $r['json']['total'] === 0, 'UNION');
    $r = $q("'; DROP TABLE articulos; -- ");
    afirmar($r['status'] === 200 && $r['json']['total'] === 0, 'DROP');
    afirmar((int) db()->query('SELECT COUNT(*) FROM articulos')->fetchColumn() > 5, 'la tabla articulos fue alterada');
    $r = $q('%');
    afirmar($r['status'] === 200 && $r['json']['total'] === 1, "'%' literal debe coincidir solo con el nombre que lo contiene, no con todo: {$r['json']['total']}");
    $r = $q('_');
    afirmar($r['status'] === 200 && $r['json']['total'] === 0, "'_' literal: {$r['json']['total']}");
    $r = $A->pedir('GET', '/inventario/articulos?tipo=' . rawurlencode("esencia' OR '1'='1") . '&activo=' . rawurlencode('1 OR 1=1') . '&pagina=' . rawurlencode('2; DROP TABLE articulos'));
    afirmar($r['status'] === 200 && $r['json']['pagina'] === 2 && $r['json']['total'] === $total, 'filtros inválidos se ignoran: ' . json_encode([$r['json']['pagina'], $r['json']['total'], $total]));
    $r = $A->pedir('GET', '/inventario/articulos?q[]=a&tipo[]=esencia');
    afirmar($r['status'] === 200 && $r['json']['total'] === $total, 'parámetros array');
    afirmar($A->pedir('GET', '/inventario/articulos/1%20OR%201=1')['status'] === 404, 'id no numérico');
    afirmar($A->pedir('GET', '/inventario/articulos/99999999')['status'] === 404, 'id inexistente');
    $r = $q('Descuento 50% oferta');
    afirmar($r['json']['total'] === 1, 'búsqueda normal con %');
});

// ========================================================= edición, tipo, desactivación
/** Payload de edición a partir de la fila actual. */
function f1bEdicion(int $id, array $cambios = []): array
{
    $a = f1bArt($id);
    $base = ['tipo' => $a['tipo'], 'sku' => $a['sku'], 'nombre' => $a['nombre'], 'descripcion' => (string) $a['descripcion'],
        'categoria_id' => (string) $a['categoria_id'], 'unidad_medida' => $a['unidad_medida'], 'tasa_iva' => $a['tasa_iva'], 'stock_minimo' => $a['stock_minimo']];
    $sub = [
        'esencia' => ['genero' => 'femenino', 'densidad_g_ml' => '0.9500'], 'producto' => ['contenido_ml' => '100'],
        'envase' => ['capacidad_ml' => '50', 'material' => 'vidrio', 'tipo_cierre' => 'atomizador'], 'insumo' => ['tipo_insumo' => 'alcohol'],
    ];
    return array_merge($base, $sub[$a['tipo']], $cambios);
}

caso('Edición: cambios de datos permitidos y auditados con antes/después', function () use ($A, &$S) {
    $id = $S['prod_saldo'];
    $r = $A->post("/inventario/articulos/$id", f1bEdicion($id, ['nombre' => 'Producto editado', 'tasa_iva' => '5', 'stock_minimo' => '3.5', 'descripcion' => 'Nueva descripción']));
    afirmar($r['status'] === 200 && ($r['json']['ok'] ?? false), 'editar: ' . $r['body']);
    $a = f1bArt($id);
    afirmar($a['nombre'] === 'Producto editado' && $a['tasa_iva'] === '5.00' && $a['stock_minimo'] === '3.500' && $a['descripcion'] === 'Nueva descripción', 'datos ' . json_encode($a));
    $au = db()->query("SELECT antes, despues FROM auditoria WHERE accion = 'articulo.editar' AND entidad_id = $id ORDER BY id DESC LIMIT 1")->fetch();
    $antes = json_decode($au['antes'], true);
    $despues = json_decode($au['despues'], true);
    afirmar($despues['nombre'] === 'Producto editado' && $antes['nombre'] !== 'Producto editado' && $despues['tasa_iva'] === '5.00', 'auditoría de la edición');
    // Sin cambios: no escribe auditoría.
    $n = f1bCuenta('auditoria');
    $r = $A->post("/inventario/articulos/$id", f1bEdicion($id));
    afirmar($r['status'] === 200 && f1bCuenta('auditoria') === $n, 'edición sin cambios auditada');
    afirmar($A->post("/inventario/articulos/$id", f1bEdicion($id, ['nombre' => 'a']))['status'] === 422, 'edición inválida');
    afirmar($A->post('/inventario/articulos/99999', f1bEdicion($id))['status'] === 404, 'editar inexistente');
    afirmar($A->post("/inventario/articulos/$id", f1bEdicion($id, ['marca_id' => '98765']))['status'] === 422, 'marca inexistente al editar');
    // El saldo no se puede tocar desde la edición.
    $A->post("/inventario/articulos/$id", f1bEdicion($id) + ['cantidad' => '999', 'saldo_inicial' => '999', 'costo_promedio' => '1']);
    $x = db()->query("SELECT cantidad, costo_promedio FROM existencias WHERE articulo_id = $id AND sucursal_id = 1")->fetch();
    afirmar($x['cantidad'] === '12.500' && $x['costo_promedio'] === '18500.500000', 'la edición alteró el saldo');
    afirmar((int) db()->query("SELECT COUNT(*) FROM movimientos_inventario WHERE articulo_id = $id")->fetchColumn() === 1, 'la edición creó movimientos');
});

caso('Cambiar tipo, SKU o unidad con movimientos está PROHIBIDO; sin historia sí se puede', function () use ($A, &$S) {
    $id = $S['prod_saldo'];   // tiene saldo inicial => movimientos
    $antes = f1bArt($id);
    foreach ([
        ['tipo', ['tipo' => 'insumo', 'tipo_insumo' => 'otro', 'unidad_medida' => 'und']],
        ['tipo', ['tipo' => 'envase', 'capacidad_ml' => '30', 'unidad_medida' => 'und']],
        ['sku', ['sku' => 'NUEVO-SKU-1']],
        ['unidad_medida', ['unidad_medida' => 'ml']],
    ] as [$campo, $cambio]) {
        $r = $A->post("/inventario/articulos/$id", f1bEdicion($id, $cambio));
        afirmar($r['status'] === 409 && isset($r['json']['errores'][$campo]), "$campo: {$r['status']} {$r['body']}");
    }
    afirmar(f1bArt($id) === $antes, 'la fila cambió');
    afirmar((int) db()->query("SELECT COUNT(*) FROM productos WHERE articulo_id = $id")->fetchColumn() === 1
        && (int) db()->query("SELECT COUNT(*) FROM insumos WHERE articulo_id = $id")->fetchColumn() === 0, 'subtipo alterado');
    // Con el formulario HTML: 409 y el mensaje se muestra.
    $h = f1bPost($A, "/inventario/articulos/$id", f1bEdicion($id, ['tipo' => 'insumo', 'tipo_insumo' => 'otro', 'unidad_medida' => 'und']));
    afirmar($h['status'] === 409 && str_contains($h['body'], 'No se puede cambiar el tipo'), 'HTML ' . $h['status']);
    // Sin historia: el envase del caso de alta puede cambiar de tipo.
    $e = $S['envase'];
    $r = $A->post("/inventario/articulos/$e", f1bEdicion($e, ['tipo' => 'insumo', 'tipo_insumo' => 'caja', 'unidad_medida' => 'und']));
    afirmar($r['status'] === 200, 'cambio de tipo sin historia: ' . $r['body']);
    afirmar(f1bArt($e)['tipo'] === 'insumo'
        && (int) db()->query("SELECT COUNT(*) FROM envases_vacios WHERE articulo_id = $e")->fetchColumn() === 0
        && db()->query("SELECT tipo FROM insumos WHERE articulo_id = $e")->fetchColumn() === 'caja'
        && (int) db()->query("SELECT COUNT(*) FROM existencias WHERE articulo_id = $e")->fetchColumn() === 2, 'subtipo no se reemplazó');
    // Y sin historia también se puede cambiar el SKU, pero no a uno ya usado.
    $r = $A->post("/inventario/articulos/$e", f1bEdicion($e, ['sku' => 'TST-0001']));
    afirmar($r['status'] === 409 && isset($r['json']['errores']['sku']), 'SKU repetido al editar');
    $r = $A->post("/inventario/articulos/$e", f1bEdicion($e, ['sku' => 'env-renombrado-1']));
    afirmar($r['status'] === 200 && f1bArt($e)['sku'] === 'ENV-RENOMBRADO-1', 'cambio de SKU sin historia');
    // La esencia con recipiente (más abajo) también queda bloqueada: ver caso de recipientes.
});

caso('Desactivación y reactivación LÓGICAS (nunca DELETE), con auditoría', function () use ($A, &$S) {
    $id = $S['prod_saldo'];
    $total = f1bCuenta('articulos');
    $r = $A->post("/inventario/articulos/$id/activo", ['activo' => '0']);
    afirmar($r['status'] === 200, 'desactivar: ' . $r['body']);
    afirmar(f1bCuenta('articulos') === $total && (int) f1bArt($id)['activo'] === 0, 'la fila debe seguir existiendo, inactiva');
    afirmar((int) db()->query("SELECT COUNT(*) FROM movimientos_inventario WHERE articulo_id = $id")->fetchColumn() === 1
        && (int) db()->query("SELECT COUNT(*) FROM existencias WHERE articulo_id = $id")->fetchColumn() === 2, 'historia e inventario intactos');
    $ids = fn (string $q) => array_column($A->pedir('GET', '/inventario/articulos?q=' . rawurlencode(f1bArt($id)['sku']) . $q)['json']['articulos'], 'id');
    afirmar($ids('') === [], 'el listado por defecto muestra inactivos');
    afirmar($ids('&activo=0') === [$id] && $ids('&activo=todos') === [$id], 'filtro de inactivos');
    afirmar($A->pedir('GET', "/inventario/articulos/$id")['json']['articulo']['activo'] === false, 'detalle de un inactivo');
    $r = $A->post("/inventario/articulos/$id/activo", ['activo' => '1']);
    afirmar($r['status'] === 200 && (int) f1bArt($id)['activo'] === 1 && $ids('') === [$id], 'reactivar');
    afirmar($A->post("/inventario/articulos/$id/activo", ['activo' => 'quizas'])['status'] === 422, 'valor inválido');
    afirmar($A->post('/inventario/articulos/99999/activo', ['activo' => '0'])['status'] === 404, 'inexistente');
    $acc = db()->query("SELECT accion, antes, despues FROM auditoria WHERE entidad = 'articulos' AND entidad_id = $id AND accion IN ('articulo.desactivar','articulo.activar') ORDER BY id")->fetchAll();
    afirmar(count($acc) === 2 && $acc[0]['accion'] === 'articulo.desactivar' && json_decode($acc[0]['antes'], true) === ['activo' => 1]
        && json_decode($acc[0]['despues'], true) === ['activo' => 0], 'auditoría ' . json_encode($acc));
    // No se desactiva con unidades apartadas.
    $r = f1bCrear($A, f1bDatos('producto'));
    $ap = (int) $r['json']['id'];
    db()->exec("UPDATE existencias SET cantidad = 5, cantidad_reservada = 2 WHERE articulo_id = $ap AND sucursal_id = 1");
    afirmar($A->post("/inventario/articulos/$ap/activo", ['activo' => '0'])['status'] === 409 && (int) f1bArt($ap)['activo'] === 1, 'desactivó con reservas');
    // HTML: redirección 303 al detalle.
    $h = f1bPost($A, "/inventario/articulos/$id/activo", ['activo' => '1']);
    afirmar($h['status'] === 303 && str_contains($h['headers']['location'][0] ?? '', "/inventario/articulos/$id?m=activo"), 'redirección HTML');
});

// ================================================================== recipientes
caso('Recipientes: saldo inicial en gramos al libro, promedio ponderado y cuadre con v_control_existencias_esencia', function () use ($A, &$S) {
    $esen = $S['esencia'];
    $uid = idUsuario('adm1b');
    $r = $A->post('/inventario/recipientes', ['esencia_id' => (string) $esen, 'gramos_iniciales' => '100.5', 'costo_g' => '50.25',
        'capacidad_g' => '500', 'tara_g' => '120.5', 'descripcion' => 'Frasco 1', 'ubicacion' => 'Estante A']);
    afirmar($r['status'] === 201, 'crear recipiente: ' . $r['body']);
    $r1 = (int) $r['json']['id'];
    $S['recipiente'] = $r1;
    $rec = db()->query("SELECT * FROM recipientes_esencia WHERE id = $r1")->fetch();
    afirmar($rec['codigo'] === 'R-' . str_pad((string) $r1, 6, '0', STR_PAD_LEFT) && $rec['gramos_actuales'] === '100.500' && $rec['estado'] === 'activo'
        && $rec['capacidad_g'] === '500.000' && $rec['tara_g'] === '120.500' && (int) $rec['sucursal_id'] === 1, 'recipiente ' . json_encode($rec));
    $mov = db()->query("SELECT * FROM movimientos_esencia WHERE recipiente_id = $r1")->fetchAll();
    afirmar(count($mov) === 1 && $mov[0]['tipo'] === 'saldo_inicial' && $mov[0]['gramos'] === '100.500' && $mov[0]['saldo_recipiente'] === '100.500'
        && $mov[0]['costo_g'] === '50.250000' && (int) $mov[0]['usuario_id'] === $uid, 'movimiento ' . json_encode($mov));
    $ex = db()->query("SELECT cantidad, costo_promedio FROM existencias WHERE articulo_id = $esen AND sucursal_id = 1")->fetch();
    afirmar($ex['cantidad'] === '100.500' && $ex['costo_promedio'] === '50.250000', 'existencias ' . json_encode($ex));
    // Segundo recipiente a otro costo: promedio ponderado exacto.
    $r = $A->post('/inventario/recipientes', ['esencia_id' => (string) $esen, 'gramos_iniciales' => '50', 'costo_g' => '60', 'descripcion' => 'Frasco 2']);
    afirmar($r['status'] === 201, 'segundo recipiente');
    $r2 = (int) $r['json']['id'];
    $total = bcadd(bcmul('100.5', '50.25', 9), bcmul('50', '60', 9), 9);
    $prom = bcdiv($total, '150.5', 12);
    $esperado = bcadd($prom, '0.0000005', 6);   // redondeo half-up a 6 decimales
    $ex = db()->query("SELECT cantidad, costo_promedio, ultimo_costo FROM existencias WHERE articulo_id = $esen AND sucursal_id = 1")->fetch();
    afirmar($ex['cantidad'] === '150.500' && $ex['costo_promedio'] === $esperado && $ex['ultimo_costo'] === '60.000000', "promedio {$ex['costo_promedio']} esperado $esperado");
    // La vista de control: cuadra en todas las sucursales.
    $v = db()->query("SELECT sucursal_id, gramos_existencias, gramos_recipientes, descuadrado FROM v_control_existencias_esencia WHERE articulo_id = $esen ORDER BY sucursal_id")->fetchAll();
    afirmar(count($v) === 2 && $v[0]['gramos_existencias'] === '150.500' && $v[0]['gramos_recipientes'] === '150.500' && (int) $v[0]['descuadrado'] === 0
        && (int) $v[1]['descuadrado'] === 0, 'v_control ' . json_encode($v));
    afirmar((int) db()->query('SELECT COUNT(*) FROM v_control_existencias_esencia WHERE descuadrado = 1')->fetchColumn() === 0, 'hay esencias descuadradas');
    // Recipiente vacío (sin saldo), en la otra sucursal, con código propio.
    $r = $A->post('/inventario/recipientes', ['esencia_id' => (string) $esen, 'sucursal_id' => '2', 'codigo' => 'FRASCO-A', 'capacidad_g' => '250']);
    afirmar($r['status'] === 201, 'recipiente vacío: ' . $r['body']);
    $r3 = (int) $r['json']['id'];
    $rec = db()->query("SELECT codigo, estado, gramos_actuales, sucursal_id FROM recipientes_esencia WHERE id = $r3")->fetch();
    afirmar($rec['codigo'] === 'FRASCO-A' && $rec['estado'] === 'vacio' && $rec['gramos_actuales'] === '0.000' && (int) $rec['sucursal_id'] === 2, 'vacío ' . json_encode($rec));
    afirmar((int) db()->query("SELECT COUNT(*) FROM movimientos_esencia WHERE recipiente_id = $r3")->fetchColumn() === 0, 'movimiento sin saldo');
    // Validaciones.
    $mal = [
        ['codigo', ['codigo' => 'FRASCO-A']],                   // duplicado => 409 más abajo
        ['codigo', ['codigo' => '123456']],                     // solo dígitos
        ['codigo', ['codigo' => 'con espacio']],
        ['codigo', ['codigo' => 'R-000999']],                   // reservado
        ['gramos_iniciales', ['gramos_iniciales' => '600', 'costo_g' => '1', 'capacidad_g' => '500']],
        ['gramos_iniciales', ['gramos_iniciales' => '-1', 'costo_g' => '1']],
        ['gramos_iniciales', ['gramos_iniciales' => '1e2', 'costo_g' => '1']],
        ['costo_g', ['gramos_iniciales' => '5', 'costo_g' => '']],
        ['costo_g', ['gramos_iniciales' => '5', 'costo_g' => '-3']],
        ['capacidad_g', ['capacidad_g' => '0']],
        ['tara_g', ['tara_g' => '-1']],
        ['esencia_id', ['esencia_id' => (string) $S['producto']]],
        ['esencia_id', ['esencia_id' => '77777']],
        ['esencia_id', ['esencia_id' => '']],
        ['sucursal_id', ['sucursal_id' => '3']],
    ];
    $antes = f1bCuenta('recipientes_esencia');
    foreach ($mal as $i => [$campo, $extra]) {
        $r = $A->post('/inventario/recipientes', array_merge(['esencia_id' => (string) $esen], $extra));
        $codigoEsperado = $i === 0 ? 409 : 422;
        afirmar($r['status'] === $codigoEsperado && isset($r['json']['errores'][$campo]), "$campo " . json_encode($extra) . ": {$r['status']} {$r['body']}");
    }
    afirmar(f1bCuenta('recipientes_esencia') === $antes, 'se creó algún recipiente inválido');
    // Baja y edición.
    afirmar($A->post("/inventario/recipientes/$r1/activo", ['activo' => '0'])['status'] === 409, 'baja con esencia dentro');
    afirmar($A->post("/inventario/recipientes/$r1", ['capacidad_g' => '50', 'descripcion' => 'x'])['status'] === 422, 'capacidad menor que el contenido');
    afirmar($A->post("/inventario/recipientes/$r1", ['capacidad_g' => '600', 'tara_g' => '120.5', 'descripcion' => 'Frasco 1 (editado)', 'ubicacion' => 'Estante B'])['status'] === 200, 'editar');
    $rec = db()->query("SELECT descripcion, capacidad_g, gramos_actuales, codigo FROM recipientes_esencia WHERE id = $r1")->fetch();
    afirmar($rec['descripcion'] === 'Frasco 1 (editado)' && $rec['capacidad_g'] === '600.000' && $rec['gramos_actuales'] === '100.500', 'edición alteró el saldo');
    afirmar($A->post("/inventario/recipientes/$r3/activo", ['activo' => '0'])['status'] === 200
        && db()->query("SELECT estado FROM recipientes_esencia WHERE id = $r3")->fetchColumn() === 'baja', 'baja de recipiente vacío');
    afirmar($A->post("/inventario/recipientes/$r3/activo", ['activo' => '1'])['status'] === 200
        && db()->query("SELECT estado FROM recipientes_esencia WHERE id = $r3")->fetchColumn() === 'vacio', 'reactivar');
    afirmar($A->post('/inventario/recipientes/99999', ['descripcion' => 'x'])['status'] === 404, 'editar inexistente');
    // Con recipientes, la esencia ya no puede cambiar de tipo.
    $r = $A->post("/inventario/articulos/$esen", f1bEdicion($esen, ['tipo' => 'insumo', 'tipo_insumo' => 'otro', 'unidad_medida' => 'g']));
    afirmar($r['status'] === 409 && f1bArt($esen)['tipo'] === 'esencia', 'cambio de tipo de una esencia con recipientes: ' . $r['status']);
    return "R-" . $r1 . ", promedio $esperado";
});

caso('Recipientes: el empleado ve gramos pero no costos y no puede crear; HTML escapado', function () use ($A, $E, &$S) {
    $r = $E->pedir('GET', '/inventario/recipientes');
    afirmar($r['status'] === 200 && $r['json']['total'] >= 3 && isset($r['json']['recipientes'][0]['gramos_actuales']), 'empleado lista: ' . $r['status']);
    afirmar(stripos($r['body'], 'costo') === false && !str_contains($r['body'], '50.25') && !str_contains($r['body'], '60.000'), 'recipientes muestran costos');
    $h = $E->pedir('GET', '/inventario/recipientes', null, [], false);
    afirmar($h['status'] === 200 && stripos($h['body'], 'costo') === false && !str_contains($h['body'], '/inventario/recipientes/nuevo'), 'HTML del empleado');
    $d = $E->pedir('GET', "/inventario/articulos/{$S['esencia']}");
    afirmar($d['status'] === 200 && count($d['json']['recipientes']) >= 3 && stripos($d['body'], 'costo') === false, 'detalle de esencia del empleado');
    afirmar($E->post('/inventario/recipientes', ['esencia_id' => (string) $S['esencia']])['status'] === 403, 'empleado crea recipiente');
    // XSS en la descripción del recipiente.
    $x = $A->post('/inventario/recipientes', ['esencia_id' => (string) $S['esencia'], 'descripcion' => '<script>r()</script>']);
    afirmar($x['status'] === 201, 'alta con XSS');
    $h = $A->pedir('GET', '/inventario/recipientes?q=script', null, [], false);
    afirmar(!str_contains($h['body'], '<script>r()') && str_contains($h['body'], '&lt;script&gt;r()&lt;/script&gt;'), 'XSS en recipientes');
    // Filtros.
    $r = $A->pedir('GET', '/inventario/recipientes?esencia_id=' . $S['esencia'] . '&estado=vacio');
    afirmar($r['status'] === 200 && $r['json']['total'] === 2, 'filtro de estado: ' . $r['json']['total']);
    $r = $A->pedir('GET', '/inventario/recipientes?q=' . rawurlencode("' OR 1=1 -- "));
    afirmar($r['status'] === 200 && $r['json']['total'] === 0, 'SQLi en recipientes');
});

// ============================================================ paginación y filtros
caso('Listado: paginación (20 por página), filtros por tipo, estado, marca y categoría', function () use ($A, &$S) {
    $ins = db()->prepare("INSERT INTO articulos (tipo, sku, nombre, unidad_medida, categoria_id, activo) VALUES ('producto', ?, ?, 'und', ?, 1)");
    for ($i = 1; $i <= 45; $i++) {
        $ins->execute([sprintf('PAG-%03d', $i), sprintf('Paginacion %03d', $i), $i <= 10 ? $S['cat'] : null]);
    }
    $p = fn (string $q) => $A->pedir('GET', '/inventario/articulos?q=Paginacion' . $q);
    $p1 = $p('&pagina=1')['json'];
    $p2 = $p('&pagina=2')['json'];
    $p3 = $p('&pagina=3')['json'];
    afirmar($p1['total'] === 45 && $p1['paginas'] === 3 && $p1['por_pagina'] === 20 && count($p1['articulos']) === 20, 'página 1: ' . json_encode([$p1['total'], $p1['paginas'], count($p1['articulos'])]));
    afirmar(count($p2['articulos']) === 20 && count($p3['articulos']) === 5, 'páginas 2 y 3');
    $todos = array_merge(array_column($p1['articulos'], 'sku'), array_column($p2['articulos'], 'sku'), array_column($p3['articulos'], 'sku'));
    afirmar(count(array_unique($todos)) === 45 && $todos === array_values($todos) && $todos[0] === 'PAG-001' && $todos[44] === 'PAG-045', 'orden o repetidos entre páginas');
    afirmar($p('&pagina=4')['json']['articulos'] === [] && $p('&pagina=0')['json']['pagina'] === 1 && $p('&pagina=-3')['json']['pagina'] === 3, 'páginas fuera de rango');
    afirmar($p('&tipo=producto')['json']['total'] === 45 && $p('&tipo=esencia')['json']['total'] === 0, 'filtro por tipo');
    db()->exec("UPDATE articulos SET activo = 0 WHERE sku IN ('PAG-001','PAG-002','PAG-003')");
    afirmar($p('')['json']['total'] === 42 && $p('&activo=0')['json']['total'] === 3 && $p('&activo=todos')['json']['total'] === 45, 'filtro por estado');
    // Búsqueda por SKU, por nombre, por marca y por categoría.
    afirmar($A->pedir('GET', '/inventario/articulos?q=PAG-04')['json']['total'] === 6, 'búsqueda por SKU');
    $r = $A->pedir('GET', '/inventario/articulos?q=' . rawurlencode('Marca <b>Uno</b>'));
    afirmar($r['json']['total'] >= 2 && count(array_filter($r['json']['articulos'], fn ($a) => $a['marca'] !== 'Marca <b>Uno</b>' && $a['marca'] !== 'Marca Uno')) === 0, 'búsqueda por marca ' . $r['json']['total']);
    $r = $A->pedir('GET', '/inventario/articulos?q=' . rawurlencode('rabes') . '&activo=todos');
    afirmar($r['json']['total'] >= 10, 'búsqueda por categoría: ' . $r['json']['total']);
    // HTML: textos de paginación y enlaces que conservan los filtros.
    $h = $A->pedir('GET', '/inventario/articulos?q=Paginacion&pagina=2', null, [], false)['body'];
    afirmar(str_contains($h, 'Página 2 de 3') && str_contains($h, 'rel="prev"') && str_contains($h, 'rel="next"') && str_contains($h, 'q=Paginacion'), 'paginación HTML');
    afirmar(str_contains($h, '<label for="q">') && str_contains($h, '<caption'), 'etiquetas y caption');
});

// ===================================================================== formularios
caso('Formularios HTML accesibles: labels, errores por campo y valores conservados', function () use ($A, &$S) {
    $g = $A->pedir('GET', '/inventario/articulos/nuevo?tipo=producto', null, [], false);
    afirmar($g['status'] === 200, 'formulario nuevo: ' . $g['status']);
    foreach (['nombre', 'sku', 'tasa_iva', 'stock_minimo', 'categoria_id', 'marca_id', 'saldo_inicial', 'costo_unitario'] as $campo) {
        afirmar(str_contains($g['body'], '<label for="f-' . $campo . '">') && preg_match('/<(input|select|textarea) id="f-' . $campo . '"/', $g['body']), "campo $campo sin label");
    }
    afirmar(str_contains($g['body'], 'name="_csrf"'), 'formulario sin token CSRF');
    $h = $A->pedir('GET', '/inventario/articulos/nuevo', null, [], false);
    afirmar($h['status'] === 200 && str_contains($h['body'], 'tipo=esencia') && str_contains($h['body'], 'tipo=insumo'), 'selector de tipo');
    $e = $A->pedir('GET', '/inventario/articulos/nuevo?tipo=esencia', null, [], false)['body'];
    afirmar(!str_contains($e, 'id="f-saldo_inicial"') && str_contains($e, 'recipiente'), 'la esencia no ofrece saldo en el alta');
    // Error: se vuelve a mostrar el formulario con los valores y los errores junto al campo.
    $r = f1bPost($A, '/inventario/articulos', ['tipo' => 'producto', 'nombre' => '', 'sku' => 'ab', 'tasa_iva' => '250',
        'descripcion' => 'Texto "conservado" & más', 'contenido_ml' => '75']);
    afirmar($r['status'] === 422, 'estado ' . $r['status']);
    afirmar(str_contains($r['body'], 'id="err-nombre"') && str_contains($r['body'], 'aria-invalid="true" aria-describedby="err-nombre"'), 'error de nombre sin aria');
    afirmar(str_contains($r['body'], 'id="err-sku"') && str_contains($r['body'], 'id="err-tasa_iva"') && str_contains($r['body'], 'role="alert"'), 'errores por campo');
    afirmar(str_contains($r['body'], 'value="ab"') && str_contains($r['body'], 'value="250"') && str_contains($r['body'], 'value="75"')
        && str_contains($r['body'], 'Texto &quot;conservado&quot; &amp; más'), 'valores no conservados');
    // Alta HTML correcta: 303 al detalle.
    $r = f1bPost($A, '/inventario/articulos', ['tipo' => 'insumo', 'sku_auto' => '1', 'nombre' => 'Alcohol desde formulario', 'tipo_insumo' => 'alcohol', 'unidad_medida' => 'ml']);
    afirmar($r['status'] === 303 && preg_match('#/inventario/articulos/\d+\?m=creado$#', $r['headers']['location'][0] ?? ''), 'redirección: ' . $r['status']);
    $d = $A->pedir('GET', $r['headers']['location'][0], null, [], false);
    afirmar($d['status'] === 200 && str_contains($d['body'], 'Alcohol desde formulario') && str_contains($d['body'], 'role="status"'), 'detalle tras alta');
    // Edición: el formulario trae los valores actuales.
    $id = $S['prod_saldo'];
    $f = $A->pedir('GET', "/inventario/articulos/$id/editar", null, [], false)['body'];
    afirmar(str_contains($f, 'value="Producto editado"') && str_contains($f, 'Nueva descripción') && !str_contains($f, 'Pasar a'), 'formulario de edición (con historia)');
    $f = $A->pedir('GET', "/inventario/articulos/{$S['insumo']}/editar", null, [], false)['body'];
    afirmar(str_contains($f, 'Pasar a'), 'sin historia ofrece cambiar de tipo');
    // El formulario de recipiente.
    $f = $A->pedir('GET', '/inventario/recipientes/nuevo?esencia_id=' . $S['esencia'], null, [], false)['body'];
    afirmar(str_contains($f, '<label for="f-esencia_id">') && str_contains($f, 'value="' . $S['esencia'] . '" selected') && str_contains($f, 'f-gramos_iniciales'), 'formulario de recipiente');
    // Estático: ningún script ni estilo en línea (CSP).
    foreach ([$g['body'], $r['body'], $f] as $body) {
        afirmar(!preg_match('/<script(?![^>]*\bsrc=)/i', $body) && !preg_match('/\son[a-z]+=/i', $body) && !preg_match('/\sstyle=/i', $body), 'script/estilo en línea');
    }
});

// =============================================================== auditoría e invariantes
caso('Auditoría de 1B: crear/editar/desactivar con antes/después y sin costos ni secretos', function () use ($A, &$S) {
    $acc = db()->query("SELECT DISTINCT accion FROM auditoria")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['articulo.crear', 'articulo.editar', 'articulo.desactivar', 'articulo.activar', 'marca.crear', 'marca.editar', 'marca.desactivar',
        'categoria.crear', 'recipiente.crear', 'recipiente.editar', 'recipiente.baja', 'recipiente.reactivar'] as $a) {
        afirmar(in_array($a, $acc, true), "falta la acción $a");
    }
    $c = db()->query("SELECT despues, usuario_id, sucursal_id, entidad FROM auditoria WHERE accion = 'articulo.crear' AND entidad_id = {$S['prod_saldo']}")->fetch();
    $d = json_decode($c['despues'], true);
    afirmar($d['nombre'] !== '' && $d['saldo_inicial'] === '12.500' && $d['existencias_creadas'] === 2 && $c['entidad'] === 'articulos'
        && (int) $c['usuario_id'] === idUsuario('adm1b'), 'auditoría del alta ' . $c['despues']);
    afirmar(stripos($c['despues'], 'costo') === false && !str_contains($c['despues'], '18500'), 'la auditoría guarda costos');
    $todo = implode("\n", db()->query("SELECT IFNULL(antes,'') , IFNULL(despues,'') FROM auditoria WHERE accion LIKE 'articulo.%' OR accion LIKE 'recipiente.%'")->fetchAll(PDO::FETCH_COLUMN));
    afirmar(!preg_match('/password|token|argon2|secret/i', $todo), 'datos sensibles en auditoría 1B');
    $rc = db()->query("SELECT despues FROM auditoria WHERE accion = 'recipiente.crear' ORDER BY id LIMIT 1")->fetchColumn();
    afirmar(stripos($rc, 'costo') === false && str_contains($rc, 'saldo_inicial_g'), 'auditoría del recipiente: ' . $rc);
});

caso('Invariantes de inventario tras todo lo anterior (existencias, libro, esencias)', function () {
    $pdo = db();
    // Todo artículo creado por el CRUD tiene una fila de existencias por sucursal activa.
    $sin = (int) $pdo->query("SELECT COUNT(*) FROM articulos a WHERE a.sku NOT LIKE 'PAG-%' AND (SELECT COUNT(*) FROM existencias x JOIN sucursales s ON s.id = x.sucursal_id AND s.activa = 1 WHERE x.articulo_id = a.id) <> 2")->fetchColumn();
    afirmar($sin === 0, "$sin artículos sin existencias en las 2 sucursales activas");
    afirmar((int) $pdo->query('SELECT COUNT(*) FROM existencias WHERE sucursal_id = 3')->fetchColumn() === 0, 'existencias en la sucursal inactiva');
    // Libro = caché: por artículo y sucursal.
    $d = (int) $pdo->query("SELECT COUNT(*) FROM existencias x
        WHERE x.cantidad <> COALESCE((SELECT SUM(m.cantidad) FROM movimientos_inventario m WHERE m.articulo_id = x.articulo_id AND m.sucursal_id = x.sucursal_id AND m.tipo NOT IN ('reserva','liberacion_reserva')), 0)
          AND x.articulo_id NOT IN (SELECT articulo_id FROM (SELECT articulo_id FROM existencias WHERE cantidad_reservada > 0) t)
          AND x.articulo_id NOT IN (SELECT articulo_id FROM esencias)")->fetchColumn();
    afirmar($d === 0, "$d filas de existencias no cuadran con el libro");
    afirmar((int) $pdo->query('SELECT COUNT(*) FROM v_control_existencias_esencia WHERE descuadrado = 1')->fetchColumn() === 0, 'esencias descuadradas');
    $g = (int) $pdo->query('SELECT COUNT(*) FROM recipientes_esencia r WHERE r.gramos_actuales <> COALESCE((SELECT SUM(m.gramos) FROM movimientos_esencia m WHERE m.recipiente_id = r.id), 0)')->fetchColumn();
    afirmar($g === 0, "$g recipientes no cuadran con su libro");
    // Los artículos sin código de barras existen y la vista de control los reporta (1C los resolverá).
    $v = (int) $pdo->query('SELECT COUNT(*) FROM v_control_articulos WHERE sin_codigo_principal = 1')->fetchColumn();
    afirmar($v === (int) $pdo->query('SELECT COUNT(*) FROM articulos')->fetchColumn() && (int) $pdo->query('SELECT COUNT(*) FROM articulos WHERE codigo_barras IS NOT NULL')->fetchColumn() === 0,
        'los artículos deben poder existir sin código de barras y verse en v_control_articulos');
    return 'v_control_articulos reporta ' . $v . ' sin código';
});

caso('Lista de referencias de ArticuloService al día con las FKs reales (detecta tablas nuevas)', function () {
    $filas = db()->query("SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME r FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = '" . BD . "' AND REFERENCED_TABLE_NAME IN ('articulos','esencias') AND REFERENCED_COLUMN_NAME = 'id'")->fetchAll();
    $faltan = [];
    foreach ($filas as $f) {
        $lista = $f['r'] === 'articulos' ? ArticuloService::REFERENCIAS_ARTICULO : ArticuloService::REFERENCIAS_ESENCIA;
        $ok = (($lista[$f['t']] ?? null) === $f['c']) || ($f['r'] === 'articulos' && in_array($f['t'], ArticuloService::REFERENCIAS_NEUTRAS, true));
        if (!$ok) {
            $faltan[] = "{$f['t']}.{$f['c']} -> {$f['r']}";
        }
    }
    afirmar($faltan === [], 'FKs no contempladas en ArticuloService::REFERENCIAS_*: ' . implode(', ', $faltan));
    // Y cada tabla de la lista existe de verdad.
    foreach (array_merge(array_keys(ArticuloService::REFERENCIAS_ARTICULO), array_keys(ArticuloService::REFERENCIAS_ESENCIA)) as $t) {
        afirmar((int) db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . BD . "' AND TABLE_NAME = '$t'")->fetchColumn() === 1, "tabla $t inexistente");
    }
    return count($filas) . ' FKs revisadas';
});

caso('Decimal: valida cadenas sin float; rechaza negativos, exponentes y separadores de miles', function () {
    $ok = [['12', 9, 3, '12.000'], ['0012.5', 9, 3, '12.500'], ['12,5', 9, 3, '12.500'], ['0', 9, 3, '0.000'], ['.5', 9, 3, null],
        ['999999999.999', 9, 3, '999999999.999'], ['1000000000', 9, 3, null], ['1.2345', 9, 3, null], ['-1', 9, 3, null], ['+1', 9, 3, null],
        ['1e3', 9, 3, null], ['1.2.3', 9, 3, null], ['1,2,3', 9, 3, null], ['1 000', 9, 3, null], ['', 9, 3, null], [' 7 ', 9, 3, '7.000'],
        ["5\n", 9, 3, '5.000'], ['0.1', 8, 6, '0.100000'], ['99999999.999999', 8, 6, '99999999.999999'], ['NaN', 9, 3, null], ['١٢', 9, 3, null]];
    foreach ($ok as [$in, $e, $d, $esp]) {
        afirmar(Decimal::parsear($in, $e, $d) === $esp, 'parsear(' . json_encode($in) . ") dio " . json_encode(Decimal::parsear($in, $e, $d)));
    }
    afirmar(Decimal::comparar('10.000', '9.999') === 1 && Decimal::comparar('9.5', '9.50') === 0 && Decimal::comparar('0.001', '0.01') === -1
        && Decimal::comparar('100', '99') === 1 && Decimal::comparar('123456789012', '123456789011') === 1, 'comparar');
    afirmar(Decimal::esCero('0.000') && !Decimal::esCero('0.001') && Decimal::mostrar('12.500000') === '12.5' && Decimal::mostrar('3.000') === '3' && Decimal::mostrar('100') === '100', 'esCero/mostrar');
});
