<?php
use LibertyFin\Vista\Widget as W;
use LibertyFin\Vista\Plantilla as P;

/**
 * Acciones por sección.
 *
 * Antes salían "Exportar" y "Nueva venta" en todas partes. El de exportar
 * no hacía nada, y el de nueva venta no tiene sentido estando ya en la
 * caja. Aquí cada sección declara lo suyo, y donde no hay nada útil no
 * aparece nada.
 */
$acciones = [
  'panel'   => [['/caja', 'mas', 'Nueva venta', 'primary']],
  'venta'   => [['/caja', 'mas', 'Nueva venta', 'primary']],
  'cliente' => [['/clientes?nuevo=1', 'mas', 'Nuevo cliente', 'primary']],
  'reloj'   => [['/caja', 'mas', 'Nueva venta', 'primary']],
  'baja'    => [['/gastos?nuevo=1', 'mas', 'Nuevo gasto', 'primary']],
  'serv'    => [['/servicios?nuevo=1', 'mas', 'Nuevo servicio', 'primary']],
  // Reportes sí exporta de verdad: baja el detalle en CSV.
  'pct'     => [['/reportes/csv', 'baja', 'Descargar CSV', 'secondary']],
];
// Un rol de plataforma no vende ni da de alta clientes: esas acciones
// operarían sobre la empresa de la sesión, que no es la suya.
$aqui = \LibertyFin\Dominio\Permisos::esPlataforma($_SESSION['usuario_rol'] ?? '')
      ? [] : ($acciones[$icono] ?? []);
?>
<header class="lf-top">
  <div class="lf-top-tit">
    <h1><?= W::icono($icono, '18px') ?><span><?= P::e($titulo) ?></span></h1>
    <?php if ($subtitulo): ?><p><?= P::e($subtitulo) ?></p><?php endif; ?>
  </div>

  <div class="lf-acc">
    <?php foreach ($aqui as $a): ?>
      <a class="btn btn-<?= $a[3] ?>" href="<?= P::e($a[0]) ?>">
        <?= W::icono($a[1], '15px') ?><?= P::e($a[2]) ?></a>
    <?php endforeach; ?>

    <button type="button" class="lf-tema" id="lfTema"
            aria-label="Cambiar entre claro y oscuro" title="Cambiar tema">
      <span class="claro"><?= W::icono('luna', '16px') ?></span>
      <span class="oscuro"><?= W::icono('sol', '16px') ?></span>
    </button>
  </div>
</header>

<script>
(function(){
  var b = document.getElementById('lfTema');
  if (!b) return;
  b.addEventListener('click', function(){
    var raiz = document.documentElement;
    var nuevo = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    raiz.setAttribute('data-theme', nuevo);
    try { localStorage.setItem('lf-tema', nuevo); } catch (e) {}
  });
})();
</script>
