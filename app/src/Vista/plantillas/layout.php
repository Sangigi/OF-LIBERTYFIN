<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= P::e($titulo ?? 'LibertyFin') ?> · LibertyFin</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/libertyfin.css?v=1">
</head>
<body>
<div class="lf-app">
  <?php P::parcial('parciales/sidebar', ['activo' => $icono ?? '']); ?>
  <div class="lf-main">
    <?php P::parcial('parciales/topbar', ['titulo' => $titulo ?? '', 'icono' => $icono ?? 'panel', 'subtitulo' => $subtitulo ?? '']); ?>
    <div class="lf-cont"><?= $contenido ?></div>
  </div>
</div>
</body>
</html>
