<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Integraciones;

/**
 * Tutorial · LibertyFin explicado paso a paso, de punta a punta.
 *
 * Doce capítulos cortos con los nombres EXACTOS que salen en pantalla.
 * Cada uno dice para qué sirve, dónde está, los pasos, el consejo, el
 * error común y cuándo ya lo dominaste.
 *
 * Los atajos «Ir a…» siguen las mismas reglas que el menú (permiso,
 * módulo encendido, integración activa, sección no apagada): un botón
 * que lleva a «Esta sección no es para tu rol» enseña lo contrario.
 *
 * Todo el estilo va bajo .tu y usa los colores del sistema, así que sigue
 * el tema claro/oscuro y el color de marca de cada empresa.
 */
$modulos  = $modulos ?? [];
$apagadas = $apagadas ?? [];
$ir = function ($ruta, $texto, $permiso = null, array $c = []) use ($modulos, $apagadas) {
    if ($permiso && !Permisos::puede($permiso)) return '';
    if (isset($c['mod']) && empty($modulos[$c['mod']])) return '';
    if (isset($c['si'])  && !Integraciones::activa($c['si'])) return '';
    if (isset($c['sec']) && isset($apagadas[$c['sec']])) return '';
    return '<a class="btn btn-secondary btn-sm tu-ir" href="' . P::e($ruta) . '" data-parcial>'
         . P::e($texto) . ' <span aria-hidden="true">→</span></a>';
};
$esAdmin = Permisos::puede('editar.empresa');
?>
<style>
  .tu{container-type:inline-size;--tu-gap:22px}
  .tu .tu-cap, .tu .tu-sec{scroll-margin-top:16px}

  /* ── CABECERA ── */
  .tu-intro{
    border-radius:var(--lf-r-panel);padding:30px 32px;margin-bottom:var(--tu-gap);
    background:radial-gradient(700px 260px at 0 0, var(--lf-brand-soft), transparent 70%), var(--lf-sup);
    border:1px solid var(--lf-linea);box-shadow:var(--lf-s1);
    display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:28px;align-items:center;
  }
  .tu-eti{
    display:inline-flex;font-size:11.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
    color:var(--lf-brand-2);background:var(--lf-brand-soft);border-radius:var(--lf-r-pill);padding:5px 11px;
  }
  .tu-intro h2{font-size:clamp(24px,3.2vw,34px);font-weight:800;letter-spacing:-1px;line-height:1.12;margin:12px 0 8px;color:var(--lf-tinta)}
  .tu-intro p{font-size:14.5px;color:var(--lf-tinta-2);max-width:620px;margin:0}
  .tu-intro-acc{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
  .tu-avance{background:var(--lf-sup-2);border:1px solid var(--lf-linea);border-radius:var(--lf-r);padding:16px}
  .tu-avance-n{font-size:30px;font-weight:800;letter-spacing:-1px;color:var(--lf-tinta);line-height:1}
  .tu-avance-n small{font-size:14px;font-weight:600;color:var(--lf-tinta-3);letter-spacing:0}
  .tu-avance-t{font-size:12.5px;color:var(--lf-tinta-3);margin-top:4px}
  .tu-barra{height:8px;border-radius:99px;background:var(--lf-linea);margin-top:12px;overflow:hidden}
  .tu-barra > i{display:block;height:100%;width:0;background:var(--lf-brand);border-radius:99px;transition:width .35s}

  /* ── BLOQUES ── */
  .tu-sec{margin-bottom:var(--tu-gap)}
  .tu-sec-tit{font-size:20px;font-weight:800;letter-spacing:-.4px;color:var(--lf-tinta);margin:0 0 4px}
  .tu-sec-sub{font-size:13.5px;color:var(--lf-tinta-3);margin:0 0 14px}
  .tu-ideas{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .tu-idea{background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:18px}
  .tu-idea-n{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-weight:800;font-size:14px;
    background:var(--lf-brand-soft);color:var(--lf-brand-2);margin-bottom:10px}
  .tu-idea h3{font-size:15px;font-weight:700;color:var(--lf-tinta);margin:0 0 5px;line-height:1.35}
  .tu-idea p{font-size:13.5px;color:var(--lf-tinta-2);margin:0}
  .tu-rutas{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .tu-ruta{background:var(--lf-sup-2);border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:16px;display:flex;flex-direction:column;gap:8px}
  .tu-ruta h3{font-size:14.5px;font-weight:700;color:var(--lf-tinta);margin:0}
  .tu-ruta p{font-size:12.5px;color:var(--lf-tinta-3);margin:0;flex:1}
  .tu-ruta-caps{display:flex;flex-wrap:wrap;gap:6px}
  .tu-ruta-caps a{font-size:12px;font-weight:700;text-decoration:none;color:var(--lf-brand-2);background:var(--lf-sup);
    border:1px solid var(--lf-brand-ring);border-radius:8px;padding:3px 9px}
  .tu-ruta-caps a:hover{background:var(--lf-brand-soft)}

  /* ── ÍNDICE + CAPÍTULOS ── */
  .tu-cuerpo{display:grid;grid-template-columns:230px minmax(0,1fr);gap:24px;align-items:start}
  .tu-indice-caja{position:sticky;top:16px;max-height:calc(100vh - 32px);overflow:auto;
    background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:10px}
  .tu-indice-caja > summary{display:none}
  .tu-indice ol{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:2px}
  .tu-indice a{display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:9px;text-decoration:none;
    color:var(--lf-tinta-2);font-size:13px;line-height:1.3}
  .tu-indice a:hover{background:var(--lf-vidrio);color:var(--lf-tinta)}
  .tu-indice a.actual{background:var(--lf-brand-soft);color:var(--lf-brand-2);font-weight:600}
  .tu-indice .n{flex:none;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:11px;font-weight:700;
    border:1.5px solid var(--lf-linea-f);color:var(--lf-tinta-3);background:var(--lf-sup)}
  .tu-indice a.visto .n{background:var(--lf-brand);border-color:var(--lf-brand);color:#fff;font-size:0}
  .tu-indice a.visto .n::after{content:'✓';font-size:11px}
  .tu-indice-extra{margin-top:8px!important;padding-top:8px!important;border-top:1px solid var(--lf-linea)}

  .tu-caps{display:flex;flex-direction:column;gap:18px;min-width:0}
  .tu-cap{background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:26px 28px;box-shadow:var(--lf-s1);transition:border-color .3s}
  .tu-cap.visto{border-color:var(--lf-brand-ring)}
  .tu-cap-cab{display:flex;gap:14px;align-items:flex-start}
  .tu-cap-num{flex:none;width:46px;height:46px;border-radius:14px;display:grid;place-items:center;
    background:var(--lf-tinta);color:var(--lf-sup);font-weight:800;font-size:19px}
  .tu-cap.visto .tu-cap-num{background:var(--lf-brand);color:#fff}
  .tu-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:5px}
  .tu-pill{font-size:11px;font-weight:700;letter-spacing:.2px;border-radius:var(--lf-r-pill);padding:2px 9px;
    background:var(--lf-vidrio);color:var(--lf-tinta-3);border:1px solid var(--lf-linea)}
  .tu-pill.op{background:var(--lf-info-soft);color:var(--lf-info);border-color:transparent}
  .tu-cap h2{font-size:clamp(19px,2.3vw,23px);font-weight:800;letter-spacing:-.5px;line-height:1.2;color:var(--lf-tinta);margin:0}
  .tu-para{font-size:14.5px;color:var(--lf-tinta-2);margin:5px 0 0}
  .tu-cap h3{font-size:15px;font-weight:700;color:var(--lf-tinta);margin:22px 0 8px}
  .tu-txt{font-size:14px;color:var(--lf-tinta-2);margin:8px 0 0}

  .tu-donde{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:14px;font-size:12.5px;color:var(--lf-tinta-3)}
  .tu-donde > span:first-child{font-weight:700;color:var(--lf-tinta-2)}
  .tu-migas{display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px}
  .tu-migas b{font-weight:600;color:var(--lf-tinta);background:var(--lf-vidrio);border:1px solid var(--lf-linea);
    border-radius:7px;padding:2px 7px;font-size:12px}
  .tu-migas i{font-style:normal;color:var(--lf-tinta-4)}
  .tu-ui{display:inline-block;font-weight:600;font-size:.92em;color:var(--lf-tinta);background:var(--lf-vidrio);
    border:1px solid var(--lf-linea-f);border-bottom-width:2px;border-radius:7px;padding:0 6px;line-height:1.7;white-space:nowrap}
  .tu-ui.marca{background:var(--lf-brand);border-color:var(--lf-brand-d);color:#fff}
  .tu-q{color:var(--lf-tinta);font-weight:600}
  .tu kbd{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;background:var(--lf-sup);color:var(--lf-tinta);
    border:1px solid var(--lf-linea-f);border-bottom-width:2px;border-radius:5px;padding:1px 5px;box-shadow:none}

  .tu-pasos{list-style:none;counter-reset:paso;margin:12px 0 0;padding:0;display:flex;flex-direction:column}
  .tu-pasos > li{counter-increment:paso;position:relative;padding:11px 0 11px 46px;font-size:14px;color:var(--lf-tinta-2);
    border-bottom:1px dashed var(--lf-linea)}
  .tu-pasos > li:last-child{border-bottom:0}
  .tu-pasos > li::before{content:counter(paso);position:absolute;left:0;top:9px;width:30px;height:30px;border-radius:50%;
    display:grid;place-items:center;background:var(--lf-brand-soft);color:var(--lf-brand-2);font-weight:800;font-size:13px;
    border:1.5px solid var(--lf-brand-ring)}
  .tu-pasos > li > strong:first-child{color:var(--lf-tinta);display:block;margin-bottom:2px;font-size:14.5px}
  .tu-sub{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:5px}
  .tu-sub li{position:relative;padding-left:16px;font-size:13.5px;color:var(--lf-tinta-2)}
  .tu-sub li::before{content:'';position:absolute;left:3px;top:.62em;width:6px;height:6px;border-radius:50%;background:var(--lf-brand)}

  .tu-nota{display:flex;gap:11px;border-radius:var(--lf-r);padding:13px 15px;margin-top:14px;font-size:13.5px;color:var(--lf-tinta-2)}
  .tu-nota > .ic{flex:none;width:24px;height:24px;border-radius:7px;display:grid;place-items:center;font-size:13px;font-weight:800;color:#fff}
  .tu-nota b.t{display:block;color:var(--lf-tinta);margin-bottom:1px}
  .tu-nota.tip{background:var(--lf-brand-soft)}  .tu-nota.tip > .ic{background:var(--lf-brand)}
  .tu-nota.ojo{background:var(--lf-amb-soft)}    .tu-nota.ojo > .ic{background:var(--lf-amb)}
  .tu-nota.info{background:var(--lf-info-soft)}  .tu-nota.info > .ic{background:var(--lf-info)}

  .tu-pie{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--lf-linea)}
  .tu-listo{font-size:13.5px;color:var(--lf-tinta-2)}
  .tu-listo b{color:var(--lf-tinta)}
  .tu-pie-acc{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .tu-visto{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--lf-linea-f);background:var(--lf-sup);
    color:var(--lf-tinta-2);border-radius:var(--lf-r-pill);padding:6px 13px;font-weight:600;font-size:12.5px;cursor:pointer;font-family:inherit}
  .tu-visto::before{content:'';width:14px;height:14px;border-radius:50%;border:1.5px solid var(--lf-linea-f);box-sizing:border-box}
  .tu-visto:hover{border-color:var(--lf-brand);color:var(--lf-brand-2)}
  .tu-visto[aria-pressed="true"]{background:var(--lf-brand);border-color:var(--lf-brand);color:#fff}
  .tu-visto[aria-pressed="true"]::before{content:'✓';border:0;display:grid;place-items:center;font-size:12px;line-height:14px}
  .tu-visto:focus-visible, .tu-indice a:focus-visible{outline:2px solid var(--lf-brand);outline-offset:2px}

  .tu-tabla-env{overflow-x:auto;margin-top:12px;border:1px solid var(--lf-linea);border-radius:var(--lf-r)}
  .tu-tabla{width:100%;border-collapse:collapse;font-size:13.5px;min-width:540px;margin:0}
  .tu-tabla th{background:var(--lf-sup-2);text-align:left;font-weight:700;color:var(--lf-tinta);padding:9px 12px;font-size:12.5px}
  .tu-tabla td{padding:9px 12px;border-top:1px solid var(--lf-linea);color:var(--lf-tinta-2);vertical-align:top}
  .tu-tabla td:first-child{font-weight:600;color:var(--lf-tinta)}

  .tu-rejilla{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
  .tu-mini{border:1px solid var(--lf-linea);border-radius:var(--lf-r);padding:13px;background:var(--lf-sup)}
  .tu-mini .k{font-size:11px;font-weight:700;color:var(--lf-brand-2);letter-spacing:.4px;text-transform:uppercase}
  .tu-mini h4{font-size:14px;font-weight:700;color:var(--lf-tinta);margin:3px 0;line-height:1.35}
  .tu-mini p{font-size:13px;color:var(--lf-tinta-2);margin:0}
  .tu-herr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
  .tu-herr .tu-mini{padding:16px;display:flex;flex-direction:column}
  .tu-herr .tu-mini h4{font-size:15px}
  .tu-herr ol{margin:8px 0 0 18px;padding:0;font-size:13px;color:var(--lf-tinta-2);display:flex;flex-direction:column;gap:3px;flex:1}
  .tu-herr .tu-ir{align-self:flex-start;margin-top:10px}

  /* ── MAQUETAS ── */
  .tu-maq{margin:16px 0 0;border:1px solid var(--lf-linea-f);border-radius:var(--lf-r-lg);overflow:hidden;background:var(--lf-sup-2)}
  .tu-maq-barra{display:flex;align-items:center;gap:6px;padding:9px 13px;background:var(--lf-sup);border-bottom:1px solid var(--lf-linea)}
  .tu-maq-barra > span{width:9px;height:9px;border-radius:50%;background:var(--lf-linea-f)}
  .tu-maq-barra > b{margin-left:8px;font-size:12px;color:var(--lf-tinta-2);font-weight:600}
  .tu-maq figcaption{font-size:12.5px;color:var(--lf-tinta-3);padding:9px 13px;background:var(--lf-sup);border-top:1px solid var(--lf-linea)}
  .tu-mk{display:inline-grid;place-items:center;width:19px;height:19px;border-radius:50%;background:var(--lf-tinta);color:var(--lf-sup);
    font-size:10.5px;font-weight:800;flex:none}
  .tu-mq-caja{display:grid;grid-template-columns:1.35fr 1fr;gap:12px;padding:12px}
  .tu-mq-panel{background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:12px;padding:11px}
  .tu-mq-fila{display:flex;align-items:center;gap:8px}
  .tu-mq-busca,.tu-mq-campo{flex:1;font-size:12px;color:var(--lf-tinta-4);border:1px solid var(--lf-linea);border-radius:8px;padding:6px 9px}
  .tu-mq-prods{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:10px}
  .tu-mq-prod{border:1px solid var(--lf-linea);border-radius:9px;padding:8px;font-size:11.5px;color:var(--lf-tinta-2)}
  .tu-mq-prod b{display:block;color:var(--lf-tinta);font-size:12px;margin-top:2px}
  .tu-mq-prod.sel{border-color:var(--lf-brand);background:var(--lf-brand-soft)}
  .tu-mq-ticket{display:flex;flex-direction:column;gap:7px;font-size:12px}
  .tu-mq-ln{display:flex;justify-content:space-between;gap:8px;color:var(--lf-tinta-2)}
  .tu-mq-ln b{color:var(--lf-tinta)}
  .tu-mq-pagos{display:flex;gap:5px;flex-wrap:wrap}
  .tu-mq-pagos span{border:1px solid var(--lf-linea);border-radius:8px;padding:4px 8px;font-size:11px;font-weight:600;color:var(--lf-tinta-2)}
  .tu-mq-pagos span.on{background:var(--lf-tinta);border-color:var(--lf-tinta);color:var(--lf-sup)}
  .tu-mq-total{display:flex;justify-content:space-between;font-weight:800;font-size:17px;color:var(--lf-tinta);border-top:1px solid var(--lf-linea);padding-top:7px}
  .tu-mq-cobrar{flex:1;background:var(--lf-brand);color:#fff;text-align:center;border-radius:10px;padding:9px;font-weight:700;font-size:13px}

  .tu-folio{padding:16px 14px;display:flex;flex-direction:column;gap:10px}
  .tu-folio-venta{display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:var(--lf-sup);border:1px solid var(--lf-linea);
    border-radius:12px;padding:11px 13px;font-size:12.5px;color:var(--lf-tinta-2)}
  .tu-folio code, .tu-folio-p code{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;font-size:13.5px;color:var(--lf-tinta);background:none;padding:0}
  .tu-folio-pagos{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding-left:20px;position:relative}
  .tu-folio-pagos::before{content:'';position:absolute;left:7px;top:-10px;bottom:50%;width:10px;border-left:2px solid var(--lf-linea-f);
    border-bottom:2px solid var(--lf-linea-f);border-bottom-left-radius:8px}
  .tu-folio-p{background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:12px;padding:9px 11px;font-size:12px;color:var(--lf-tinta-2)}
  .tu-folio-p code{display:block;color:var(--lf-brand-2)}
  .tu-folio-p.fin{border-color:var(--lf-brand);background:var(--lf-brand-soft)}

  .tu-ecu{display:flex;flex-wrap:wrap;align-items:stretch;gap:10px;padding:14px}
  .tu-ecu-c{flex:1 1 140px;background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:12px;padding:11px 13px}
  .tu-ecu-c small{display:block;font-size:11.5px;color:var(--lf-tinta-3)}
  .tu-ecu-c b{font-size:21px;font-weight:800;color:var(--lf-tinta)}
  .tu-ecu-c.res{border-color:var(--lf-brand);background:var(--lf-brand-soft)}
  .tu-ecu-op{display:grid;place-items:center;font-size:21px;font-weight:800;color:var(--lf-tinta-4);flex:none}

  .tu-cascada{padding:14px;display:flex;flex-direction:column;gap:7px}
  .tu-cas{display:grid;grid-template-columns:160px minmax(0,1fr) 84px;gap:10px;align-items:center;font-size:12.5px}
  .tu-cas span{color:var(--lf-tinta-2)}
  .tu-cas em{font-style:normal;text-align:right;font-weight:700;color:var(--lf-tinta);font-variant-numeric:tabular-nums}
  .tu-cas-carril{height:13px;background:var(--lf-sup);border:1px solid var(--lf-linea);border-radius:99px;position:relative;overflow:hidden}
  .tu-cas-carril i{position:absolute;top:0;bottom:0;border-radius:99px}
  .tu-cas.base i{background:var(--lf-tinta)}
  .tu-cas.menos i{background:var(--lf-amb)}
  .tu-cas.queda i{background:var(--lf-brand)}
  .tu-cas.queda span, .tu-cas.queda em{color:var(--lf-brand-2);font-weight:800}

  /* ── PLANES, GLOSARIO, PREGUNTAS, CIERRE ── */
  .tu-planes{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .tu-plan{border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:18px;background:var(--lf-sup);display:flex;flex-direction:column;gap:7px}
  .tu-plan.dest{border-color:var(--lf-brand);box-shadow:0 0 0 3px var(--lf-brand-ring)}
  .tu-plan .nom{font-size:11.5px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--lf-tinta-3)}
  .tu-plan .pre{font-weight:800;font-size:28px;color:var(--lf-tinta);letter-spacing:-1px}
  .tu-plan .pre small{font-size:12.5px;font-weight:600;color:var(--lf-tinta-3);letter-spacing:0}
  .tu-plan .us{font-size:13px;font-weight:600;color:var(--lf-brand-2)}
  .tu-plan ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:5px;font-size:13px;color:var(--lf-tinta-2)}
  .tu-plan li{padding-left:18px;position:relative}
  .tu-plan li::before{content:'✓';position:absolute;left:0;color:var(--lf-brand);font-weight:800}
  .tu-plan .para{font-size:12px;color:var(--lf-tinta-3);border-top:1px solid var(--lf-linea);padding-top:9px;margin-top:auto}

  .tu-glosario{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 26px;margin:0;background:var(--lf-sup);
    border:1px solid var(--lf-linea);border-radius:var(--lf-r-lg);padding:4px 20px}
  .tu-glosario div{padding:12px 0;border-bottom:1px solid var(--lf-linea)}
  .tu-glosario dt{font-weight:700;color:var(--lf-tinta);font-size:14px}
  .tu-glosario dd{font-size:13.5px;color:var(--lf-tinta-2);margin:2px 0 0}
  .tu-faq{display:flex;flex-direction:column;gap:8px}
  .tu-faq details{border:1px solid var(--lf-linea);border-radius:var(--lf-r);background:var(--lf-sup)}
  .tu-faq summary{list-style:none;cursor:pointer;padding:14px 44px 14px 16px;font-weight:600;color:var(--lf-tinta);font-size:14px;position:relative}
  .tu-faq summary::-webkit-details-marker{display:none}
  .tu-faq summary::after{content:'+';position:absolute;right:16px;top:10px;font-size:20px;color:var(--lf-brand-2);font-weight:400}
  .tu-faq details[open] summary::after{content:'−'}
  .tu-faq details > div{padding:0 16px 14px;font-size:13.5px;color:var(--lf-tinta-2)}

  .tu-final{border-radius:var(--lf-r-panel);padding:30px;text-align:center;background:var(--lf-brand-soft);border:1px solid var(--lf-brand-ring)}
  .tu-final h2{font-size:24px;font-weight:800;letter-spacing:-.6px;color:var(--lf-tinta);margin:0 0 6px}
  .tu-final p{font-size:14px;color:var(--lf-tinta-2);margin:0 0 16px}
  .tu-final .tu-intro-acc{justify-content:center;margin:0}

  /* ── ANCHO DEL CONTENEDOR (el menú lateral le quita espacio) ── */
  @container (max-width: 1000px){
    .tu-cuerpo{grid-template-columns:1fr;gap:14px}
    .tu-indice-caja{position:static;max-height:none;overflow:visible;padding:0}
    .tu-indice-caja > summary{display:flex;justify-content:space-between;align-items:center;list-style:none;cursor:pointer;
      padding:13px 15px;font-weight:700;color:var(--lf-tinta);font-size:14px}
    .tu-indice-caja > summary::-webkit-details-marker{display:none}
    .tu-indice-caja > summary::after{content:'▾';color:var(--lf-tinta-3)}
    .tu-indice-caja[open] > summary::after{content:'▴'}
    .tu-indice{padding:0 8px 10px}
    .tu-rutas, .tu-planes{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @container (max-width: 760px){
    .tu-intro{grid-template-columns:1fr;padding:22px 20px}
    .tu-ideas, .tu-rejilla{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @container (max-width: 560px){
    .tu-ideas, .tu-rejilla, .tu-herr, .tu-rutas, .tu-planes, .tu-glosario{grid-template-columns:1fr}
    .tu-cap{padding:20px 16px}
    .tu-cap-num{width:40px;height:40px;font-size:17px;border-radius:12px}
    .tu-pasos > li{padding-left:42px}
    .tu-mq-caja{grid-template-columns:1fr}
    .tu-mq-prods{grid-template-columns:repeat(2,minmax(0,1fr))}
    .tu-folio-pagos{grid-template-columns:1fr}
    .tu-cas{grid-template-columns:104px minmax(0,1fr) 70px;font-size:11.5px}
    .tu-ui{white-space:normal}
  }
  @media (prefers-reduced-motion:reduce){ .tu *{transition:none!important} }
</style>

<div class="tu">

  <!-- ═══ CABECERA ═══ -->
  <section class="tu-intro">
    <div>
      <span class="tu-eti">Tutorial paso a paso</span>
      <h2>Aprende LibertyFin en una tarde</h2>
      <p>De tu primer ingreso a tu primer corte de caja. Cada capítulo te dice qué hacer,
        dónde dar clic y qué vas a ver, con los nombres exactos de la pantalla. Lee todo
        en orden o solo lo que necesites hoy.</p>
      <div class="tu-intro-acc">
        <a class="btn btn-primary" href="#tu-ideas">Empezar</a>
        <a class="btn btn-secondary" href="/guia"><?= W::icono('panel','15px') ?>Ver la guía animada</a>
      </div>
    </div>
    <div class="tu-avance" aria-live="polite">
      <div class="tu-avance-n"><span data-avance-num>0</span> <small>de 12 capítulos</small></div>
      <div class="tu-avance-t">Marca cada capítulo como visto: tu avance se queda en este navegador.</div>
      <div class="tu-barra"><i data-avance-barra></i></div>
    </div>
  </section>

  <!-- ═══ 6 IDEAS ═══ -->
  <section class="tu-sec" id="tu-ideas">
    <h2 class="tu-sec-tit">6 ideas que explican todo LibertyFin</h2>
    <p class="tu-sec-sub">Si entiendes esto, el resto se acomoda solo.</p>
    <div class="tu-ideas">
      <article class="tu-idea"><div class="tu-idea-n">1</div><h3>Cobrado no es lo mismo que vendido</h3>
        <p>Si vendes $1,000 y te dejan $400, vendiste $1,000 pero cobraste $400. Siempre ves las dos cifras, y los reportes cuentan <b>lo que de verdad entró</b>.</p></article>
      <article class="tu-idea"><div class="tu-idea-n">2</div><h3>Una venta puede quedar con saldo</h3>
        <p>El cliente paga completo o deja un anticipo. Lo que falta queda como <b>saldo</b> y se paga después en <b>abonos</b>, sin abrir otra venta.</p></article>
      <article class="tu-idea"><div class="tu-idea-n">3</div><h3>Cada pago tiene su propio folio</h3>
        <p>La venta tiene un folio y cada cobro un subfolio: <b>-01</b>, <b>-02</b>… Puedes referirte a un pago por teléfono y cuadrarlo contra tu banco.</p></article>
      <article class="tu-idea"><div class="tu-idea-n">4</div><h3>Nada se borra: se cancela</h3>
        <p>Una venta o un pago equivocado se cancela con un motivo. Deja de contar en tus números, pero queda el rastro de quién lo hizo y cuándo.</p></article>
      <article class="tu-idea"><div class="tu-idea-n">5</div><h3>Cada quien ve lo suyo</h3>
        <p>El <b>Administrador</b> ve todo. El <b>Cajero</b> cobra y cierra su caja, pero no ve comisiones ni ganancias. <b>Inventario</b> cuida el catálogo y no cobra.</p></article>
      <article class="tu-idea"><div class="tu-idea-n">6</div><h3>Enciendes solo lo que usas</h3>
        <p>Agenda, cotizaciones, inventario, igualas… se prenden según tu giro. Si no las necesitas, ni aparecen en tu menú.</p></article>
    </div>
  </section>

  <!-- ═══ RUTAS ═══ -->
  <section class="tu-sec" id="tu-rutas">
    <h2 class="tu-sec-tit">Elige tu ruta</h2>
    <p class="tu-sec-sub">No tienes que leerlo todo: ve directo a los capítulos de tu perfil.</p>
    <div class="tu-rutas">
      <div class="tu-ruta"><h3>Soy el dueño o el gerente</h3><p>Configuras, cobras y ves números. Léelo todo, en orden.</p>
        <div class="tu-ruta-caps"><a href="#cap-1">Empieza en el 1</a></div></div>
      <div class="tu-ruta"><h3>Cobro en mostrador</h3><p>Caja, abonos, clientes y el corte del día.</p>
        <div class="tu-ruta-caps"><a href="#cap-4">4</a><a href="#cap-6">6</a><a href="#cap-7">7</a><a href="#cap-8">8</a></div></div>
      <div class="tu-ruta"><h3>Llevo el inventario</h3><p>Catálogo, existencias y pedidos a proveedor.</p>
        <div class="tu-ruta-caps"><a href="#cap-3">3</a><a href="#cap-10">10</a></div></div>
      <div class="tu-ruta"><h3>Tengo 15 minutos</h3><p>Lo mínimo para cobrar hoy y cuadrar en la noche.</p>
        <div class="tu-ruta-caps"><a href="#cap-4">4</a><a href="#cap-8">8</a></div></div>
    </div>
  </section>

  <!-- ═══ ÍNDICE + CAPÍTULOS ═══ -->
  <div class="tu-cuerpo">
    <aside>
      <details class="tu-indice-caja" open>
        <summary>Índice · <span><span data-avance-num>0</span> de 12</span></summary>
        <nav class="tu-indice" aria-label="Capítulos del tutorial">
          <ol>
            <li><a href="#cap-1"><span class="n">1</span>Tu primer ingreso</a></li>
            <li><a href="#cap-2"><span class="n">2</span>La configuración inicial</a></li>
            <li><a href="#cap-3"><span class="n">3</span>Productos, equipo y caja</a></li>
            <li><a href="#cap-4"><span class="n">4</span>Tu primera venta</a></li>
            <li><a href="#cap-5"><span class="n">5</span>Cobros en línea</a></li>
            <li><a href="#cap-6"><span class="n">6</span>Ventas, abonos y saldos</a></li>
            <li><a href="#cap-7"><span class="n">7</span>Clientes y cobranza</a></li>
            <li><a href="#cap-8"><span class="n">8</span>El corte de caja</a></li>
            <li><a href="#cap-9"><span class="n">9</span>¿Cuánto te queda?</a></li>
            <li><a href="#cap-10"><span class="n">10</span>Herramientas para tu giro</a></li>
            <li><a href="#cap-11"><span class="n">11</span>Factura tus ventas</a></li>
            <li><a href="#cap-12"><span class="n">12</span>Equipo, plan y soporte</a></li>
          </ol>
          <ol class="tu-indice-extra">
            <li><a href="#tu-planes"><span class="n">$</span>Planes</a></li>
            <li><a href="#tu-glosario"><span class="n">Aa</span>Glosario</a></li>
            <li><a href="#tu-preguntas"><span class="n">?</span>Preguntas frecuentes</a></li>
          </ol>
        </nav>
      </details>
    </aside>

    <div class="tu-caps">

      <!-- ── 1 ── -->
      <article class="tu-cap" id="cap-1">
        <div class="tu-cap-cab"><div class="tu-cap-num">1</div><div>
          <div class="tu-meta"><span class="tu-pill">4 min</span></div>
          <h2>Tu primer ingreso</h2>
          <p class="tu-para">Para que tu cuenta quede segura y sepas entrar sin problemas.</p></div></div>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Mi cuenta</b><i>›</i><b>Mi perfil</b></span><span>(tu nombre, abajo del menú)</span></div>
        <ol class="tu-pasos">
          <li><strong>Cambia la contraseña del correo de bienvenida</strong>
            En <span class="tu-q">«Cambiar mi contraseña»</span> escribe la actual y una nueva de al menos 8 caracteres, y da <span class="tu-ui marca">Cambiar</span>. El sistema te pide entrar otra vez.</li>
          <li><strong>Pon tu foto (opcional)</strong>
            <span class="tu-q">«Mi foto»</span> › <span class="tu-ui">Elegir foto</span> › <span class="tu-ui">Guardar foto</span>. Aparece junto a tu nombre en el menú.</li>
          <li><strong>Recuerda cómo entrar</strong>
            En el campo <span class="tu-q">«Correo»</span> del inicio de sesión puedes escribir tu correo <b>o</b> tu usuario. Luego, <span class="tu-ui marca">Entrar al sistema</span>.</li>
        </ol>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>¿Trabajas de noche? Arriba a la derecha está el botón de tema para cambiar entre claro y oscuro. Se queda guardado.</div></div>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>No hay «recuperar contraseña» por correo: si eres cajero o de inventario, pídesela a tu administrador; si eres el administrador, escríbele a soporte. Y una cuenta abre una sola sesión a la vez: si entras desde otro equipo, el sistema pregunta si cierras la anterior (<span class="tu-ui">Cerrarla y entrar aquí</span>).</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> cambiaste la contraseña que te llegó por correo.</div>
          <div class="tu-pie-acc"><?= $ir('/cuenta?t=perfil', 'Ir a Mi perfil') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 2 ── -->
      <article class="tu-cap" id="cap-2">
        <div class="tu-cap-cab"><div class="tu-cap-num">2</div><div>
          <div class="tu-meta"><span class="tu-pill">5 min</span><span class="tu-pill">Administrador</span></div>
          <h2>La configuración inicial</h2>
          <p class="tu-para">Para que el sistema hable el idioma de tu negocio desde el primer día.</p></div></div>
        <div class="tu-donde"><span>Dónde:</span><span>aparece sola la primera vez que entra el administrador. Para repetirla:</span>
          <span class="tu-migas"><b>Ajustes</b><i>›</i><b>Empresa</b><i>›</i><b>Volver a hacerla</b></span></div>
        <p class="tu-txt">Son 9 preguntas rápidas que ya vienen marcadas según tu giro: toca la tarjeta que aplique y avanza sola. A la derecha ves en vivo <span class="tu-q">«Así se verá tu menú»</span>.</p>
        <div class="tu-rejilla">
          <div class="tu-mini"><span class="k">Paso 1</span><h4>¿A qué se dedica tu negocio?</h4><p>Servicios profesionales, Salud y belleza, Tienda o comercio u Otro giro.</p></div>
          <div class="tu-mini"><span class="k">Paso 2</span><h4>¿Qué vendes?</h4><p>Servicios, Productos o Las dos cosas. Con productos se enciende Inventario.</p></div>
          <div class="tu-mini"><span class="k">Paso 3</span><h4>¿Cómo te pagan?</h4><p>«Todo al momento» o «Una parte y el resto después».</p></div>
          <div class="tu-mini"><span class="k">Paso 4</span><h4>¿Tus ventas llevan IVA?</h4><p>16%, 8% o «Casi nunca».</p></div>
          <div class="tu-mini"><span class="k">Paso 5</span><h4>¿Pagas comisiones?</h4><p>Si dices que no, la sección ni aparece.</p></div>
          <div class="tu-mini"><span class="k">Paso 6</span><h4>¿Qué más te ayudaría?</h4><p>Herramientas extra: agenda, cotizaciones, igualas…</p></div>
          <div class="tu-mini"><span class="k">Paso 7</span><h4>¿Cómo ordenamos lo que vendes?</h4><p>Tus áreas: las categorías de tu catálogo.</p></div>
          <div class="tu-mini"><span class="k">Paso 8</span><h4>Que se vea como tu negocio</h4><p>Tu logotipo (opcional) y el color de tu marca.</p></div>
          <div class="tu-mini"><span class="k">Paso 9</span><h4>Así quedó tu LibertyFin</h4><p>Un resumen de todo antes de entrar.</p></div>
        </div>
        <h3>Lo que trae marcado cada giro</h3>
        <div class="tu-tabla-env">
          <table class="tu-tabla">
            <thead><tr><th>Giro</th><th>Vende</th><th>Cobro</th><th>Comisiones</th><th>Herramientas sugeridas</th></tr></thead>
            <tbody>
              <tr><td>Servicios profesionales</td><td>Servicios</td><td>Anticipo y abonos</td><td>Sí</td><td>Cotizaciones, igualas, trabajos, expediente</td></tr>
              <tr><td>Salud y belleza</td><td>Las dos cosas</td><td>Completo</td><td>Sí</td><td>Agenda, paquetes de sesiones, expediente</td></tr>
              <tr><td>Tienda o comercio</td><td>Productos</td><td>Completo</td><td>No</td><td>Inventario y compras a proveedor</td></tr>
              <tr><td>Otro giro</td><td>Servicios</td><td>Completo</td><td>No</td><td>Ninguna: las enciendes tú</td></tr>
            </tbody>
          </table>
        </div>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>«Nada de esto es obligatorio». Todo se cambia después en Ajustes. Y la guía animada, la que te señala cada sección del menú en menos de un minuto, la vuelves a ver con el botón <span class="tu-ui">Ver la guía animada</span> de arriba.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> ves tu logo y tu color en el menú.</div>
          <div class="tu-pie-acc"><?= $ir('/ajustes?t=empresa', 'Ir a Ajustes', 'ver.ajustes') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 3 ── -->
      <article class="tu-cap" id="cap-3">
        <div class="tu-cap-cab"><div class="tu-cap-num">3</div><div>
          <div class="tu-meta"><span class="tu-pill">10 min</span></div>
          <h2>Prepara tu negocio: productos, equipo y caja</h2>
          <p class="tu-para">Para que, cuando llegue tu primer cliente, todo esté a un clic.</p></div></div>

        <h3>A · Lo que vendes</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Productos</b><i>›</i><b>Dar de alta un producto</b></span></div>
        <ol class="tu-pasos">
          <li><strong>Lo básico</strong>Escribe <span class="tu-q">«Nombre»</span>, elige su <span class="tu-q">«Área»</span> y pon el <span class="tu-q">«Precio»</span>.</li>
          <li><strong>Lo que ayuda (opcional)</strong>
            <ul class="tu-sub">
              <li><span class="tu-q">«Código»</span>: para cobrarlo con lector de código de barras.</li>
              <li><span class="tu-q">«Costo»</span>: con él, <span class="tu-q">«Utilidad %»</span> calcula el precio o al revés, y ves cuánto <span class="tu-q">«Te queda por venta»</span>.</li>
              <li>Si llevas existencias: <span class="tu-q">«Lleva existencias»</span>, <span class="tu-q">«Se cuenta por»</span> (pieza, kilo, litro…) y la existencia inicial.</li>
            </ul></li>
          <li><strong>Guarda</strong>Da clic en <span class="tu-ui marca">Dar de alta</span>. Ya aparece en Caja.</li>
        </ol>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>¿Cada trabajo cuesta distinto? Deja el precio en <b>0</b>: queda como <span class="tu-q">«Precio libre»</span> y escribes el monto al vender.</div></div>

        <h3>B · Las personas que atienden</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ajustes</b><i>›</i><b>Áreas y colaboradores</b></span></div>
        <p class="tu-txt">Tus <b>colaboradores</b> son quienes atienden al cliente y ganan comisión, con su <span class="tu-q">«% usual»</span>. No necesitan usuario para entrar, así que no cuentan en el límite de tu plan.</p>

        <h3>C · Quién entra al sistema</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ajustes</b><i>›</i><b>Usuarios</b><i>›</i><b>Dar de alta un usuario</b></span></div>
        <p class="tu-txt">Nombre, usuario, correo, <b>rol</b> (Administrador, Cajero o Inventario), sucursal y una contraseña de al menos 8 caracteres. Arriba ves cuántos lugares te quedan: <span class="tu-q">«N de M usuarios de tu plan»</span>.</p>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>La contraseña del nuevo usuario se muestra <b>una sola vez</b>: anótala y dísela en persona. Los usuarios no se borran, se desactivan, y sus ventas quedan intactas.</div></div>

        <h3>D · Cómo cobra tu caja</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ajustes</b><i>›</i><b>Caja</b></span></div>
        <p class="tu-txt">Decide si el anticipo empieza en <span class="tu-q">«El total de la venta»</span> o <span class="tu-q">«Vacío»</span>, si manejas IVA y con qué tasa. Se guarda al elegir. ¿Más de un local? Agrégalo en <span class="tu-migas"><b>Ajustes</b><i>›</i><b>Sucursales</b></span>; tu empresa empieza con <b>«Matriz»</b>.</p>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> tienes al menos 3 productos con precio.</div>
          <div class="tu-pie-acc">
            <?= $ir('/servicios', 'Ir a Productos', 'ver.servicios') ?>
            <?= $ir('/usuarios', 'Ir a Usuarios', 'ver.usuarios') ?>
            <?= $ir('/ajustes?t=caja', 'Ajustes de Caja', 'ver.ajustes') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 4 ── -->
      <article class="tu-cap" id="cap-4">
        <div class="tu-cap-cab"><div class="tu-cap-num">4</div><div>
          <div class="tu-meta"><span class="tu-pill">8 min</span><span class="tu-pill">El más importante</span></div>
          <h2>Tu primera venta en Caja</h2>
          <p class="tu-para">Tu pantalla de todos los días. Cobras en segundos, completo o en partes.</p></div></div>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Caja</b></span></div>

        <div class="tu-nota info"><span class="ic">i</span><div><b class="t">Antes de cobrar: abre tu turno</b>En <span class="tu-migas"><b>Corte de caja</b><i>›</i><b>Abrir caja</b></span> escribe tu <span class="tu-q">«Fondo inicial»</span>. Si no lo abres, la venta se registra igual, pero no entra a tu corte y en la noche no te va a cuadrar.</div></div>

        <figure class="tu-maq" aria-label="Ejemplo de la pantalla de Caja">
          <div class="tu-maq-barra"><span></span><span></span><span></span><b>Caja · Venta nueva</b></div>
          <div class="tu-mq-caja">
            <div class="tu-mq-panel">
              <div class="tu-mq-fila"><span class="tu-mk">1</span><div class="tu-mq-busca">Nombre o código ( / )</div></div>
              <div class="tu-mq-prods">
                <div class="tu-mq-prod sel">Corte de cabello<b>$250.00</b></div>
                <div class="tu-mq-prod">Tinte<b>$650.00</b></div>
                <div class="tu-mq-prod sel">Shampoo 1 L<b>$400.00</b></div>
                <div class="tu-mq-prod">Peinado<b>$300.00</b></div>
                <div class="tu-mq-prod">Manicure<b>$220.00</b></div>
                <div class="tu-mq-prod">Asesoría<b>Precio libre</b></div>
              </div>
            </div>
            <div class="tu-mq-panel tu-mq-ticket">
              <b>Ticket · 2 conceptos</b>
              <div class="tu-mq-ln"><span>Corte de cabello</span><b>$250.00</b></div>
              <div class="tu-mq-ln"><span>Shampoo 1 L</span><b>$400.00</b></div>
              <div class="tu-mq-fila"><span class="tu-mk">2</span><div class="tu-mq-campo">Cliente (opcional)</div></div>
              <div class="tu-mq-fila"><span class="tu-mk">3</span><div class="tu-mq-campo">Especialista</div></div>
              <div class="tu-mq-fila"><span class="tu-mk">4</span><div class="tu-mq-pagos"><span class="on">Efectivo</span><span>Tarjeta</span><span>SPEI</span></div></div>
              <div class="tu-mq-total"><span>Total</span><span>$650.00</span></div>
              <div class="tu-mq-fila"><span class="tu-mk">5</span><div class="tu-mq-cobrar">Cobrar $650.00</div></div>
            </div>
          </div>
          <figcaption>A la izquierda, tu catálogo; a la derecha, el ticket y cómo paga. Los números son los pasos de abajo.</figcaption>
        </figure>

        <ol class="tu-pasos">
          <li><strong>Agrega lo que se lleva</strong>Toca los productos y se suman al <span class="tu-q">«Ticket»</span>. Cambia la cantidad con <span class="tu-ui">−</span> y <span class="tu-ui">+</span>, o toca el precio para editarlo. Atajos: <kbd>/</kbd> busca, <kbd>Enter</kbd> agrega el primero, <kbd>Esc</kbd> limpia. También funciona con lector de código de barras.</li>
          <li><strong>¿Para quién es?</strong>Escribe en <span class="tu-q">«Cliente (opcional)»</span>: desde 2 letras te sugiere. Si es nuevo, se da de alta solo al cobrar. Sin cliente, queda como <b>«Público general»</b>.</li>
          <li><strong>¿Quién lo atendió?</strong>Elige el <span class="tu-q">«Especialista»</span> si llevas sus comisiones.</li>
          <li><strong>¿Cómo paga?</strong>
            <ul class="tu-sub">
              <li><b>Efectivo completo:</b> escribe <span class="tu-q">«¿Con cuánto paga?»</span> y te dice el <b>Cambio</b>.</li>
              <li><b>Solo una parte:</b> <span class="tu-ui">Solo una parte</span> › <span class="tu-q">«¿Cuánto deja hoy?»</span> (atajos <span class="tu-ui">50%</span> y <span class="tu-ui">Completo</span>). El resto queda como saldo.</li>
              <li><b>Tarjeta con tu terminal o transferencia ya recibida:</b> elige Tarjeta o SPEI, marca <span class="tu-q">«Ya me pagaron — no generar liga de pago»</span> y, si quieres, anota banco y referencia.</li>
            </ul></li>
          <li><strong>Revisa y confirma</strong><span class="tu-ui marca">Cobrar $650.00</span> abre <span class="tu-q">«Revisa antes de registrar»</span>: te avisa si falta el cliente, el especialista o si queda saldo. Si todo está bien, <span class="tu-ui marca">Confirmar y registrar</span>.</li>
          <li><strong>Entrega el ticket</strong><span class="tu-ui">Imprimir ticket</span> (80 mm) y luego <span class="tu-ui">Siguiente venta</span>.</li>
        </ol>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Tres trucos de cajero experto</b>
          <ul class="tu-sub">
            <li>El interruptor <span class="tu-q">«IVA 16%»</span> <b>no cambia el total</b>: tus precios ya lo incluyen. Solo separa subtotal e IVA en el ticket.</li>
            <li>¿Regresa por algo más y todavía te debe? <span class="tu-q">«Agregar a una venta que ya existe»</span> lo suma a su cuenta en vez de abrir otra venta.</li>
            <li>Las promociones se aplican solas. Si tienes cupones, aparece <span class="tu-q">«Código de promoción»</span>.</li>
          </ul></div></div>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>Una venta registrada no se elimina. Si hubo un error, el administrador la cancela con un motivo (capítulo 6).</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> imprimiste tu primer ticket.</div>
          <div class="tu-pie-acc">
            <?= $ir('/corte', 'Abrir mi caja', 'ver.corte', ['sec' => 'corte']) ?>
            <?= $ir('/caja', 'Ir a Caja', 'cobrar') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 5 ── -->
      <article class="tu-cap" id="cap-5">
        <div class="tu-cap-cab"><div class="tu-cap-num">5</div><div>
          <div class="tu-meta"><span class="tu-pill">5 min</span><span class="tu-pill op">Opcional</span></div>
          <h2>Cobra en línea: tarjeta, SPEI y tienda</h2>
          <p class="tu-para">Para que tu cliente pague a distancia y el sistema registre el pago solo.</p></div></div>
        <div class="tu-nota info"><span class="ic">i</span><div><b class="t">¿Cuándo lo tengo?</b>Cuando LibertyFin activa los cobros en línea en tu cuenta. Para eso, el administrador completa en <b>Mi cuenta</b> los <span class="tu-q">«Datos fiscales»</span>, <span class="tu-q">«Cobrar con tarjeta»</span> y los <span class="tu-q">«Documentos»</span>.</div></div>

        <h3>Desde Caja</h3>
        <ol class="tu-pasos">
          <li><strong>Elige la forma en línea</strong>Tarjeta, SPEI o <span class="tu-q">«Efectivo (tienda)»</span>, <b>sin</b> marcar «Ya me pagaron». El botón cambia a <span class="tu-ui marca">Confirmar y generar liga</span>.</li>
          <li><strong>Compártelo con tu cliente</strong>
            <ul class="tu-sub">
              <li><b>Tarjeta:</b> un código QR y una liga para <span class="tu-ui">Copiar</span> o <span class="tu-ui">Abrir la página</span>.</li>
              <li><b>SPEI:</b> una CLABE para transferir el monto exacto.</li>
              <li><b>Tienda:</b> una referencia para OXXO y tiendas participantes, con <span class="tu-ui">Ver el comprobante</span>.</li>
            </ul></li>
          <li><strong>El sistema se entera solo</strong>Cuando el cliente paga verás <span class="tu-q">«¡Pagado! El abono ya quedó aplicado.»</span></li>
        </ol>

        <h3>Desde Ligas de pago</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ligas de pago</b><i>›</i><b>Generar una liga de pago</b></span></div>
        <p class="tu-txt">Para cobrar el saldo de una venta o un cobro suelto: <span class="tu-q">«¿Para qué venta?»</span>, <span class="tu-q">«Monto»</span>, <span class="tu-q">«Concepto»</span>, cómo puede pagar y <span class="tu-q">«Vigencia»</span> (1 a 30 días) › <span class="tu-ui marca">Generar</span>. Cada liga muestra su estado: <b>Esperando</b>, <b>Pagada</b>, <b>Por aprobar</b> o <b>Venció</b>.</p>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>¿Quieres revisar cada pago antes de que cuente? <span class="tu-migas"><b>Ajustes</b><i>›</i><b>Empresa</b><i>›</i><b>Pagos en línea</b></span> › <span class="tu-q">«Requieren aprobación»</span>: esperan en <span class="tu-q">«Pagos por aprobar»</span> hasta que des <span class="tu-ui">Aprobar</span>.</div></div>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>En SPEI pide el <b>monto exacto</b>: una cantidad distinta no se asocia sola. Y en línea se cobra el total; si solo deja una parte, cóbrala en efectivo con «Solo una parte».</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> generaste una liga y la ves «Esperando».</div>
          <div class="tu-pie-acc">
            <?= $ir('/ligas', 'Ir a Ligas de pago', 'ver.ligas', ['si' => 'spei', 'sec' => 'ligas']) ?>
            <?= $esAdmin ? $ir('/cuenta?t=documentos', 'Mis documentos') : '' ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 6 ── -->
      <article class="tu-cap" id="cap-6">
        <div class="tu-cap-cab"><div class="tu-cap-num">6</div><div>
          <div class="tu-meta"><span class="tu-pill">6 min</span></div>
          <h2>Ventas, abonos y saldos</h2>
          <p class="tu-para">Para saber qué vendiste, qué ya te pagaron y qué te deben, venta por venta.</p></div></div>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ventas</b></span></div>
        <figure class="tu-maq" aria-label="Cómo se numeran los pagos de una venta">
          <div class="tu-maq-barra"><span></span><span></span><span></span><b>Así se numeran los pagos</b></div>
          <div class="tu-folio">
            <div class="tu-folio-venta"><code>20261016143022</code><span>Venta de $1,500 · el folio es la fecha y hora en que se registró</span></div>
            <div class="tu-folio-pagos">
              <div class="tu-folio-p"><code>-01</code>Anticipo $500<br>Debe $1,000</div>
              <div class="tu-folio-p"><code>-02</code>Abono $500<br>Debe $500</div>
              <div class="tu-folio-p fin"><code>-03</code>Abono $500<br><b>Liquidada</b></div>
            </div>
          </div>
          <figcaption>Si se cancela un pago, su número no se reusa. Buscar el folio de la venta encuentra todos sus pagos.</figcaption>
        </figure>
        <ol class="tu-pasos">
          <li><strong>Encuentra la venta</strong>Filtra por fechas o por estado: <span class="tu-q">«Liquidadas»</span>, <span class="tu-q">«Con saldo»</span> o <span class="tu-q">«Canceladas»</span>. Si buscas un nombre o folio, busca en todo tu historial.</li>
          <li><strong>Ábrela</strong>Ves lo <b>Cobrado</b>, el <b>Saldo</b> y la lista de pagos con su subfolio.</li>
          <li><strong>Registra un abono</strong>El monto ya trae el saldo. Ajusta fecha, método, banco y referencia y da <span class="tu-ui marca">Registrar</span>. Verás <span class="tu-q">«Abono registrado. Queda un saldo de…»</span> o <span class="tu-q">«…La venta queda liquidada.»</span></li>
        </ol>
        <h3>Si algo salió mal (solo el administrador)</h3>
        <ul class="tu-sub">
          <li><b>Cancelar un pago:</b> la <span class="tu-ui">×</span> junto al pago, con un motivo. Las comisiones se recalculan solas.</li>
          <li><b>Cancelar la venta:</b> <span class="tu-ui">Cancelar venta</span>, con un motivo de al menos 5 letras. Se cancelan sus cobros y comisiones, y lo vendido regresa al inventario.</li>
        </ul>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>Cancelar una venta <b>no se puede deshacer</b>, y el sistema te recuerda devolverle al cliente lo que pagó. Tampoco se puede abonar más que el saldo.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> registraste un abono y viste su subfolio <b>-02</b>.</div>
          <div class="tu-pie-acc"><?= $ir('/ventas', 'Ir a Ventas', 'ver.ventas') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 7 ── -->
      <article class="tu-cap" id="cap-7">
        <div class="tu-cap-cab"><div class="tu-cap-num">7</div><div>
          <div class="tu-meta"><span class="tu-pill">5 min</span></div>
          <h2>Clientes y cobranza</h2>
          <p class="tu-para">Para conocer a tus clientes y saber, cada mañana, a quién hay que llamarle.</p></div></div>
        <h3>Tus clientes</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Clientes</b></span></div>
        <p class="tu-txt">Da de alta con <span class="tu-q">«Dar de alta un cliente»</span>, aunque también se crean solos al cobrar en Caja. Toca un nombre para abrir su <b>ficha</b>: cuánto te ha comprado, cuánto <b>debe</b>, sus últimas compras y botones directos a <span class="tu-ui">WhatsApp</span> y <span class="tu-ui">Nueva venta</span>.</p>
        <h3>La cobranza del día <span class="tu-pill">Administrador</span></h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Cobranza</b></span></div>
        <div class="tu-rejilla">
          <div class="tu-mini"><span class="k">1 a 30 días</span><h4>Fresco</h4><p>Se cobra solo. No hace falta insistir.</p></div>
          <div class="tu-mini"><span class="k">31 a 60 días</span><h4>Recuérdales</h4><p>Un mensaje amable a tiempo evita que se enfríe.</p></div>
          <div class="tu-mini"><span class="k">Más de 60 días</span><h4>Háblales hoy</h4><p>Lo más viejo es lo más difícil de cobrar.</p></div>
        </div>
        <p class="tu-txt">Ordena por <span class="tu-q">«Lo más viejo primero»</span> y ve de arriba hacia abajo. Para cobrar, abre la venta y registra el abono.</p>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Por qué funciona</b>Los días se cuentan <b>desde el último abono</b>, no desde la venta. Un cliente que abona seguido no sale urgente aunque deba mucho; uno que dejó de pagar, sí.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> sabes qué cliente tiene el saldo más viejo.</div>
          <div class="tu-pie-acc">
            <?= $ir('/clientes', 'Ir a Clientes', 'ver.clientes') ?>
            <?= $ir('/cobranza', 'Ir a Cobranza', 'ver.cobranza', ['sec' => 'cobranza']) ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 8 ── -->
      <article class="tu-cap" id="cap-8">
        <div class="tu-cap-cab"><div class="tu-cap-num">8</div><div>
          <div class="tu-meta"><span class="tu-pill">4 min</span></div>
          <h2>Cierra el día: el corte de caja</h2>
          <p class="tu-para">Para cuadrar el efectivo de tu cajón y saber, sin dudas, si sobra o falta.</p></div></div>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Corte de caja</b></span></div>
        <figure class="tu-maq" aria-label="Cómo se calcula el corte">
          <div class="tu-maq-barra"><span></span><span></span><span></span><b>La cuenta del cajón</b></div>
          <div class="tu-ecu">
            <div class="tu-ecu-c"><small>Fondo inicial</small><b>$500</b></div>
            <div class="tu-ecu-op">+</div>
            <div class="tu-ecu-c"><small>Efectivo cobrado</small><b>$3,200</b></div>
            <div class="tu-ecu-op">=</div>
            <div class="tu-ecu-c res"><small>Debe haber en el cajón</small><b>$3,700</b></div>
          </div>
          <figcaption>Tarjeta y transferencia se muestran aparte: no pasan por el cajón.</figcaption>
        </figure>
        <ol class="tu-pasos">
          <li><strong>Al empezar el turno</strong><span class="tu-ui marca">Abrir caja</span> con tu <span class="tu-q">«Fondo inicial»</span>.</li>
          <li><strong>Durante el día</strong>Cobra normal. En <span class="tu-q">«Turno actual»</span> ves cuánto <span class="tu-q">«Debe haber en el cajón»</span>.</li>
          <li><strong>Al cerrar</strong>Cuenta el efectivo y escríbelo en <span class="tu-q">«Cuánto hay realmente»</span>. Te responde <span class="tu-q">«Cuadra exacto.»</span> o cuánto sobra o falta.</li>
          <li><strong>Explica la diferencia</strong>Si no cuadra, escribe en <span class="tu-q">«Nota»</span> a qué se debe (es obligatoria) y da <span class="tu-ui marca">Cerrar caja</span>.</li>
        </ol>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>La pestaña <span class="tu-q">«Historial»</span> te dice cuántos cortes cuadraron y los sobrantes y faltantes acumulados. Si siempre falta un poco, ahí lo vas a notar.</div></div>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>Cada usuario tiene su propio turno y no puede abrir uno nuevo mientras tenga uno abierto. Ciérralo siempre antes de irte.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> cerraste tu primer corte.</div>
          <div class="tu-pie-acc"><?= $ir('/corte', 'Ir a Corte de caja', 'ver.corte', ['sec' => 'corte']) ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 9 ── -->
      <article class="tu-cap" id="cap-9">
        <div class="tu-cap-cab"><div class="tu-cap-num">9</div><div>
          <div class="tu-meta"><span class="tu-pill">7 min</span><span class="tu-pill">Administrador</span></div>
          <h2>¿Cuánto te queda? Gastos, comisiones y reportes</h2>
          <p class="tu-para">La cifra que casi nadie tiene clara: lo que te queda después de pagar todo.</p></div></div>
        <figure class="tu-maq" aria-label="De lo cobrado a lo que queda">
          <div class="tu-maq-barra"><span></span><span></span><span></span><b>De lo cobrado a lo que queda (ejemplo de un mes)</b></div>
          <div class="tu-cascada">
            <div class="tu-cas base"><span>Cobrado</span><div class="tu-cas-carril"><i style="left:0;width:100%"></i></div><em>$50,000</em></div>
            <div class="tu-cas menos"><span>− Gastos de operación</span><div class="tu-cas-carril"><i style="left:94%;width:6%"></i></div><em>$3,000</em></div>
            <div class="tu-cas menos"><span>− Gastos generales</span><div class="tu-cas-carril"><i style="left:70%;width:24%"></i></div><em>$12,000</em></div>
            <div class="tu-cas menos"><span>− Comisiones</span><div class="tu-cas-carril"><i style="left:58%;width:12%"></i></div><em>$6,000</em></div>
            <div class="tu-cas menos"><span>− IVA</span><div class="tu-cas-carril"><i style="left:44.2%;width:13.8%"></i></div><em>$6,900</em></div>
            <div class="tu-cas queda"><span>= Queda</span><div class="tu-cas-carril"><i style="left:0;width:44.2%"></i></div><em>$22,100</em></div>
          </div>
          <figcaption>Así se ve el bloque «De dónde a dónde» de Reportes. Las cifras son de ejemplo.</figcaption>
        </figure>
        <h3>Gastos</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Gastos</b><i>›</i><b>Registrar un gasto</b></span></div>
        <ul class="tu-sub">
          <li><b>Generales</b> (renta, luz, nómina): bajan lo que te queda, pero no tocan comisiones.</li>
          <li><b>De operación</b> (lo que gastaste para una venta concreta): se capturan en Caja, en <span class="tu-q">«Más datos»</span>, y se descuentan antes de calcular la comisión.</li>
        </ul>
        <h3>Comisiones</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Comisiones</b></span></div>
        <p class="tu-txt">La regla de oro: <b>la comisión se libera conforme el cliente paga</b>. Si la venta va a medias, la comisión también. Se calcula sin IVA, sin costo y descontando los gastos de operación.</p>
        <ul class="tu-sub">
          <li>Asígnala desde la venta (<span class="tu-q">«Asignar comisión»</span>) o a muchas a la vez con <span class="tu-ui">Asignar en lote</span>.</li>
          <li>Revisa <span class="tu-q">«Comisiones sin dueño»</span>: ventas a las que nadie tiene asignado su porcentaje.</li>
        </ul>
        <h3>Reportes</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Reportes</b></span></div>
        <p class="tu-txt">Arriba, en grande: <span class="tu-q">«Entraron $X y quedaron $Y»</span>. Abajo, el detalle por área, colaborador, producto, cliente, forma de pago y día por día. Para tu contador: <span class="tu-ui">Excel completo</span> o <span class="tu-ui">Imprimir…</span>.</p>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>Los reportes cuentan <b>lo cobrado, no lo vendido</b>. Si vendiste mucho pero no has cobrado, todavía no se ve aquí, y así debe ser.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> viste tu cifra «Queda» del mes.</div>
          <div class="tu-pie-acc">
            <?= $ir('/gastos', 'Ir a Gastos', 'ver.gastos', ['sec' => 'gastos']) ?>
            <?= $ir('/comisiones', 'Ir a Comisiones', 'ver.comisiones', ['sec' => 'comisiones']) ?>
            <?= $ir('/reportes', 'Ir a Reportes', 'ver.reportes', ['sec' => 'reportes']) ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 10 ── -->
      <article class="tu-cap" id="cap-10">
        <div class="tu-cap-cab"><div class="tu-cap-num">10</div><div>
          <div class="tu-meta"><span class="tu-pill">6 min</span><span class="tu-pill op">Opcional</span></div>
          <h2>Herramientas para tu giro</h2>
          <p class="tu-para">Enciende solo lo que tu negocio necesita. Lo demás no estorba porque ni aparece.</p></div></div>
        <div class="tu-donde"><span>Dónde se encienden:</span><span class="tu-migas"><b>Ajustes</b><i>›</i><b>Módulos</b></span><span>(las que tu plan no incluye salen con candado)</span></div>
        <div class="tu-herr">
          <div class="tu-mini"><span class="k">Comercio</span><h4>Inventario</h4>
            <p>Cuánto queda de cada producto en cada sucursal. Caja lo descuenta al cobrar.</p>
            <ol><li><span class="tu-q">«Registrar entrada, salida o conteo»</span>.</li><li>Elige qué pasó: llegó mercancía, salió sin venderse, lo conté o pasó a otra sucursal.</li><li>Vigila <span class="tu-q">«Bajo su mínimo»</span> y <span class="tu-q">«Agotados»</span>.</li></ol>
            <?= $ir('/inventario', 'Ir a Inventario', 'ver.inventario', ['mod' => 'inventario']) ?></div>
          <div class="tu-mini"><span class="k">Comercio</span><h4>Compras a proveedor</h4>
            <p>El pedido y la recepción, sin capturar dos veces.</p>
            <ol><li><span class="tu-q">«Nuevo pedido a proveedor»</span> y, si quieres, <span class="tu-q">«Llenar con lo que está bajo su mínimo»</span>.</li><li>Cuando llegue: <span class="tu-ui">Recibir mercancía</span>.</li><li>Entra solo al inventario y, si lo marcas, se registra como gasto.</li></ol>
            <?= $ir('/compras', 'Ir a Compras', 'ver.compras', ['mod' => 'compras']) ?></div>
          <div class="tu-mini"><span class="k">Servicios</span><h4>Cotizaciones</h4>
            <p>Presupuestos que se convierten en venta.</p>
            <ol><li><span class="tu-q">«Nueva cotización»</span> con productos de tu catálogo.</li><li><span class="tu-ui">Imprimir / PDF</span> y envíala.</li><li>Si acepta: <span class="tu-ui">Cobrar en Caja</span> y llega armada.</li></ol>
            <?= $ir('/cotizaciones', 'Ir a Cotizaciones', 'ver.cotizaciones', ['mod' => 'cotizaciones']) ?></div>
          <div class="tu-mini"><span class="k">Servicios</span><h4>Igualas</h4>
            <p>Lo que un cliente te paga cada mes, sin acordarte tú.</p>
            <ol><li><span class="tu-q">«Nueva iguala»</span>: cliente, monto al mes y día de cobro.</li><li>Cada mes, ese día, se genera sola la venta.</li><li>Aparece en Cobranza para darle seguimiento.</li></ol>
            <?= $ir('/igualas', 'Ir a Igualas', 'ver.igualas', ['mod' => 'igualas']) ?></div>
          <div class="tu-mini"><span class="k">Servicios</span><h4>Trabajos</h4>
            <p>En qué va cada encargo, en un tablero.</p>
            <ol><li>Cada venta aparece como trabajo <b>Pendiente</b>.</li><li>Muévelo a <b>En proceso</b>, <b>Listo para entregar</b> y <b>Entregado</b>.</li><li>Ponle responsable y fecha de entrega.</li></ol>
            <?= $ir('/seguimiento', 'Ir a Trabajos', 'ver.seguimiento', ['mod' => 'seguimiento']) ?></div>
          <div class="tu-mini"><span class="k">Salud y belleza</span><h4>Agenda de citas</h4>
            <p>Las citas del día por especialista.</p>
            <ol><li>Toca un espacio libre o usa <span class="tu-q">«Agendar una cita»</span>.</li><li><span class="tu-ui">Recordar</span> abre WhatsApp con el mensaje listo.</li><li><span class="tu-ui">Cobrar</span> la lleva a Caja y queda atendida.</li></ol>
            <?= $ir('/agenda', 'Ir a Agenda', 'ver.agenda', ['mod' => 'agenda']) ?></div>
          <div class="tu-mini"><span class="k">Salud y belleza</span><h4>Paquetes de sesiones</h4>
            <p>Vende 10 sesiones hoy y descuéntalas en cada visita.</p>
            <ol><li>En Productos marca <span class="tu-q">«Es un paquete de sesiones»</span>.</li><li>Véndelo en Caja (con cliente).</li><li>En cada visita: <span class="tu-q">«Usar sesión de su paquete»</span>.</li></ol></div>
          <div class="tu-mini"><span class="k">Siempre disponible</span><h4>Importar tu Excel</h4>
            <p>Trae las ventas que llevabas en hojas de cálculo.</p>
            <ol><li><span class="tu-ui">Descargar ejemplo</span> para ver el formato.</li><li>Sube tu hoja y da <span class="tu-ui">Revisar</span>: corrige lo que marque.</li><li>Guarda. Subirla dos veces no duplica nada.</li></ol>
            <?= $ir('/importar', 'Ir a Importar', 'importar.ventas') ?></div>
        </div>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>Con <b>Expediente de clientes</b> encendido, la ficha de cada cliente guarda notas y archivos (fotos y PDF) que solo ve tu equipo. Ideal para consultorios y despachos.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> encendiste las herramientas de tu giro.</div>
          <div class="tu-pie-acc"><?= $ir('/ajustes?t=modulos', 'Ir a Módulos', 'ver.ajustes') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 11 ── -->
      <article class="tu-cap" id="cap-11">
        <div class="tu-cap-cab"><div class="tu-cap-num">11</div><div>
          <div class="tu-meta"><span class="tu-pill">4 min</span><span class="tu-pill op">Opcional</span><span class="tu-pill">Administrador</span></div>
          <h2>Factura tus ventas (CFDI)</h2>
          <p class="tu-para">Timbra la factura de una venta sin salir de LibertyFin, con su PDF y su XML.</p></div></div>
        <div class="tu-nota info"><span class="ic">i</span><div><b class="t">Antes de tu primera factura</b>
          <ul class="tu-sub">
            <li><span class="tu-migas"><b>Mi cuenta</b><i>›</i><b>Datos fiscales</b></span>: tipo de persona, RFC, CP fiscal, razón social y régimen. Tienen que coincidir <b>exactamente</b> con tu constancia.</li>
            <li><span class="tu-migas"><b>Mi cuenta</b><i>›</i><b>Documentos</b></span>: identificación (frente y reverso), estado de cuenta, comprobante de domicilio y constancia. Se revisan en 24 a 72 horas.</li>
          </ul></div></div>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Facturación</b></span></div>
        <ol class="tu-pasos">
          <li><strong>Elige la venta</strong>En <span class="tu-q">«Ventas del periodo»</span> cada venta dice cómo se factura: <b>PUE</b> si ya está pagada completa, <b>PPD</b> si todavía tiene saldo.</li>
          <li><strong>Timbra</strong><span class="tu-ui marca">Timbrar</span> y descarga el <span class="tu-ui">PDF</span> y el <span class="tu-ui">XML</span>.</li>
          <li><strong>Si fue PPD, timbra cada abono</strong>Conforme te pagan, cada abono se timbra como complemento de pago en <span class="tu-ui">Complementos</span>.</li>
        </ol>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>La venta necesita un cliente con RFC. Una de <b>«Público general»</b> sale como «Sin cliente» y no se puede timbrar.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> timbraste tu primera factura.</div>
          <div class="tu-pie-acc">
            <?= $esAdmin ? $ir('/cuenta?t=fiscales', 'Mis datos fiscales') : '' ?>
            <?= $ir('/facturacion', 'Ir a Facturación', 'ver.facturacion', ['si' => 'facturapi', 'sec' => 'facturacion']) ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ── 12 ── -->
      <article class="tu-cap" id="cap-12">
        <div class="tu-cap-cab"><div class="tu-cap-num">12</div><div>
          <div class="tu-meta"><span class="tu-pill">5 min</span></div>
          <h2>Tu equipo, tu plan y soporte</h2>
          <p class="tu-para">Quién puede hacer qué, cómo renuevas y a dónde pedir ayuda.</p></div></div>
        <h3>Qué puede hacer cada rol</h3>
        <div class="tu-tabla-env">
          <table class="tu-tabla">
            <thead><tr><th>Rol</th><th>Puede</th><th>No ve</th></tr></thead>
            <tbody>
              <tr><td>Administrador</td><td>Todo: configurar, cobrar, cancelar ventas y pagos, asignar comisiones, ver reportes, facturar y pagar el plan.</td><td>—</td></tr>
              <tr><td>Cajero</td><td>Cobrar en Caja, abrir y cerrar su caja, registrar abonos, atender clientes, generar ligas de pago, cotizar y agendar.</td><td>Comisiones, gastos, reportes, cobranza, facturación y ajustes.</td></tr>
              <tr><td>Inventario</td><td>Mantener productos y promociones, inventario y compras, y registrar gastos.</td><td>No cobra ni borra gastos.</td></tr>
            </tbody>
          </table>
        </div>
        <h3>Tu plan <span class="tu-pill">Administrador</span></h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Mi cuenta</b><i>›</i><b>Plan</b></span></div>
        <p class="tu-txt">Ves cuántos días te quedan. En <span class="tu-q">«Elige tu plan»</span> escoges <b>Mensual</b> o <b>Anual (−20%)</b> y pagas con tarjeta, SPEI o en tienda. Sin permanencia: lo que pagues se suma a los días que te queden.</p>
        <div class="tu-nota ojo"><span class="ic">!</span><div><b class="t">Ojo</b>Desde 15 días antes del vencimiento ves un aviso para renovar. Si vence, el administrador solo puede entrar a <b>Mi cuenta › Plan</b> hasta renovar, y el resto del equipo ve un aviso para pedírselo. Tu información no se pierde y el acceso vuelve solo en cuanto se aprueba el pago.</div></div>
        <h3>Cuando necesites ayuda</h3>
        <div class="tu-donde"><span>Dónde:</span><span class="tu-migas"><b>Ayuda</b></span><span>o la burbuja <b>«Soporte»</b> en cualquier pantalla</span></div>
        <ol class="tu-pasos">
          <li><strong>Cuéntanos qué pasa</strong>En <span class="tu-q">«Reportar un problema»</span>: qué pasa, con qué tiene que ver y el detalle.</li>
          <li><strong>Adjunta una captura</strong>Cópiala y pégala con <kbd>Ctrl</kbd>+<kbd>V</kbd>, o arrástrala. Ahorra muchas preguntas.</li>
          <li><strong>Envía y sigue la conversación</strong><span class="tu-ui marca">Enviar reporte</span> te da un folio. Ve su estado: <b>Lo estamos viendo</b>, <b>Trabajando en ello</b>, <b>Esperamos tu respuesta</b> o <b>Resuelto</b>. Con <span class="tu-ui">Avisarme cuando respondan</span> te llega un aviso aunque cierres LibertyFin.</li>
        </ol>
        <div class="tu-nota tip"><span class="ic">✓</span><div><b class="t">Consejo</b>Entre más concreto, más rápido se resuelve: qué hiciste, qué viste y qué esperabas ver. Si un número no cuadra, dinos cuál y cuánto debería ser.</div></div>
        <div class="tu-pie">
          <div class="tu-listo">🎯 <b>Listo cuando:</b> sabes dónde pedir ayuda.</div>
          <div class="tu-pie-acc">
            <?= $ir('/usuarios', 'Ir a Usuarios', 'ver.usuarios') ?>
            <?= $esAdmin ? $ir('/cuenta?t=plan', 'Ver mi plan') : '' ?>
            <?= $ir('/ayuda', 'Ir a Ayuda', 'abrir.ticket') ?>
            <button type="button" class="tu-visto" data-visto aria-pressed="false"><span>Marcar como visto</span></button></div>
        </div>
      </article>

      <!-- ═══ PLANES ═══ -->
      <section class="tu-sec" id="tu-planes">
        <h2 class="tu-sec-tit">Planes</h2>
        <p class="tu-sec-sub">Todos traen Caja, ventas, clientes, cobranza, corte, gastos, productos, reportes, inventario y comisiones. Cambia cuántas personas entran y qué herramientas extra puedes encender. Precios por mes; en anual, 20% menos.</p>
        <div class="tu-planes">
          <div class="tu-plan"><span class="nom">Básico</span><span class="pre">$299 <small>MXN/mes</small></span><span class="us">1 usuario</span>
            <ul><li>Todo lo esencial</li><li>Cotizaciones</li><li>Expediente de clientes</li></ul><span class="para">Para quien atiende solo su negocio.</span></div>
          <div class="tu-plan"><span class="nom">Profesional</span><span class="pre">$599 <small>MXN/mes</small></span><span class="us">4 usuarios</span>
            <ul><li>Todas las herramientas de tu giro</li><li>Agenda, igualas, compras y más</li></ul><span class="para">Para un equipo pequeño.</span></div>
          <div class="tu-plan dest"><span class="nom">Empresarial · Más popular</span><span class="pre">$999 <small>MXN/mes</small></span><span class="us">6 usuarios</span>
            <ul><li>Todas las herramientas</li><li>Pasarela de pago y SPEI</li></ul><span class="para">Para cobrar en línea con tu equipo.</span></div>
          <div class="tu-plan"><span class="nom">Empresarial Plus</span><span class="pre">$1,499 <small>MXN/mes</small></span><span class="us">10 usuarios</span>
            <ul><li>Todas las herramientas</li><li>Pasarela, SPEI y tarjeta de crédito</li><li>500 timbres CFDI</li></ul><span class="para">Para varias sucursales.</span></div>
        </div>
      </section>

      <!-- ═══ GLOSARIO ═══ -->
      <section class="tu-sec" id="tu-glosario">
        <h2 class="tu-sec-tit">Glosario</h2>
        <p class="tu-sec-sub">Las palabras que vas a ver en el sistema.</p>
        <dl class="tu-glosario">
          <div><dt>Anticipo</dt><dd>Lo que el cliente deja hoy cuando no paga completo.</dd></div>
          <div><dt>Abono</dt><dd>Cada pago que llega después, hasta liquidar la venta.</dd></div>
          <div><dt>Saldo</dt><dd>Lo que todavía te deben de una venta: total menos lo pagado.</dd></div>
          <div><dt>Liquidada</dt><dd>Venta pagada por completo. Saldo en cero.</dd></div>
          <div><dt>Folio y subfolio</dt><dd>El folio identifica la venta; el subfolio (-01, -02…) cada uno de sus pagos.</dd></div>
          <div><dt>Público general</dt><dd>Una venta registrada sin cliente.</dd></div>
          <div><dt>Precio libre</dt><dd>Producto con precio en 0: el monto se escribe al vender.</dd></div>
          <div><dt>Especialista / colaborador</dt><dd>Quien atendió la venta y gana comisión. No necesita usuario.</dd></div>
          <div><dt>Fondo inicial</dt><dd>El efectivo con el que abres tu caja al empezar el turno.</dd></div>
          <div><dt>Corte de caja</dt><dd>El cierre del turno: comparar lo que debe haber en el cajón con lo que hay.</dd></div>
          <div><dt>Liga de pago</dt><dd>Un enlace o QR para que el cliente pague con tarjeta desde su celular.</dd></div>
          <div><dt>CLABE</dt><dd>Los 18 dígitos a los que tu cliente transfiere por SPEI.</dd></div>
          <div><dt>Gasto de operación</dt><dd>Lo que gastaste para una venta concreta. Se descuenta antes de la comisión.</dd></div>
          <div><dt>Comisión devengada</dt><dd>La parte de la comisión que ya se ganó porque el cliente ya pagó esa parte.</dd></div>
          <div><dt>PUE y PPD</dt><dd>Formas de facturar: PUE si se pagó en una sola exhibición; PPD si se paga en parcialidades.</dd></div>
          <div><dt>Complemento de pago</dt><dd>El comprobante fiscal que se timbra por cada abono de una factura PPD.</dd></div>
        </dl>
      </section>

      <!-- ═══ PREGUNTAS ═══ -->
      <section class="tu-sec" id="tu-preguntas">
        <h2 class="tu-sec-tit">Preguntas frecuentes</h2>
        <p class="tu-sec-sub">Lo que todos preguntan la primera semana.</p>
        <div class="tu-faq">
          <details><summary>¿Lo puedo usar en el celular?</summary>
            <div>Sí. LibertyFin funciona en el navegador de tu computadora, tablet o celular, sin instalar nada. En el celular, abajo tienes una barra con tus secciones principales y el botón «Más».</div></details>
          <details><summary>¿Qué pasa cuando vence mi plan?</summary>
            <div>Desde 15 días antes ves un aviso. Si vence, solo el administrador puede entrar a Mi cuenta › Plan para renovar. Tu información se queda tal cual y el acceso vuelve en cuanto se aprueba el pago.</div></details>
          <details><summary>¿Mi información está separada de otros negocios?</summary>
            <div>Sí. Cada empresa tiene su propia base de datos, y dentro de tu empresa cada rol ve solo lo que le toca.</div></details>
          <details><summary>¿Puedo traer lo que llevaba en Excel?</summary>
            <div>Sí, con Importar. Descarga el ejemplo, sube tu hoja, corrige lo que el sistema marque y guarda. Si subes el mismo archivo dos veces, no se duplica nada.</div></details>
          <details><summary>¿Qué pasa si me equivoco en una venta?</summary>
            <div>No se borra: el administrador la cancela con un motivo; sus cobros y comisiones se cancelan y lo vendido regresa al inventario. Queda registrado quién lo hizo y cuándo.</div></details>
          <details><summary>¿Mis cajeros ven cuánto gano?</summary>
            <div>No. El cajero cobra, registra abonos y cierra su caja, pero no ve comisiones, gastos ni reportes.</div></details>
          <details><summary>¿Puedo cobrar una parte hoy y el resto después?</summary>
            <div>Sí. En Caja usa «Solo una parte». El saldo queda en la venta, lo ves en Ventas y en Cobranza, y cada abono tiene su propio folio.</div></details>
          <details><summary>¿Puedo cobrar con tarjeta?</summary>
            <div>Con tu terminal, sí: en Caja elige Tarjeta y marca «Ya me pagaron». Para cobrar en línea (liga, QR, SPEI o tienda), LibertyFin activa los cobros en línea en tu cuenta cuando completas tus datos fiscales y tus documentos.</div></details>
          <details><summary>Olvidé mi contraseña, ¿qué hago?</summary>
            <div>Si eres cajero o de inventario, pídele a tu administrador que te la restablezca: lo hace en Ajustes, en un clic. Si eres el administrador, pídesela a soporte de LibertyFin. En los dos casos se genera una contraseña nueva que se entrega en persona o por teléfono, y la cambias al entrar.</div></details>
          <details><summary>¿Cuántas personas pueden usarlo?</summary>
            <div>Depende de tu plan: 1, 4, 6 o 10 usuarios. Los colaboradores que solo ganan comisión no cuentan, porque no necesitan entrar al sistema.</div></details>
        </div>
      </section>

      <!-- ═══ CIERRE ═══ -->
      <section class="tu-final">
        <h2>¿Listo para tu primera venta?</h2>
        <p>Lo que aprendiste aquí se fija haciéndolo. Ve a Caja y cobra algo de verdad.</p>
        <div class="tu-intro-acc">
          <?php if (Permisos::puede('cobrar')): ?><a class="btn btn-primary" href="/caja" data-parcial>Ir a Caja</a><?php endif; ?>
          <a class="btn btn-secondary" href="/guia">Ver la guía animada</a>
          <a class="btn btn-secondary" href="/ayuda" data-parcial>¿Dudas? Escríbenos</a>
        </div>
      </section>

    </div>
  </div>
</div>

<script>
/* El avance: qué capítulos marcó como vistos este navegador. Es una
   comodidad: si el navegador no deja guardar, todo funciona igual.
   Corre cada vez que se abre la sección (la navegación sin recarga
   vuelve a ejecutar los <script>), así que el clic se escucha una sola
   vez y lo demás se rehace con lo que hay en pantalla. */
(function () {
  var raiz = document.querySelector('.tu');
  if (!raiz) return;
  var CLAVE = 'lf-tutorial-visto';

  function leer() {
    try { var v = JSON.parse(localStorage.getItem(CLAVE) || '[]'); return Array.isArray(v) ? v : []; }
    catch (e) { return []; }
  }
  function guardar(v) { try { localStorage.setItem(CLAVE, JSON.stringify(v)); } catch (e) {} }

  function pintar() {
    var r = document.querySelector('.tu');
    if (!r) return;
    var caps = r.querySelectorAll('.tu-cap'), vistos = leer(), n = 0;
    caps.forEach(function (c) {
      var hecho = vistos.indexOf(c.id) !== -1;
      if (hecho) n++;
      c.classList.toggle('visto', hecho);
      var b = c.querySelector('[data-visto]');
      if (b) {
        b.setAttribute('aria-pressed', hecho ? 'true' : 'false');
        b.querySelector('span').textContent = hecho ? 'Visto' : 'Marcar como visto';
      }
      var a = r.querySelector('.tu-indice a[href="#' + c.id + '"]');
      if (a) a.classList.toggle('visto', hecho);
    });
    r.querySelectorAll('[data-avance-num]').forEach(function (e) { e.textContent = n; });
    r.querySelectorAll('[data-avance-barra]').forEach(function (e) {
      e.style.width = (caps.length ? n / caps.length * 100 : 0) + '%';
    });
  }

  if (!window.lfTutorialClic) {
    window.lfTutorialClic = true;
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest('.tu [data-visto]');
      if (!b) return;
      var id = b.closest('.tu-cap').id, v = leer(), i = v.indexOf(id);
      if (i === -1) v.push(id); else v.splice(i, 1);
      guardar(v);
      pintar();
    });
  }
  pintar();

  /* El índice marca el capítulo que se está leyendo. */
  if (window.lfTutorialObs) window.lfTutorialObs.disconnect();
  var enlaces = {};
  raiz.querySelectorAll('.tu-indice a').forEach(function (a) { enlaces[a.getAttribute('href').slice(1)] = a; });
  if ('IntersectionObserver' in window) {
    window.lfTutorialObs = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        Object.keys(enlaces).forEach(function (k) { enlaces[k].classList.toggle('actual', k === e.target.id); });
      });
    }, { rootMargin: '-25% 0px -65% 0px' });
    raiz.querySelectorAll('.tu-cap').forEach(function (c) { window.lfTutorialObs.observe(c); });
  }

  /* Angosto: el índice empieza cerrado y se cierra al elegir capítulo. */
  var caja = raiz.querySelector('.tu-indice-caja');
  var angosto = function () { return raiz.getBoundingClientRect().width <= 1000; };
  if (caja && angosto()) caja.open = false;
  raiz.querySelectorAll('.tu-indice a').forEach(function (a) {
    a.addEventListener('click', function () { if (caja && angosto()) caja.open = false; });
  });
})();
</script>
