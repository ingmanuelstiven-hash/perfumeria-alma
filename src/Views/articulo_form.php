<?php
/** @var array $d @var \App\View $v */
$editar = $d['modo'] === 'editar';
$tipo = $d['tipo'];
$etiquetaTipo = ['esencia' => 'esencia', 'producto' => 'producto', 'envase' => 'envase', 'insumo' => 'insumo'];
$d['titulo'] = $editar ? 'Editar artículo' : 'Nuevo artículo';
require __DIR__ . '/_cabecera.php';
require __DIR__ . '/_helpers_form.php';
?>
<section class="tarjeta">
  <h1><?= $editar ? 'Editar artículo' . ($d['a'] ? ' ' . $v->e($d['a']['sku']) : '') : 'Nuevo artículo' ?></h1>

  <?php if ($tipo === ''): ?>
    <p>Elija el tipo de artículo:</p>
    <ul>
      <?php foreach ($etiquetaTipo as $k => $n): ?>
        <li><a href="<?= $v->url('/inventario/articulos/nuevo?tipo=' . $k) ?>">Nueva <?= $v->e($n) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <p><a href="<?= $v->url('/inventario/articulos') ?>">Volver al listado</a></p>
  <?php else: ?>
  <?php $resumenErrores(); ?>
  <p class="nota">Tipo: <strong><?= $v->e($tipo) ?></strong>.
    <?php if ($editar && $d['sin_historia']): ?>
      Este artículo no tiene movimientos: puede cambiar el tipo.
      <?php foreach ($etiquetaTipo as $k => $n): if ($k !== $tipo): ?>
        <a href="<?= $v->url('/inventario/articulos/' . (int) $d['a']['id'] . '/editar?tipo=' . $k) ?>">Pasar a <?= $v->e($n) ?></a>
      <?php endif; endforeach; ?>
    <?php elseif ($editar): ?>
      Con movimientos o documentos asociados no se puede cambiar el tipo, el SKU ni la unidad de medida.
    <?php endif; ?>
  </p>
  <form method="post" action="<?= $v->url($editar ? '/inventario/articulos/' . (int) $d['a']['id'] : '/inventario/articulos') ?>" novalidate>
    <?= $v->campoCsrf() ?>
    <input type="hidden" name="tipo" value="<?= $v->e($tipo) ?>">

    <?php $sinCambio = $editar && !$d['sin_historia']; ?>
    <?php if (!$editar): ?>
      <label class="casilla"><input type="checkbox" name="sku_auto" value="1" id="f-sku_auto"<?= $val('sku_auto', empty($d['form']) ? '1' : '') !== '' ? ' checked' : '' ?>>
        Generar el SKU automáticamente (si se marca, se ignora el campo SKU)</label>
    <?php endif; ?>
    <?php $input('sku', 'SKU (mayúsculas, dígitos y guion; 3 a 40)', 'maxlength="40" autocomplete="off"' . ($sinCambio ? ' readonly' : '')); ?>

    <?php $input('nombre', 'Nombre', 'required maxlength="150"'); ?>
    <label for="f-descripcion">Descripción (opcional)</label>
    <textarea id="f-descripcion" name="descripcion" maxlength="1000"<?= $ariaCampo('descripcion') ?>><?= $v->e($val('descripcion')) ?></textarea>
    <?= $msgCampo('descripcion') ?>

    <?php
    $opcCat = [['', '(sin categoría)']];
    foreach ($d['categorias'] as $c) {
        $opcCat[] = [(string) $c['id'], ($c['padre'] ? $c['padre'] . ' › ' : '') . $c['nombre']];
    }
    $select('categoria_id', 'Categoría', $opcCat, $val('categoria_id'));
    ?>

    <?php if (count($d['unidades']) > 1): ?>
      <?php $select('unidad_medida', 'Unidad de medida', array_map(static fn ($u) => [$u, $u], $d['unidades']), $val('unidad_medida'), $sinCambio ? 'disabled' : ''); ?>
      <?php if ($sinCambio): ?><input type="hidden" name="unidad_medida" value="<?= $v->e($val('unidad_medida')) ?>"><?php endif; ?>
    <?php else: ?>
      <p class="nota">Unidad de medida: <strong><?= $v->e($d['unidades'][0]) ?></strong> (fija para este tipo).</p>
    <?php endif; ?>

    <?php $input('tasa_iva', 'IVA (%)', 'inputmode="decimal" maxlength="6"', 'text', $val('tasa_iva', '19.00')); ?>
    <?php $input('stock_minimo', 'Stock mínimo (en la unidad de medida)', 'inputmode="decimal" maxlength="13"', 'text', $val('stock_minimo', '0')); ?>

    <?php
    $opcMarca = [['', '(sin marca)']];
    foreach ($d['marcas'] as $m) {
        $opcMarca[] = [(string) $m['id'], $m['nombre']];
    }
    ?>
    <?php if ($tipo === 'esencia'): ?>
      <fieldset><legend>Datos de la esencia</legend>
        <?php $input('inspiracion', 'Perfume de inspiración', 'maxlength="150"'); ?>
        <?php $select('marca_id', 'Marca del perfume de referencia', $opcMarca, $val('marca_id')); ?>
        <?php $select('genero', 'Género', [['unisex', 'Unisex'], ['femenino', 'Femenino'], ['masculino', 'Masculino']], $val('genero', 'unisex')); ?>
        <?php $input('familia_olfativa', 'Familia olfativa', 'maxlength="60"'); ?>
        <?php $input('densidad_g_ml', 'Densidad (g/ml)', 'inputmode="decimal" maxlength="7"', 'text', $val('densidad_g_ml', '1.0000')); ?>
      </fieldset>
    <?php elseif ($tipo === 'producto'): ?>
      <fieldset><legend>Datos del producto</legend>
        <?php $select('marca_id', 'Marca', $opcMarca, $val('marca_id')); ?>
        <?php $input('contenido_ml', 'Contenido (ml, opcional)', 'inputmode="decimal" maxlength="9"'); ?>
      </fieldset>
    <?php elseif ($tipo === 'envase'): ?>
      <fieldset><legend>Datos del envase vacío</legend>
        <?php $input('capacidad_ml', 'Capacidad (ml)', 'required inputmode="decimal" maxlength="9"'); ?>
        <?php $select('material', 'Material', [['vidrio', 'Vidrio'], ['plastico', 'Plástico'], ['aluminio', 'Aluminio'], ['otro', 'Otro']], $val('material', 'vidrio')); ?>
        <?php $select('tipo_cierre', 'Tipo de cierre', [['atomizador', 'Atomizador'], ['roll_on', 'Roll-on'], ['tapa', 'Tapa'], ['gotero', 'Gotero'], ['otro', 'Otro']], $val('tipo_cierre', 'atomizador')); ?>
        <?php $input('color', 'Color', 'maxlength="40"'); ?>
      </fieldset>
    <?php else: ?>
      <fieldset><legend>Datos del insumo</legend>
        <?php $select('tipo_insumo', 'Tipo de insumo', [['', '(elija)'], ['alcohol', 'Alcohol'], ['base', 'Base'], ['fijador', 'Fijador'], ['caja', 'Caja'], ['bolsa', 'Bolsa'], ['etiqueta', 'Etiqueta'], ['otro', 'Otro']], $val('tipo_insumo'), 'required'); ?>
      </fieldset>
    <?php endif; ?>

    <?php if (!$editar && $tipo !== 'esencia' && $d['puede_saldo']): ?>
      <fieldset><legend>Saldo inicial (opcional)</legend>
        <p class="nota">Se registra como un movimiento de saldo inicial en el libro de inventario, en su sucursal.</p>
        <?php $input('saldo_inicial', 'Cantidad inicial', 'inputmode="decimal" maxlength="13"'); ?>
        <?php $input('costo_unitario', 'Costo por unidad (obligatorio si hay saldo)', 'inputmode="decimal" maxlength="15"'); ?>
      </fieldset>
    <?php elseif (!$editar && $tipo === 'esencia'): ?>
      <p class="nota">El saldo inicial de una esencia se registra al crear su recipiente (gramos).</p>
    <?php endif; ?>

    <button type="submit"><?= $editar ? 'Guardar cambios' : 'Crear artículo' ?></button>
    <a href="<?= $v->url($editar ? '/inventario/articulos/' . (int) $d['a']['id'] : '/inventario/articulos') ?>">Cancelar</a>
  </form>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
