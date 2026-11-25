<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Marcas';
require __DIR__ . '/_cabecera.php';
require __DIR__ . '/_helpers_form.php';
?>
<section class="tarjeta">
  <h1>Marcas</h1>
  <?php if (!empty($d['mensaje'])): ?><p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p><?php endif; ?>
  <?php $resumenErrores(); ?>
  <table>
    <caption class="oculto">Marcas</caption>
    <thead><tr><th scope="col">Nombre</th><th scope="col" class="num">Artículos</th><th scope="col">Estado</th><?php if ($d['puede_gestionar']): ?><th scope="col">Acciones</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($d['marcas'] as $m): ?>
      <tr<?= (int) $m['activa'] === 1 ? '' : ' class="inactivo"' ?>>
        <td><?= $v->e($m['nombre']) ?></td>
        <td class="num"><?= (int) $m['articulos'] ?></td>
        <td><?= (int) $m['activa'] === 1 ? 'Activa' : 'Inactiva' ?></td>
        <?php if ($d['puede_gestionar']): ?>
        <td>
          <form method="post" action="<?= $v->url('/admin/marcas/' . (int) $m['id']) ?>" class="en-linea">
            <?= $v->campoCsrf() ?>
            <label class="oculto" for="mn-<?= (int) $m['id'] ?>">Nuevo nombre de <?= $v->e($m['nombre']) ?></label>
            <input id="mn-<?= (int) $m['id'] ?>" name="nombre" required maxlength="80" value="<?= $v->e($m['nombre']) ?>">
            <button type="submit">Renombrar</button>
          </form>
          <form method="post" action="<?= $v->url('/admin/marcas/' . (int) $m['id'] . '/activo') ?>" class="en-linea">
            <?= $v->campoCsrf() ?>
            <input type="hidden" name="activo" value="<?= (int) $m['activa'] === 1 ? '0' : '1' ?>">
            <button type="submit"><?= (int) $m['activa'] === 1 ? 'Desactivar' : 'Reactivar' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$d['marcas']): ?><tr><td colspan="4">Aún no hay marcas.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p><a href="<?= $v->url('/inventario/articulos') ?>">Volver a artículos</a></p>
</section>

<?php if ($d['puede_gestionar']): ?>
<section class="tarjeta estrecha">
  <h2>Nueva marca</h2>
  <form method="post" action="<?= $v->url('/admin/marcas') ?>" novalidate>
    <?= $v->campoCsrf() ?>
    <?php $input('nombre', 'Nombre', 'required maxlength="80"'); ?>
    <button type="submit">Crear marca</button>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/_pie.php'; ?>
