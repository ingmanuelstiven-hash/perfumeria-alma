<?php
/** @var array $d @var \App\View $v */
$d['titulo'] = 'Error ' . (int) $d['status'];
require __DIR__ . '/_cabecera.php';
?>
<section class="tarjeta estrecha">
  <h1>Error <?= (int) $d['status'] ?></h1>
  <p><?= $v->e($d['mensaje']) ?></p>
  <p><a href="<?= $v->url('/') ?>">Volver al inicio</a></p>
</section>
<?php require __DIR__ . '/_pie.php'; ?>
