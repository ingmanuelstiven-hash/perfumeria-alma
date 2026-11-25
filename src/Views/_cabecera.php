<?php
/** @var array $d @var \App\View $v */
$titulo = $d['titulo'] ?? 'Perfumería';
$u = $d['usuario'] ?? null;
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $v->csrf() ?>">
<title><?= $v->e($titulo) ?> · Perfumería</title>
<link rel="stylesheet" href="<?= $v->url('/assets/css/app.css') ?>">
</head>
<body>
<header class="barra">
  <a class="marca" href="<?= $v->url('/') ?>">Perfumería</a>
  <?php if (is_array($u)): ?>
  <nav aria-label="Cuenta">
    <span><?= $v->e($u['nombre']) ?> (<?= $v->e($u['rol']) ?>)</span>
    <a href="<?= $v->url('/cuenta/password') ?>">Cambiar contraseña</a>
    <form method="post" action="<?= $v->url('/logout') ?>" class="en-linea">
      <?= $v->campoCsrf() ?>
      <button type="submit">Cerrar sesión</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main id="contenido">
