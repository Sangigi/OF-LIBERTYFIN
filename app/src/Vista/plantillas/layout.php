<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
?><!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
<meta charset="utf-8">
<script>
// Se ejecuta antes de que el navegador pinte nada: si esperara al final
// del documento, la página aparecería en claro y saltaría a oscuro. Ese
// parpadeo blanco es lo que hace que un modo oscuro se sienta barato.
(function(){
  try {
    var t = localStorage.getItem('lf-tema');
    if (t === 'dark' || t === 'light') {
      document.documentElement.setAttribute('data-theme', t);
      return;
    }
  } catch (e) {}
  // Sin preferencia guardada: claro. NUNCA se hereda del sistema sin que
  // el usuario lo pida.
  document.documentElement.setAttribute('data-theme', 'light');
})();
</script>

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= P::e($titulo ?? 'LibertyFin') ?> · LibertyFin</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='24' fill='%2327ae60'/><text x='50' y='50' font-family='DM Sans,system-ui,sans-serif' font-size='62' font-weight='800' fill='white' text-anchor='middle' dominant-baseline='central'>L</text></svg>">
<meta name="theme-color" content="#27ae60">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/libertyfin.css?v=2">
<?php
// El color de la empresa se inyecta como variable. Todo lo demás
// —hovers, fondos tenues, anillos de foco— se calcula con color-mix,
// así que basta con este dato para que el sistema entero cambie.
$marca = $_SESSION['lf_marca_color'] ?? '';
if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)$marca)): ?>
<style>:root{--lf-brand:<?= $marca ?>}</style>
<?php endif; ?>
</head>
<body>
<div class="lf-app">
  <?php P::parcial('parciales/sidebar', ['activo' => $icono ?? '']); ?>
  <div class="lf-main">
    <?php P::parcial('parciales/topbar', ['titulo' => $titulo ?? '', 'icono' => $icono ?? 'panel', 'subtitulo' => $subtitulo ?? '']); ?>
    <div class="lf-cont"><?= $contenido ?></div>
  </div>
  <?php if (!empty($_SESSION['lf_mostrar_guia'])): unset($_SESSION['lf_mostrar_guia']);
        P::parcial('parciales/guia'); endif; ?>
  </div>
</div>
</body>
</html>
