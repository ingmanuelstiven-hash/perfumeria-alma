<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Recipientes de esencia';
require __DIR__ . '/_cabecera.php';
$res = $d['res'];
$f = $res['filtros'];
$enlace = static fn (int $p): string => '?' . http_build_query(['q' => $f['q'], 'esencia_id' => $f['esencia_id'], 'estado' => $f['estado'], 'pagina' => $p]);
?>
<section class="tarjeta ancha">
  <h1>Recipientes de esencia</h1>
  <?php if (!empty($d['mensaje'])): ?><p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p><?php endif; ?>
  <?php if (!empty($d['error'])): ?><div class="alerta" role="alert"><p><?= $v->e($d['error']) ?></p></div><?php endif; ?>

  <form method="get" action="<?= $v->url('/inventario/recipientes') ?>" class="filtros" role="search" aria-label="Filtrar recipientes">
    <div>
      <label for="q">Buscar (código, descripción o esencia)</label>
      <input id="q" name="q" maxlength="100" value="<?= $v->e($f['q']) ?>">
    </div>
    <div>
      <label for="esencia_id">Esencia</label>
      <select id="esencia_id" name="esencia_id">
        <option value="">Todas</option>
        <?php foreach ($d['esencias'] as $e): ?>
          <option value="<?= (int) $e['id'] ?>"<?= $f['esencia_id'] === (string) $e['id'] ? ' selected' : '' ?>><?= $v->e($e['sku'] . ' · ' . $e['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="estado">Estado</label>
      <select id="estado" name="estado">
        <option value="">Todos</option>
        <?php foreach (['activo' => 'Activo', 'vacio' => 'Vacío', 'baja' => 'De baja'] as $k => $n): ?>
          <option value="<?= $v->e($k) ?>"<?= $f['estado'] === $k ? ' selected' : '' ?>><?= $v->e($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button type="submit">Filtrar</button></div>
  </form>

  <div class="enlaces">
    <?php if ($d['puede_gestionar']): ?><a href="<?= $v->url('/inventario/recipientes/nuevo') ?>">Nuevo recipiente</a><?php endif; ?>
    <a href="<?= $v->url('/inventario/articulos') ?>">Volver a artículos</a>
  </div>

  <p class="nota" role="status"><?= (int) $res['total'] ?> recipiente(s). Página <?= (int) $res['pagina'] ?> de <?= (int) $res['paginas'] ?>.</p>
  <table>
    <caption class="oculto">Recipientes de esencia</caption>
    <thead><tr>
      <th scope="col">Código</th><th scope="col">Esencia</th><th scope="col">Descripción</th><th scope="col">Sucursal</th>
      <th scope="col" class="num">Gramos</th><th scope="col" class="num">Capacidad (g)</th><th scope="col">Estado</th>
      <?php if ($d['puede_gestionar']): ?><th scope="col">Acciones</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($res['filas'] as $r): ?>
      <tr<?= $r['estado'] === 'baja' ? ' class="inactivo"' : '' ?>>
        <td><?= $v->e($r['codigo']) ?></td>
        <td><a href="<?= $v->url('/inventario/articulos/' . (int) $r['articulo_id']) ?>"><?= $v->e($r['sku'] . ' · ' . $r['esencia']) ?></a></td>
        <td><?= $v->e($r['descripcion'] ?? '') ?></td>
        <td><?= $v->e($r['sucursal']) ?></td>
        <td class="num"><?= $v->e(\App\Support\Decimal::mostrar((string) ($r['gramos_actuales'] ?? ''))) ?></td>
        <td class="num"><?= $v->e(\App\Support\Decimal::mostrar((string) ($r['capacidad_g'] ?? ''))) ?></td>
        <td><?= $v->e($r['estado']) ?></td>
        <?php if ($d['puede_gestionar']): ?>
        <td>
          <a href="<?= $v->url('/inventario/recipientes/' . (int) $r['id'] . '/editar') ?>">Editar</a>
          <form method="post" action="<?= $v->url('/inventario/recipientes/' . (int) $r['id'] . '/activo') ?>" class="en-linea">
            <?= $v->campoCsrf() ?>
            <input type="hidden" name="activo" value="<?= $r['estado'] === 'baja' ? '1' : '0' ?>">
            <button type="submit"><?= $r['estado'] === 'baja' ? 'Reactivar' : 'Dar de baja' ?></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$res['filas']): ?><tr><td colspan="8">No hay recipientes con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <nav class="paginacion" aria-label="Paginación">
    <?php if ($res['pagina'] > 1): ?><a href="<?= $v->e($enlace($res['pagina'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
    <?php if ($res['pagina'] < $res['paginas']): ?><a href="<?= $v->e($enlace($res['pagina'] + 1)) ?>" rel="next">Siguiente</a><?php endif; ?>
  </nav>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
