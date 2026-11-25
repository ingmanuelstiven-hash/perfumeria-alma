<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Panel';
$u = $d['usuario'];
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta">
  <h1>Hola, <?= $v->e($u['nombre']) ?></h1>
  <?php if (!empty($d['mensaje'])): ?>
    <p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p>
  <?php endif; ?>
  <dl>
    <dt>Usuario</dt><dd><?= $v->e($u['usuario']) ?></dd>
    <dt>Rol</dt><dd><?= $v->e($u['rol_nombre']) ?> (<?= $v->e($u['rol']) ?>)</dd>
    <dt>Sucursal</dt><dd><?= $v->e($u['sucursal']) ?></dd>
  </dl>
  <?php if (in_array('usuarios.ver', $d['permisos'], true)): ?>
    <p><a href="<?= $v->url('/admin/usuarios') ?>">Gestionar usuarios</a></p>
  <?php endif; ?>
  <?php if (in_array('articulos.ver', $d['permisos'], true)): ?>
    <p><a href="<?= $v->url('/inventario/articulos') ?>">Catálogo de artículos</a>
      <?php if (in_array('inventario.ver', $d['permisos'], true)): ?> · <a href="<?= $v->url('/inventario/recipientes') ?>">Recipientes de esencia</a><?php endif; ?>
      · <a href="<?= $v->url('/admin/marcas') ?>">Marcas</a> · <a href="<?= $v->url('/admin/categorias') ?>">Categorías</a></p>
  <?php endif; ?>
  <h2>Permisos de su rol (<?= count($d['permisos']) ?>)</h2>
  <ul class="permisos">
    <?php foreach ($d['permisos'] as $p): ?>
      <li><code><?= $v->e($p) ?></code></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
