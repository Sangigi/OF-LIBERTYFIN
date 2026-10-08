<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\TicketRepo as T;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$yo = (int)($_SESSION['usuario_id'] ?? 0);
$mio = (int)$t['asignado_a'] === $yo && $yo > 0;
$horas = T::PRIORIDADES[$t['prioridad']][1] ?? 24;
$min = $t['primera_respuesta_en']
     ? (strtotime($t['primera_respuesta_en']) - strtotime($t['creado_en'])) / 60
     : (time() - strtotime($t['creado_en'])) / 60;
$venc = $min > $horas * 60;
$ini = function ($n) { return mb_strtoupper(mb_substr(trim((string)$n), 0, 1) ?: '?'); };
?>

<div style="display:flex;gap:9px;margin-bottom:18px;flex-wrap:wrap">
  <a class="btn btn-secondary btn-sm" href="/tickets">Volver a la bandeja</a>
  <?php if ($t['empresa_id']): ?>
    <a class="btn btn-secondary btn-sm" href="/soporte/<?= (int)$t['empresa_id'] ?>">
      <?= W::icono('cliente','15px') ?>Ficha de <?= P::e($t['nombre_empresa']) ?></a>
  <?php endif; ?>
  <?php if ($t['email_admin']): ?>
    <a class="btn btn-secondary btn-sm" href="mailto:<?= P::e($t['email_admin']) ?>">Escribirle</a>
  <?php endif; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if (!$t['primera_respuesta_en'] && $venc): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <?= W::icono('reloj','18px') ?>
  <span><b>Sin responder y fuera de tiempo.</b> El compromiso para prioridad
    <?= P::e(T::PRIORIDADES[$t['prioridad']][0]) ?> son <?= $horas ?> horas, y van
    <?= round($min/60,1) ?>.</span>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function(ev){
  var b = ev.target.closest('.lf-plant');
  if (!b) return;
  ev.preventDefault();
  var c = document.getElementById('cuerpoResp');
  if (!c) return;
  // Se AGREGA, no se reemplaza: quien ya escribió media respuesta no
  // debería perderla por tocar una plantilla.
  c.value = (c.value.trim() ? c.value.trim() + '\n\n' : '') + b.dataset.txt;
  c.focus();
});
</script>

<div class="lf-split">
  <div>
    <?php /* Chat en vivo: lo que escriba el cliente aparece solo, y la
             respuesta se envía sin recargar (ver assets/js/lf-chat.js). */ ?>
    <section class="card" data-lf-chat="/tickets/<?= (int)$t['id'] ?>/mensajes" data-lf-lado="soporte"
             data-lf-escribe="/tickets/<?= (int)$t['id'] ?>/escribiendo"
             data-lf-form="#formResp"
             data-lf-ultimo="<?= $mensajes ? max(array_column($mensajes, 'id')) : 0 ?>">
      <header class="card-header">
        <div><span>Conversación</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            <?php $pub = 0; foreach ($mensajes as $x) if (empty($x['interno'])) $pub++; ?>
            <?= count($mensajes) ?> mensajes · <?= $pub ?> los ve el cliente · se actualiza sola</p></div>
      </header>
      <div class="card-body lf-chat-lista" data-lf-lista>
        <?php foreach ($mensajes as $m):
          $foto = $fotos[(int)$m['id']] ?? '';
          $deCliente = ($m['autor_tipo'] ?? '') === 'empresa'; ?>
          <div class="lf-msj<?= $m['interno'] ? ' interno' : '' ?>" data-id="<?= (int)$m['id'] ?>">
            <span class="lf-av <?= $m['interno'] ? 'gris' : '' ?><?= $foto ? ' con-foto' : '' ?>"
                  <?= $foto ? 'style="background-image:url(\'' . P::e($foto) . '\')"' : '' ?>><?= P::e($ini($m['autor_nombre'])) ?></span>
            <div class="cuerpo">
              <div class="cab">
                <b><?= P::e($m['autor_nombre'] ?: 'Sistema') ?></b>
                <?php if ($deCliente): ?><span class="badge bg-secondary">Cliente</span><?php endif; ?>
                <?php if ($m['interno']): ?>
                  <span class="badge bg-secondary">Nota interna</span>
                <?php endif; ?>
                <span class="fecha"><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></span>
              </div>
              <p><?= nl2br(P::e($m['cuerpo'])) ?></p>
              <?php if ($m['adjunto'] && preg_match('/\.(png|jpe?g|webp|gif)$/i', $m['adjunto'])): ?>
                <a href="<?= P::e($m['adjunto']) ?>" target="_blank" rel="noopener" class="adj-img">
                  <img src="<?= P::e($m['adjunto']) ?>" alt="Evidencia adjunta" loading="lazy"></a>
              <?php elseif ($m['adjunto']): ?>
                <a href="<?= P::e($m['adjunto']) ?>" target="_blank" rel="noopener" class="adj">
                  <?= W::icono('serv','14px') ?>Ver evidencia</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if (!in_array($t['estado'], ['cerrado'], true)): ?>
    <section class="card">
      <header class="card-header">Responder</header>
      <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$t['id'] ?>/responder" enctype="multipart/form-data"
              id="formResp" data-lf-enviar>
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <?php if ($plantillas): ?>
            <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:11px;align-items:center">
              <span style="font-size:11.5px;color:var(--lf-tinta-4)">Plantillas:</span>
              <?php foreach ($plantillas as $pl): ?>
                <button type="button" class="lf-pill lf-plant"
                        data-txt="<?= P::e($pl['cuerpo']) ?>"><?= P::e($pl['titulo']) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <textarea class="form-control lf-desc" name="cuerpo" id="cuerpoResp" rows="4" required
                    placeholder="Qué encontraste y qué tiene que hacer"></textarea>
          <div class="lf-resp-pie">
            <label class="lf-resp-interno">
              <input type="checkbox" name="interno" value="1"> Nota interna
            </label>
            <span class="lf-file">
              <input type="file" name="adjunto" id="adjResp"
                     accept="image/png,image/jpeg,image/webp,application/pdf">
              <label class="bt" for="adjResp">Adjuntar evidencia</label>
              <span class="n" data-vacio="Imagen o PDF, o pega una captura (Ctrl+V)">Imagen o PDF, o pega una captura (Ctrl+V)</span>
            </span>
            <button class="btn btn-primary" type="submit">Enviar</button>
          </div>
          <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:11px;line-height:1.5">
            Una <b>nota interna</b> no la ve el cliente y no cuenta como primera
            respuesta: para él nadie le ha contestado todavía.
          </p>
        </form>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($ayuda): ?>
    <section class="card">
      <header class="card-header">
        <div><span>Errores conocidos de esta categoría</span>
          <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
            Quizá ya sabemos qué es</p></div>
      </header>
      <div style="padding:0 10px 8px">
        <?php foreach ($ayuda as $a): ?>
          <a class="lf-row" href="/conocimiento?ver=<?= (int)$a['id'] ?>" target="_blank">
            <span style="flex:1;min-width:0">
              <b style="display:block;font-size:12.5px"><?= P::e($a['titulo']) ?></b>
              <?php if ($a['sintoma']): ?>
                <small style="color:var(--lf-tinta-4);font-size:11px">
                  <?= P::e(mb_substr($a['sintoma'], 0, 70)) ?></small>
              <?php endif; ?>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="card">
      <header class="card-header">Estado</header>
      <div class="card-body">
        <form method="post" action="/tickets/<?= (int)$t['id'] ?>/cambiar">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <label class="form-label">Estado</label>
          <select class="form-select" name="estado" onchange="this.form.submit()">
            <?php foreach (T::ESTADOS as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $t['estado']===$k?'selected':'' ?>><?= P::e($v) ?></option>
            <?php endforeach; ?>
          </select>

          <label class="form-label" style="margin-top:14px">Prioridad</label>
          <select class="form-select" name="prioridad" onchange="this.form.submit()">
            <?php foreach (T::PRIORIDADES as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $t['prioridad']===$k?'selected':'' ?>>
                <?= P::e($v[0]) ?> · responder en <?= $v[1] ?> h</option>
            <?php endforeach; ?>
          </select>

          <label class="form-label" style="margin-top:14px">Categoría</label>
          <select class="form-select" name="categoria" onchange="this.form.submit()">
            <?php foreach (T::CATEGORIAS as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $t['categoria']===$k?'selected':'' ?>><?= P::e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <form method="post" action="/tickets/<?= (int)$t['id'] ?>/cambiar" style="margin-top:16px">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <?php if ($mio): ?>
            <p style="font-size:12.5px;color:var(--lf-tinta-3);margin-bottom:9px">
              Lo tienes tú.</p>
            <button class="btn btn-secondary btn-sm" type="submit" name="soltar" value="1"
                    style="width:100%">Soltarlo</button>
          <?php else: ?>
            <p style="font-size:12.5px;color:var(--lf-tinta-3);margin-bottom:9px">
              <?= $t['asignado_nombre']
                  ? 'Lo lleva ' . P::e($t['asignado_nombre']) . '.'
                  : 'Sin asignar.' ?></p>
            <button class="btn btn-primary btn-sm" type="submit" name="asignarme" value="1"
                    style="width:100%">Asignármelo</button>
          <?php endif; ?>
        </form>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Datos</header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ([
          'Folio'     => $t['folio'],
          'Empresa'   => $t['nombre_empresa'] ?: '—',
          'Lo abrió'  => $t['creado_nombre'] ?: '—',
          'Abierto'   => date('d/m/Y H:i', strtotime($t['creado_en'])),
          'Respuesta' => $t['primera_respuesta_en']
                         ? date('d/m/Y H:i', strtotime($t['primera_respuesta_en'])) : 'sin responder',
          'Resuelto'  => $t['resuelto_en'] ? date('d/m/Y H:i', strtotime($t['resuelto_en'])) : '—',
        ] as $k => $v): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b style="font-weight:600;text-align:right;overflow-wrap:anywhere"><?= P::e($v) ?></b>
          </div>
        <?php endforeach; ?>
        <div style="margin-top:10px;padding:10px 12px;border-radius:var(--lf-r);font-size:12px;
             background:<?= $venc?'var(--lf-amb-soft)':'var(--lf-brand-soft)' ?>;
             color:<?= $venc?'var(--lf-amb)':'var(--lf-brand-2)' ?>">
          <?php if ($t['primera_respuesta_en']): ?>
            Se respondió en <?= $min < 60 ? round($min) . ' min' : round($min/60,1) . ' h' ?>
            (comprometido: <?= $horas ?> h)
          <?php else: ?>
            Llevan <?= $min < 60 ? round($min) . ' min' : round($min/60,1) . ' h' ?> esperando
            respuesta de <?= $horas ?> h
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($eventos): ?>
    <section class="card">
      <header class="card-header">Historial</header>
      <div class="card-body">
        <?php foreach (array_slice($eventos, 0, 12) as $ev): ?>
          <div style="display:flex;gap:10px;padding:6px 0;font-size:12px">
            <span style="color:var(--lf-tinta-4);font-family:var(--lf-mono);flex-shrink:0;font-size:11px">
              <?= date('d/m H:i', strtotime($ev['creado_en'])) ?></span>
            <span style="flex:1;min-width:0;color:var(--lf-tinta-2)">
              <b style="font-weight:600"><?= P::e($ev['quien_nombre'] ?: 'Sistema') ?></b>
              <?php if ($ev['que'] === 'creado'): ?> abrió el ticket
              <?php elseif ($ev['que'] === 'asignado'): ?>
                <?= $ev['despues'] ? 'asignó a ' . P::e($ev['despues']) : 'lo dejó sin asignar' ?>
              <?php else: ?>
                cambió <?= P::e($ev['que']) ?>
                <?php if ($ev['antes']): ?>de <?= P::e($ev['antes']) ?><?php endif; ?>
                a <?= P::e($ev['despues']) ?>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<script>window.LFChat && LFChat.enlazar(document);</script>
