<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Artículos';
require __DIR__ . '/_cabecera.php';
$res = $d['res'];
$f = $res['filtros'];
$etiquetaTipo = ['esencia' => 'Esencia', 'producto' => 'Producto', 'envase' => 'Envase', 'insumo' => 'Insumo'];
$enlace = static fn (int $p): string => '?' . http_build_query(['q' => $f['q'], 'tipo' => $f['tipo'], 'activo' => $f['activo'], 'pagina' => $p]);
?>
<section class="tarjeta ancha">
  <h1>Artículos de inventario</h1>
  <?php if (!empty($d['mensaje'])): ?><p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p><?php endif; ?>

  <form method="get" action="<?= $v->url('/inventario/articulos') ?>" class="filtros" role="search" aria-label="Filtrar artículos">
    <div>
      <label for="q">Buscar (SKU, nombre, marca o categoría)</label>
      <input id="q" name="q" maxlength="100" value="<?= $v->e($f['q']) ?>">
    </div>
    <div>
      <label for="tipo">Tipo</label>
      <select id="tipo" name="tipo">
        <option value="">Todos</option>
        <?php foreach ($etiquetaTipo as $k => $n): ?>
          <option value="<?= $v->e($k) ?>"<?= $f['tipo'] === $k ? ' selected' : '' ?>><?= $v->e($n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="activo">Estado</label>
      <select id="activo" name="activo">
        <option value="1"<?= $f['activo'] === '1' ? ' selected' : '' ?>>Activos</option>
        <option value="0"<?= $f['activo'] === '0' ? ' selected' : '' ?>>Inactivos</option>
        <option value="todos"<?= $f['activo'] === 'todos' ? ' selected' : '' ?>>Todos</option>
      </select>
    </div>
    <div><button type="submit">Filtrar</button></div>
  </form>

  <div class="enlaces">
    <?php if ($d['puede_gestionar']): ?><a href="<?= $v->url('/inventario/articulos/nuevo') ?>">Nuevo artículo</a><?php endif; ?>
    <?php if ($d['ver_inventario']): ?><a href="<?= $v->url('/inventario/recipientes') ?>">Recipientes de esencia</a><?php endif; ?>
    <a href="<?= $v->url('/admin/marcas') ?>">Marcas</a>
    <a href="<?= $v->url('/admin/categorias') ?>">Categorías</a>
  </div>

  <p class="nota" role="status"><?= (int) $res['total'] ?> artículo(s). Página <?= (int) $res['pagina'] ?> de <?= (int) $res['paginas'] ?>.</p>
  <table>
    <caption class="oculto">Artículos de inventario</caption>
    <thead><tr>
      <th scope="col">SKU</th><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Categoría</th><th scope="col">Marca</th>
      <th scope="col">Unidad</th>
      <?php if ($d['ver_inventario']): ?><th scope="col" class="num">Existencias</th><?php endif; ?>
      <?php if ($d['ver_costos']): ?><th scope="col" class="num">Costo promedio</th><?php endif; ?>
      <th scope="col">Estado</th>
    </tr></thead>
    <tbody>
    <?php foreach ($res['filas'] as $a): ?>
      <tr<?= $a['activo'] ? '' : ' class="inactivo"' ?>>
        <td><a href="<?= $v->url('/inventario/articulos/' . (int) $a['id']) ?>"><?= $v->e($a['sku']) ?></a></td>
        <td><?= $v->e($a['nombre']) ?></td>
        <td><?= $v->e($etiquetaTipo[$a['tipo']] ?? $a['tipo']) ?></td>
        <td><?= $v->e($a['categoria'] ?? '') ?></td>
        <td><?= $v->e($a['marca'] ?? '') ?></td>
        <td><?= $v->e($a['unidad_medida']) ?></td>
        <?php if ($d['ver_inventario']): ?><td class="num"><?= $v->e(\App\Support\Decimal::mostrar((string) ($a['cantidad'] ?? '0'))) ?></td><?php endif; ?>
        <?php if ($d['ver_costos']): ?><td class="num"><?= $v->e(\App\Support\Decimal::mostrar((string) ($a['costo_promedio'] ?? '0'))) ?></td><?php endif; ?>
        <td><?= $a['activo'] ? 'Activo' : 'Inactivo' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$res['filas']): ?><tr><td colspan="9">No hay artículos con esos filtros.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <nav class="paginacion" aria-label="Paginación">
    <?php if ($res['pagina'] > 1): ?><a href="<?= $v->e($enlace($res['pagina'] - 1)) ?>" rel="prev">Anterior</a><?php endif; ?>
    <?php if ($res['pagina'] < $res['paginas']): ?><a href="<?= $v->e($enlace($res['pagina'] + 1)) ?>" rel="next">Siguiente</a><?php endif; ?>
  </nav>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
