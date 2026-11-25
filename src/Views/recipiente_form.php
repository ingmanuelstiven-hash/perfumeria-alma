<?php
/** @var array $d @var \App\View $v */
$editar = $d['modo'] === 'editar';
$d['titulo'] = $editar ? 'Editar recipiente' : 'Nuevo recipiente';
require __DIR__ . '/_cabecera.php';
require __DIR__ . '/_helpers_form.php';
$opcEsencia = [['', '(elija la esencia)']];
foreach ($d['esencias'] as $e) {
    $opcEsencia[] = [(string) $e['id'], $e['sku'] . ' · ' . $e['nombre']];
}
$opcSucursal = array_map(static fn ($s) => [(string) $s['id'], $s['nombre']], $d['sucursales']);
?>
<section class="tarjeta">
  <h1><?= $editar ? 'Editar recipiente ' . $v->e($d['r']['codigo']) : 'Nuevo recipiente de esencia' ?></h1>
  <?php $resumenErrores(); ?>
  <form method="post" action="<?= $v->url($editar ? '/inventario/recipientes/' . (int) $d['r']['id'] : '/inventario/recipientes') ?>" novalidate>
    <?= $v->campoCsrf() ?>
    <?php if (!$editar): ?>
      <?php $select('esencia_id', 'Esencia', $opcEsencia, $val('esencia_id'), 'required'); ?>
      <?php $select('sucursal_id', 'Sucursal', $opcSucursal, $val('sucursal_id', (string) $d['usuario']['sucursal_id'])); ?>
      <?php $input('codigo', 'Código de la etiqueta (vacío = se genera R-000001...)', 'maxlength="40" autocomplete="off"'); ?>
    <?php else: ?>
      <p class="nota">Esencia: <strong><?= $v->e($d['r']['sku'] . ' · ' . $d['r']['esencia']) ?></strong>. El código y el saldo no se editan aquí.</p>
    <?php endif; ?>
    <?php $input('descripcion', 'Descripción (opcional)', 'maxlength="100"'); ?>
    <?php $input('capacidad_g', 'Capacidad (g, opcional)', 'inputmode="decimal" maxlength="11"'); ?>
    <?php $input('tara_g', 'Tara: peso del recipiente vacío (g, opcional)', 'inputmode="decimal" maxlength="11"'); ?>
    <?php $input('ubicacion', 'Ubicación (opcional)', 'maxlength="60"'); ?>

    <?php if (!$editar && $d['puede_saldo']): ?>
      <fieldset><legend>Saldo inicial (opcional)</legend>
        <p class="nota">Se registra como un movimiento de saldo inicial en gramos y actualiza las existencias de la esencia.</p>
        <?php $input('gramos_iniciales', 'Gramos iniciales', 'inputmode="decimal" maxlength="11"'); ?>
        <?php $input('costo_g', 'Costo por gramo (obligatorio si hay saldo)', 'inputmode="decimal" maxlength="15"'); ?>
      </fieldset>
    <?php endif; ?>
    <button type="submit"><?= $editar ? 'Guardar cambios' : 'Crear recipiente' ?></button>
    <a href="<?= $v->url('/inventario/recipientes') ?>">Cancelar</a>
  </form>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
