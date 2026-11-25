<?php
/** @var array $d @var \App\View $v */
$a = $d['a'];
$d['titulo'] = $a['sku'] . ' · ' . $a['nombre'];
require __DIR__ . '/_cabecera.php';
$mostrar = static fn ($x): string => \App\Support\Decimal::mostrar((string) $x);
$etiquetaTipo = ['esencia' => 'Esencia', 'producto' => 'Producto', 'envase' => 'Envase', 'insumo' => 'Insumo'];
$campos = [
    'inspiracion' => 'Inspiración', 'genero' => 'Género', 'familia_olfativa' => 'Familia olfativa', 'densidad_g_ml' => 'Densidad (g/ml)',
    'contenido_ml' => 'Contenido (ml)', 'capacidad_ml' => 'Capacidad (ml)', 'material' => 'Material', 'tipo_cierre' => 'Cierre',
    'color' => 'Color', 'tipo_insumo' => 'Tipo de insumo',
];
?>
<section class="tarjeta ancha">
  <h1><?= $v->e($a['nombre']) ?></h1>
  <?php if (!empty($d['mensaje'])): ?><p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p><?php endif; ?>
  <?php if (!empty($d['error'])): ?>
    <div class="alerta" role="alert"><p><?= $v->e($d['error']) ?></p></div>
  <?php endif; ?>
  <dl>
    <dt>SKU</dt><dd><?= $v->e($a['sku']) ?></dd>
    <dt>Tipo</dt><dd><?= $v->e($etiquetaTipo[$a['tipo']] ?? $a['tipo']) ?></dd>
    <dt>Estado</dt><dd><?= $a['activo'] ? 'Activo' : 'Inactivo' ?></dd>
    <?php if (!empty($a['descripcion'])): ?><dt>Descripción</dt><dd><?= nl2br($v->e($a['descripcion'])) ?></dd><?php endif; ?>
    <dt>Categoría</dt><dd><?= $v->e($a['categoria'] ?? '-') ?></dd>
    <?php if ($a['tipo'] === 'esencia' || $a['tipo'] === 'producto'): ?><dt>Marca</dt><dd><?= $v->e($a['marca'] ?? '-') ?></dd><?php endif; ?>
    <dt>Unidad de medida</dt><dd><?= $v->e($a['unidad_medida']) ?></dd>
    <dt>IVA (%)</dt><dd><?= $v->e($mostrar($a['tasa_iva'])) ?></dd>
    <dt>Stock mínimo</dt><dd><?= $v->e($mostrar($a['stock_minimo'])) ?></dd>
    <?php foreach ($campos as $k => $etq): if (isset($a[$k])): ?>
      <dt><?= $v->e($etq) ?></dt><dd><?= $v->e($mostrar($a[$k])) ?></dd>
    <?php endif; endforeach; ?>
  </dl>

  <?php if (isset($a['existencias'])): ?>
  <h2>Existencias por sucursal</h2>
  <table>
    <caption class="oculto">Existencias por sucursal</caption>
    <thead><tr>
      <th scope="col">Sucursal</th>
      <?php if ($d['ver_inventario']): ?><th scope="col" class="num">Cantidad</th><th scope="col" class="num">Reservada</th><?php endif; ?>
      <?php if ($d['ver_costos']): ?><th scope="col" class="num">Costo promedio</th><th scope="col" class="num">Último costo</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($a['existencias'] as $x): ?>
      <tr>
        <td><?= $v->e($x['sucursal']) ?></td>
        <?php if ($d['ver_inventario']): ?><td class="num"><?= $v->e($mostrar($x['cantidad'])) ?></td><td class="num"><?= $v->e($mostrar($x['cantidad_reservada'])) ?></td><?php endif; ?>
        <?php if ($d['ver_costos']): ?><td class="num"><?= $v->e($mostrar($x['costo_promedio'])) ?></td><td class="num"><?= $x['ultimo_costo'] === null ? '-' : $v->e($mostrar($x['ultimo_costo'])) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($d['recipientes'] !== null): ?>
  <h2>Recipientes</h2>
  <?php if ($d['puede_gestionar']): ?><p><a href="<?= $v->url('/inventario/recipientes/nuevo?esencia_id=' . (int) $a['id']) ?>">Nuevo recipiente de esta esencia</a></p><?php endif; ?>
  <table>
    <caption class="oculto">Recipientes de la esencia</caption>
    <thead><tr><th scope="col">Código</th><th scope="col">Descripción</th><th scope="col" class="num">Gramos</th><th scope="col">Estado</th></tr></thead>
    <tbody>
    <?php foreach ($d['recipientes'] as $r): ?>
      <tr><td><?= $v->e($r['codigo']) ?></td><td><?= $v->e($r['descripcion'] ?? '') ?></td>
        <td class="num"><?= $v->e($mostrar($r['gramos_actuales'] ?? '')) ?></td><td><?= $v->e($r['estado']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$d['recipientes']): ?><tr><td colspan="4">Sin recipientes.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="enlaces">
    <a href="<?= $v->url('/inventario/articulos') ?>">Volver al listado</a>
    <?php if ($d['puede_gestionar']): ?>
      <a href="<?= $v->url('/inventario/articulos/' . (int) $a['id'] . '/editar') ?>">Editar</a>
      <form method="post" action="<?= $v->url('/inventario/articulos/' . (int) $a['id'] . '/activo') ?>" class="en-linea">
        <?= $v->campoCsrf() ?>
        <input type="hidden" name="activo" value="<?= $a['activo'] ? '0' : '1' ?>">
        <button type="submit"><?= $a['activo'] ? 'Desactivar' : 'Reactivar' ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
