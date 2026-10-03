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

  /* Lo que NUNCA se trae por partes.
     Un enlace puede descargar un archivo, imprimir un ticket, salir de
     la sesión o disparar una acción. Cambiar media pantalla en esos
     casos deja la aplicación mintiendo sobre dónde está. */
  var FUERA = ['/salir', '/login', '/registro', '/ticket', '/imprimir',
               '/descargar', '/exportar', '/pdf', '/qr'];

  function esNuestro(a) {
    if (!a || !a.getAttribute('href')) return false;
    if (a.target || a.hasAttribute('download')) return false;
    if (a.hasAttribute('data-completo')) return false;
    var u;
    try { u = new URL(a.href, location.href); } catch (e) { return false; }
    if (u.origin !== location.origin) return false;
    if (u.pathname === location.pathname && u.hash && u.search === location.search) return false;
    for (var i = 0; i < FUERA.length; i++) {
      if (u.pathname === FUERA[i] || u.pathname.indexOf(FUERA[i] + '/') === 0
          || u.pathname.indexOf(FUERA[i]) === 0) return false;
    }
    /* Pestañas, filtros y paginación —lo de siempre— y además las
       SECCIONES del menú y los enlaces que se marquen a mano.
       Cambiar de sección volvía a pedir la página entera: su menú, su
       barra, sus fuentes y su hoja de estilos, para cambiar solo el
       centro. */
    return a.matches('.lf-pill, .lf-pag a, [data-tab], .lf-nav a, .lf-dedo a, [data-parcial]');
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

        /* La barra superior y el menú viajan con la página pedida. Sin
           esto, al cambiar de sección el centro era el nuevo pero el
           título seguía diciendo el anterior y la sección encendida en
           el menú era la de antes: la aplicación mentía sobre dónde
           estabas parado. */
        var barraVieja = document.querySelector('.lf-top'),
            barraNueva = doc.querySelector('.lf-top');
        if (barraVieja && barraNueva) {
          barraVieja.innerHTML = barraNueva.innerHTML;
          ejecutarScripts(barraVieja);
        }
        var navNueva = doc.querySelector('.lf-nav');
        if (navNueva) {
          var activa = navNueva.querySelector('a.on');
          var ruta = activa ? activa.getAttribute('href') : null;
          document.querySelectorAll('.lf-nav a, .lf-dedo a').forEach(function (a) {
            a.classList.toggle('on', ruta !== null && a.getAttribute('href') === ruta);
          });
        }

        ejecutarScripts(cont);
        if (doc.title) document.title = doc.title;
        if (empujar) history.pushState({ lfNav: 1 }, '', url);
        cont.classList.remove('cargando');
        /* Quien escucha —un lector de pantalla, por ejemplo— no se
           entera de que cambió medio documento si nadie lo dice. */
        document.dispatchEvent(new CustomEvent('lf:cargado', { detail: { url: url } }));
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

/* ══════════════════════════════════════════════════════
   SELECTOR DE ARCHIVO
   El control nativo mide lo que mida el nombre del archivo
   y no acepta puntos suspensivos. Con `comprobante de pago
   enero 2026 sucursal centro.pdf` se salía de su tarjeta.
   El input sigue siendo el mismo —se envía igual, funciona
   igual sin JavaScript—: lo que se dibuja encima es lo que
   sí obedece al ancho.
   ══════════════════════════════════════════════════════ */
(function () {
  function nombrar(inp) {
    var caja = inp.closest('.lf-file');
    if (!caja) return;
    var et = caja.querySelector('.n');
    if (!et) return;
    var f = inp.files;
    if (!f || !f.length) {
      et.textContent = et.dataset.vacio || 'Ningún archivo elegido';
      et.classList.remove('hay');
      return;
    }
    et.textContent = f.length === 1 ? f[0].name : f.length + ' archivos';
    et.classList.add('hay');
    /* El nombre completo, por si no cabe. */
    et.title = f.length === 1 ? f[0].name : '';
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('.lf-file input[type=file]')) nombrar(e.target);
  });
  /* El botón es un <label for>, así que abre el diálogo solo. Esto es
     para cuando no se puede usar `for` —dentro de otra etiqueta—. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.lf-file .bt');
    if (!b || b.tagName === 'LABEL') return;
    var inp = b.closest('.lf-file').querySelector('input[type=file]');
    if (inp) inp.click();
  });
})();

/* ══════════════════════════════════════════════════════
   AJUSTES QUE SE GUARDAN SIN RECARGAR
   Elegir "los pagos se aplican solos" recargaba la página
   entera: se perdía el scroll, la pestaña abierta y el
   sitio donde estabas leyendo, para cambiar una palabra.

   Cualquier formulario con `data-guardar` se envía por
   detrás. Sin JavaScript sigue siendo un formulario normal
   que recarga, que es como debe degradar.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.fetch || !window.FormData) return;

  function aviso(form, texto, tipo) {
    var caja = form.querySelector('[data-aviso]');
    if (!caja) {
      caja = document.createElement('p');
      caja.setAttribute('data-aviso', '');
      form.appendChild(caja);
    }
    caja.className = 'lf-guardado ' + (tipo === 'error' ? 'mal' : 'bien');
    caja.textContent = texto;
    caja.hidden = false;
    clearTimeout(caja._t);
    /* El aviso de error se queda: si algo no se guardó, enterarse
       cuatro segundos no basta. El de éxito sí se va solo. */
    if (tipo !== 'error') {
      caja._t = setTimeout(function () { caja.hidden = true; }, 4000);
    }
  }

  function enviar(form) {
    var btn = form.querySelector('[type=submit]');
    if (form._enviando) return;
    form._enviando = true;
    if (btn) { btn.disabled = true; btn.dataset.antes = btn.textContent; btn.textContent = 'Guardando…'; }

    fetch(form.action, {
      method: (form.method || 'post').toUpperCase(),
      body: new FormData(form),
      headers: { 'X-LF-Json': '1', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); })
      .then(function (res) {
        var j = null;
        try { j = JSON.parse(res.t); } catch (e) {}
        if (j && typeof j.ok !== 'undefined') {
          aviso(form, j.mensaje || j.error || (j.ok ? 'Guardado.' : 'No se pudo guardar.'),
                j.ok ? 'ok' : 'error');
          if (j.ok && j.recargar) location.reload();
          return;
        }
        /* El servidor contestó una página, no JSON: la vista todavía no
           sabe responder por detrás. Se recarga, que es lo que habría
           pasado de todos modos, en vez de dejar al usuario sin saber
           si se guardó. */
        location.reload();
      })
      .catch(function () {
        aviso(form, 'No se pudo guardar: revisa tu conexión.', 'error');
      })
      .then(function () {
        form._enviando = false;
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.antes || 'Guardar'; }
      });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-guardar]');
    if (!f) return;
    e.preventDefault();
    enviar(f);
  });

  /* Elegir una opción guarda sola. Un formulario de una sola decisión
     no necesita confirmarse: el botón pasa a ser un trámite. */
  document.addEventListener('change', function (e) {
    var f = e.target.closest('form[data-guardar][data-al-elegir]');
    if (!f || !e.target.matches('input[type=radio],input[type=checkbox],select')) return;
    /* La tarjeta elegida se marca al momento, sin esperar al servidor:
       el cambio tiene que sentirse en el dedo. */
    f.querySelectorAll('.m').forEach(function (m) {
      var r = m.querySelector('input');
      m.classList.toggle('on', !!(r && r.checked));
    });
    enviar(f);
  });
})();

/* ══════════════════════════════════════════════════════
   VER SIN PERDER EL SITIO
   Abrir una venta desde la lista te sacaba de la lista:
   leías tres datos y volvías con el botón de atrás, al
   principio de la tabla y sin el filtro que tenías puesto.

   Cualquier enlace con `data-modal` se abre en un panel
   encima. Dentro del panel se puede seguir navegando —a la
   ficha del cliente, a otra venta— y al cerrar sigues donde
   estabas, con tu filtro y tu scroll intactos.

   Sigue siendo un enlace: ctrl+clic, "abrir en otra
   pestaña" y entrar sin JavaScript funcionan igual.
   ══════════════════════════════════════════════════════ */
(function () {
  if (!window.fetch) return;

  var caja = null, cuerpo = null, titulo = null, enlace = null,
      pila = [], devolver = null, pidiendo = null;

  function armar() {
    if (caja) return;
    caja = document.createElement('div');
    caja.className = 'lf-vistazo';
    caja.hidden = true;
    caja.innerHTML =
      '<div class="velo" data-cerrar></div>' +
      '<div class="hoja" role="dialog" aria-modal="true" aria-label="Detalle">' +
        '<header>' +
          '<button type="button" class="atras" hidden aria-label="Volver">&#8249;</button>' +
          '<b class="tit">Cargando…</b>' +
          '<a class="abrir" href="#" target="_blank" rel="noopener">Abrir completo</a>' +
          '<button type="button" class="cerrar" data-cerrar aria-label="Cerrar">&times;</button>' +
        '</header>' +
        '<div class="cuerpo"><div class="cargando">Cargando…</div></div>' +
      '</div>';
    document.body.appendChild(caja);
    cuerpo = caja.querySelector('.cuerpo');
    titulo = caja.querySelector('.tit');
    enlace = caja.querySelector('.abrir');

    caja.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-cerrar')) cerrar();
    });
    caja.querySelector('.atras').addEventListener('click', function () {
      if (pila.length > 1) { pila.pop(); traer(pila[pila.length - 1], false); }
    });
    /* Dentro del panel, los enlaces siguen dentro del panel. Saltar a
       página completa desde aquí tiraría el contexto que el panel
       existe para conservar. */
    cuerpo.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      var a = e.target.closest('a');
      if (!a || a.target || a.hasAttribute('download')) return;
      var u;
      try { u = new URL(a.href, location.href); } catch (err) { return; }
      if (u.origin !== location.origin) return;
      if (/\/(salir|login|ticket|imprimir|descargar|exportar|pdf|qr)/.test(u.pathname)) return;
      e.preventDefault();
      traer(u.pathname + u.search, true);
    });
  }

  function traer(url, apilar) {
    armar();
    if (apilar) pila.push(url);
    caja.querySelector('.atras').hidden = pila.length <= 1;
    enlace.href = url;
    cuerpo.innerHTML = '<div class="cargando">Cargando…</div>';

    if (pidiendo) pidiendo.abort();
    pidiendo = new AbortController();
    fetch(url, { signal: pidiendo.signal, credentials: 'same-origin',
                 headers: { 'X-LF-Parcial': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html'),
            dentro = doc.querySelector('.lf-cont');
        if (!dentro) { location.href = url; return; }
        cuerpo.innerHTML = dentro.innerHTML;
        /* Un <script> puesto con innerHTML no corre. Sin esto, los
           botones de copiar y los confirmar del contenido traído
           quedan muertos. */
        cuerpo.querySelectorAll('script').forEach(function (v) {
          var n = document.createElement('script');
          for (var i = 0; i < v.attributes.length; i++) {
            n.setAttribute(v.attributes[i].name, v.attributes[i].value);
          }
          n.textContent = v.textContent;
          v.parentNode.replaceChild(n, v);
        });
        var t = doc.querySelector('.lf-top-tit b, .lf-top-tit h1, h1');
        titulo.textContent = t ? t.textContent.trim() : (doc.title || 'Detalle');
        cuerpo.scrollTop = 0;
      })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        cuerpo.innerHTML = '<div class="cargando">No se pudo cargar. ' +
          '<a href="' + url + '">Abrir completo</a></div>';
      });
  }

  function abrir(url, origen) {
    armar();
    pila = [];
    devolver = origen || null;
    document.body.classList.add('lf-vistazo-abierto');
    caja.hidden = false;
    traer(url, true);
  }

  function cerrar() {
    if (!caja || caja.hidden) return;
    caja.hidden = true;
    document.body.classList.remove('lf-vistazo-abierto');
    pila = [];
    if (pidiendo) { pidiendo.abort(); pidiendo = null; }
    /* El foco vuelve a donde estaba. Quien navega con teclado se
       quedaba al principio del documento al cerrar. */
    if (devolver && document.contains(devolver)) devolver.focus();
    devolver = null;
  }

  document.addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    var a = e.target.closest('[data-modal]');
    if (!a || !a.getAttribute('href')) return;
    e.preventDefault();
    abrir(a.getAttribute('href'), a);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') cerrar();
  });

  window.lfVistazo = abrir;
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
