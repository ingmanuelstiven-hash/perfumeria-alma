<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Renovando sesión';
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta estrecha">
  <h1>Su sesión necesita renovarse</h1>
  <form id="form-renovar" method="post" action="<?= $v->url('/auth/refresh') ?>" data-auto="<?= $d['auto'] ? '1' : '0' ?>">
    <?= $v->campoCsrf() ?>
    <input type="hidden" name="siguiente" value="<?= $v->e($d['siguiente']) ?>">
    <p><?= $d['auto'] ? 'Renovando…' : 'Otra pestaña acaba de renovar la sesión.' ?></p>
    <button type="submit">Continuar</button>
  </form>
  <p><a href="<?= $v->url('/login') ?>">Iniciar sesión de nuevo</a></p>
</section>
<script src="<?= $v->url('/assets/js/renovar.js') ?>" defer></script>
<?php require __DIR__ . '/_pie.php'; ?>
