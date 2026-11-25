<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Usuarios';
$yo = (int) $d['usuario']['id'];
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta">
  <h1>Usuarios</h1>
  <?php if (!empty($d['mensaje'])): ?>
    <p class="ok" role="status"><?= $v->e($d['mensaje']) ?></p>
  <?php endif; ?>
  <?php if (!empty($d['error'])): ?>
    <div class="alerta" role="alert">
      <p><?= $v->e($d['error']) ?></p>
      <?php if ($d['errores']): ?>
        <ul><?php foreach ($d['errores'] as $e): ?><li><?= $v->e($e) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <table>
    <caption class="oculto">Usuarios del sistema</caption>
    <thead><tr><th scope="col">Usuario</th><th scope="col">Nombre</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($d['usuarios'] as $u): ?>
      <tr>
        <td><?= $v->e($u['usuario']) ?></td>
        <td><?= $v->e($u['nombre']) ?></td>
        <td><?= $v->e($u['rol_nombre']) ?></td>
        <td>
          <?= (int) $u['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
          <?= (int) $u['bloqueado'] === 1 ? ' · Bloqueado' : '' ?>
          <?= (int) $u['debe_cambiar_password'] === 1 ? ' · Clave temporal' : '' ?>
        </td>
        <td>
          <?php if ($d['puede_gestionar'] && (int) $u['id'] !== $yo): ?>
            <form method="post" action="<?= $v->url('/admin/usuarios/' . (int) $u['id'] . '/rol') ?>" class="en-linea">
              <?= $v->campoCsrf() ?>
              <label class="oculto" for="rol-<?= (int) $u['id'] ?>">Rol de <?= $v->e($u['usuario']) ?></label>
              <select id="rol-<?= (int) $u['id'] ?>" name="rol">
                <?php foreach ($d['roles'] as $r): ?>
                  <option value="<?= $v->e($r['codigo']) ?>"<?= $r['codigo'] === $u['rol'] ? ' selected' : '' ?>><?= $v->e($r['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit">Asignar rol</button>
            </form>
            <form method="post" action="<?= $v->url('/admin/usuarios/' . (int) $u['id'] . '/activo') ?>" class="en-linea">
              <?= $v->campoCsrf() ?>
              <input type="hidden" name="activo" value="<?= (int) $u['activo'] === 1 ? '0' : '1' ?>">
              <button type="submit"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
            </form>
            <?php if ((int) $u['bloqueado'] === 1): ?>
            <form method="post" action="<?= $v->url('/admin/usuarios/' . (int) $u['id'] . '/desbloquear') ?>" class="en-linea">
              <?= $v->campoCsrf() ?>
              <button type="submit">Desbloquear</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($d['puede_gestionar']): ?>
<section class="tarjeta estrecha">
  <h2>Nuevo usuario</h2>
  <form method="post" action="<?= $v->url('/admin/usuarios') ?>">
    <?= $v->campoCsrf() ?>
    <label for="n-usuario">Usuario</label>
    <input id="n-usuario" name="usuario" required maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="off" value="<?= $v->e($d['form']['usuario']) ?>">
    <label for="n-nombre">Nombre completo</label>
    <input id="n-nombre" name="nombre" required maxlength="100" value="<?= $v->e($d['form']['nombre']) ?>">
    <label for="n-email">Email (opcional)</label>
    <input id="n-email" name="email" type="email" maxlength="150" value="<?= $v->e($d['form']['email']) ?>">
    <label for="n-rol">Rol</label>
    <select id="n-rol" name="rol">
      <?php foreach ($d['roles'] as $r): ?>
        <option value="<?= $v->e($r['codigo']) ?>"<?= $r['codigo'] === 'empleado' ? ' selected' : '' ?>><?= $v->e($r['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="n-password">Contraseña temporal (mínimo 12 caracteres)</label>
    <input id="n-password" name="password" type="password" required minlength="12" maxlength="1024" autocomplete="new-password">
    <button type="submit">Crear usuario</button>
  </form>
</section>

<section class="tarjeta estrecha">
  <h2>Desbloquear una IP</h2>
  <form method="post" action="<?= $v->url('/admin/bloqueos/ip') ?>">
    <?= $v->campoCsrf() ?>
    <label for="ip">Dirección IP</label>
    <input id="ip" name="ip" required maxlength="45" inputmode="decimal">
    <button type="submit">Desbloquear IP</button>
  </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/_pie.php'; ?>
