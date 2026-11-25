<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Iniciar sesión';
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta estrecha">
  <h1>Iniciar sesión</h1>
  <?php if (!empty($d['error'])): ?>
    <p class="alerta" role="alert" id="error-login"><?= $v->e($d['error']) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= $v->url('/login') ?>" novalidate>
    <?= $v->campoCsrf() ?>
    <label for="usuario">Usuario</label>
    <input id="usuario" name="usuario" type="text" required maxlength="50"
           autocomplete="username" autocapitalize="none" spellcheck="false"
           value="<?= $v->e($d['usuario'] ?? '') ?>"<?= !empty($d['error']) ? ' aria-describedby="error-login"' : '' ?>>
    <label for="password">Contraseña</label>
    <input id="password" name="password" type="password" required maxlength="1024" autocomplete="current-password">
    <button type="submit">Entrar</button>
  </form>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
