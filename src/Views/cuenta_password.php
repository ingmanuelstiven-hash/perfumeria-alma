<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Cambiar contraseña';
$u = $d['usuario'];
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta estrecha">
  <h1>Cambiar contraseña</h1>
  <?php if ((int) $u['debe_cambiar_password'] === 1): ?>
    <p class="aviso" role="status">Debe cambiar su contraseña temporal antes de continuar.</p>
  <?php endif; ?>
  <?php if (!empty($d['error'])): ?>
    <div class="alerta" role="alert" id="errores-pass">
      <p><?= $v->e($d['error']) ?></p>
      <?php if ($d['errores']): ?>
        <ul><?php foreach ($d['errores'] as $e): ?><li><?= $v->e($e) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <form method="post" action="<?= $v->url('/cuenta/password') ?>">
    <?= $v->campoCsrf() ?>
    <label for="actual">Contraseña actual</label>
    <input id="actual" name="actual" type="password" required maxlength="1024" autocomplete="current-password">
    <label for="nueva">Nueva contraseña (mínimo 12 caracteres)</label>
    <input id="nueva" name="nueva" type="password" required minlength="12" maxlength="1024" autocomplete="new-password">
    <label for="confirmacion">Repita la nueva contraseña</label>
    <input id="confirmacion" name="confirmacion" type="password" required minlength="12" maxlength="1024" autocomplete="new-password">
    <button type="submit">Cambiar contraseña</button>
  </form>
  <p class="nota">Al cambiarla se cerrarán sus sesiones en otros equipos.</p>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
