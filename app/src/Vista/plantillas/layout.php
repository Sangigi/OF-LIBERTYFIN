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

/* ══════════════════════════════════════════════════════
   PESTAÑAS SIN RECARGAR
   Sirve para cualquier sección: un contenedor con
   data-tabs, enlaces con data-tab y bloques con
   data-panel. Los enlaces siguen siendo enlaces, así que
   funcionan igual sin JavaScript y se pueden abrir en
   otra pestaña del navegador.
   ══════════════════════════════════════════════════════ */
(function () {
  function activar(caja, clave, empujar) {
    var hubo = false;
    caja.querySelectorAll('[data-panel]').forEach(function (p) {
      var es = p.dataset.panel === clave;
      p.hidden = !es;
      if (es) hubo = true;
    });
    if (!hubo) return false;

    caja.querySelectorAll('[data-tab]').forEach(function (t) {
      var es = t.dataset.tab === clave;
      t.classList.toggle('active', es);
      if (t.hasAttribute('aria-selected')) t.setAttribute('aria-selected', es ? 'true' : 'false');
    });

    /* La dirección se actualiza sin recargar: así recargar a mano,
       compartir el enlace o usar el botón de atrás siguen llevando a la
       misma pestaña. Sin esto, cambiar de pestaña y recargar devolvía a
       la primera sin explicación. */
    if (empujar) {
      var activo = caja.querySelector('[data-tab="' + CSS.escape(clave) + '"]');
      if (activo && activo.getAttribute('href')) {
        history.pushState({ lfTabs: caja.dataset.tabs, lfClave: clave },
                          '', activo.getAttribute('href'));
      }
    }
    return true;
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-tab]');
    if (!t) return;
    /* Ctrl, Cmd o botón de en medio: se deja pasar, porque el usuario
       está pidiendo abrirlo en otra pestaña del navegador. */
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;

    var caja = t.closest('[data-tabs]');
    if (!caja) return;
    if (activar(caja, t.dataset.tab, true)) ev.preventDefault();
  });

  window.addEventListener('popstate', function (ev) {
    var e = ev.state;
    if (e && e.lfTabs) {
      var caja = document.querySelector('[data-tabs="' + CSS.escape(e.lfTabs) + '"]');
      if (caja) { activar(caja, e.lfClave, false); return; }
    }
    /* Sin estado propio —por ejemplo al volver desde otra página— se
       lee de la dirección. */
    document.querySelectorAll('[data-tabs]').forEach(function (caja) {
      var par = new URLSearchParams(location.search);
      var v = par.get('tipo') || par.get('t') || par.get('pestana');
      if (v) activar(caja, v, false);
    });
  });
})();

/* ══════════════════════════════════════════════════════
   CAMBIAR DE PESTAÑA SIN RECARGAR LA PÁGINA
   Vale para cualquier sección: pestañas, filtros y
   paginación. Trae solo el contenido y lo cambia en su
   sitio, en vez de volver a pedir la página entera con su
   menú, su barra y sus estilos.
   ══════════════════════════════════════════════════════ */
(function () {
  var cont = document.querySelector('.lf-cont');
  if (!cont || !window.history || !window.fetch) return;

  var enCurso = null;

  function esNuestro(a) {
    if (!a || !a.getAttribute('href')) return false;
    if (a.target || a.hasAttribute('download')) return false;
    /* Solo pestañas, filtros y paginación. Un enlace cualquiera puede
       llevar a una descarga, a otra sección o a una acción, y cambiarlo
       a medias dejaría la pantalla mintiendo. */
    if (!a.matches('.lf-pill, .lf-pag a, [data-tab]')) return false;
    var u;
    try { u = new URL(a.href, location.href); } catch (e) { return false; }
    return u.origin === location.origin;
  }

  function ejecutarScripts(donde) {
    /* Un <script> insertado con innerHTML no corre. Se vuelve a crear
       para que sí: sin esto, los botones de copiar, los selectores y los
       confirmar del contenido nuevo quedan muertos. */
    donde.querySelectorAll('script').forEach(function (viejo) {
      var nuevo = document.createElement('script');
      for (var i = 0; i < viejo.attributes.length; i++) {
        nuevo.setAttribute(viejo.attributes[i].name, viejo.attributes[i].value);
      }
      nuevo.textContent = viejo.textContent;
      viejo.parentNode.replaceChild(nuevo, viejo);
    });
  }

  function ir(url, empujar) {
    if (enCurso) enCurso.abort();
    enCurso = new AbortController();
    cont.classList.add('cargando');

    fetch(url, { signal: enCurso.signal, headers: { 'X-LF-Parcial': '1' },
                 credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nuevo = doc.querySelector('.lf-cont');
        if (!nuevo) { location.href = url; return; }

        cont.innerHTML = nuevo.innerHTML;
        ejecutarScripts(cont);
        if (doc.title) document.title = doc.title;
        if (empujar) history.pushState({ lfNav: 1 }, '', url);
        cont.classList.remove('cargando');
        /* Se sube al principio del bloque, no de la página: con el
           filtro arriba, quedarse donde estaba hace creer que no pasó
           nada. */
        var caja = cont.querySelector('.card');
        if (caja && caja.getBoundingClientRect().top < 0) {
          caja.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        cont.classList.remove('cargando');
        location.href = url;   /* si algo falla, se recarga como siempre */
      });
  }

  document.addEventListener('click', function (ev) {
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;
    var a = ev.target.closest('a');
    if (!esNuestro(a)) return;
    ev.preventDefault();
    ir(a.href, true);
  });

  window.addEventListener('popstate', function (ev) {
    if (ev.state && ev.state.lfNav) ir(location.href, false);
  });
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
