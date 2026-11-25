<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Categorías';
require __DIR__ . '/_cabecera.php';
require __DIR__ . '/_helpers_form.php';
$opcTipo = array_map(static fn ($t) => [$t, $t], $d['tipos']);
$opcPadre = [['', '(raíz)']];
foreach ($d['categorias'] as $c) {
    if ((int) $c['activa'] === 1) {
        $opcPadre[] = [(string) $c['id'], $c['nombre']];
    }
}
?>
<section class="tarjeta ancha">
  <h1>Categorías</h1>
  <?php if (!empty($d['mensaje'])): ?><p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p><?php endif; ?>
  <?php $resumenErrores(); ?>
  <table>
    <caption class="oculto">Categorías</caption>
    <thead><tr><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Padre</th><th scope="col" class="num">Artículos</th><th scope="col">Estado</th><?php if ($d['puede_gestionar']): ?><th scope="col">Acciones</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($d['categorias'] as $c): ?>
      <tr<?= (int) $c['activa'] === 1 ? '' : ' class="inactivo"' ?>>
        <td><?= $v->e($c['nombre']) ?> <span class="nota">(<?= $v->e($c['slug']) ?>)</span></td>
        <td><?= $v->e($c['tipo']) ?></td>
        <td><?= $v->e($c['padre'] ?? '') ?></td>
        <td class="num"><?= (int) $c['articulos'] ?></td>
        <td><?= (int) $c['activa'] === 1 ? 'Activa' : 'Inactiva' ?></td>
        <?php if ($d['puede_gestionar']): ?>
        <td>
          <form method="post" action="<?= $v->url('/admin/categorias/' . (int) $c['id']) ?>" class="en-linea">
            <?= $v->campoCsrf() ?>
            <label class="oculto" for="cn-<?= (int) $c['id'] ?>">Nombre de <?= $v->e($c['nombre']) ?></label>
            <input id="cn-<?= (int) $c['id'] ?>" name="nombre" required maxlength="80" value="<?= $v->e($c['nombre']) ?>">
            <label class="oculto" for="ct-<?= (int) $c['id'] ?>">Tipo de <?= $v->e($c['nombre']) ?></label>
            <select id="ct-<?= (int) $c['id'] ?>" name="tipo">
              <?php foreach ($d['tipos'] as $t): ?><option value="<?= $v->e($t) ?>"<?= $t === $c['tipo'] ? ' selected' : '' ?>><?= $v->e($t) ?></option><?php endforeach; ?>
            </select>
            <label class="oculto" for="cp-<?= (int) $c['id'] ?>">Categoría padre de <?= $v->e($c['nombre']) ?></label>
            <select id="cp-<?= (int) $c['id'] ?>" name="padre_id">
              <?php foreach ($opcPadre as [$pv, $pn]): if ($pv === (string) $c['id']) { continue; } ?>
                <option value="<?= $v->e($pv) ?>"<?= $pv === (string) ($c['padre_id'] ?? '') ? ' selected' : '' ?>><?= $v->e($pn) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="oculto" for="co-<?= (int) $c['id'] ?>">Orden de <?= $v->e($c['nombre']) ?></label>
            <input id="co-<?= (int) $c['id'] ?>" name="orden" inputmode="numeric" maxlength="5" size="5" value="<?= (int) $c['orden'] ?>">
            <button type="submit">Guardar</button>
          </form>
          <form method="post" action="<?= $v->url('/admin/categorias/' . (int) $c['id'] . '/activo') ?>" class="en-linea">
            <?= $v->campoCsrf() ?>
            <input type="hidden" name="activo" value="<?= (int) $c['activa'] === 1 ? '0' : '1' ?>">
            <button type="submit"><?= (int) $c['activa'] === 1 ? 'Desactivar' : 'Reactivar' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$d['categorias']): ?><tr><td colspan="6">Aún no hay categorías.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p><a href="<?= $v->url('/inventario/articulos') ?>">Volver a artículos</a></p>
</section>

<?php if ($d['puede_gestionar']): ?>
<section class="tarjeta estrecha">
  <h2>Nueva categoría</h2>
  <form method="post" action="<?= $v->url('/admin/categorias') ?>" novalidate>
    <?= $v->campoCsrf() ?>
    <?php $input('nombre', 'Nombre', 'required maxlength="80"'); ?>
    <?php $select('tipo', 'Tipo de artículos', $opcTipo, $val('tipo', 'general')); ?>
    <?php $select('padre_id', 'Categoría padre', $opcPadre, $val('padre_id')); ?>
    <?php $input('orden', 'Orden', 'inputmode="numeric" maxlength="5"', 'text', $val('orden', '0')); ?>
    <button type="submit">Crear categoría</button>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/_pie.php'; ?>
