<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;
use LibertyFin\Dominio\Permisos as Perm;
use LibertyFin\Datos\UsuarioRepo as U;
use LibertyFin\Datos\CuentaRepo as C;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$ed = $estadoDocs;
$colorDoc = ['sin_enviar'=>'bg-secondary','en_revision'=>'bg-warning',
             'rechazada'=>'bg-danger','aprobada'=>'bg-success'][$ed['estado']] ?? 'bg-secondary';
$textoDoc = ['sin_enviar'=>'Faltan documentos','en_revision'=>'En revisión',
             'rechazada'=>'Hay documentos rechazados','aprobada'=>'Documentación aprobada'][$ed['estado']] ?? '';
?>

<div class="lf-pills" style="margin-bottom:18px">
  <?php foreach ($pestanas as $k => $v): ?>
    <a class="lf-pill <?= $pestana===$k?'active':'' ?>" href="/cuenta?t=<?= $k ?>"><?= P::e($v) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<?php if ($ed['estado'] !== 'aprobada' && $pestana !== 'documentos'): ?>
<a class="alert alert-warning" href="/cuenta?t=documentos" style="margin-bottom:18px;text-decoration:none">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= P::e($textoDoc) ?>.</b>
    Hasta que la documentación esté aprobada puedes registrar ventas, pero no
    cobrar con tarjeta ni facturar.
    <?= (int)$ed['aprobados'] ?> de <?= (int)$ed['total'] ?> listos.</span>
</a>
<?php endif; ?>


<?php /* ═══════ MI PERFIL ═══════ */ if ($pestana === 'perfil'): ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Cambiar mi contraseña</header>
    <div class="card-body" style="max-width:400px">
      <form method="post" action="/cuenta/clave">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <label class="form-label" for="a">Contraseña actual</label>
        <input class="form-control" type="password" id="a" name="actual"
               autocomplete="current-password" required>
        <label class="form-label" for="n" style="margin-top:14px">Contraseña nueva</label>
        <input class="form-control" type="password" id="n" name="nueva"
               minlength="<?= U::CLAVE_MINIMA ?>" autocomplete="new-password" required>
        <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:8px;line-height:1.5">
          Mínimo <?= U::CLAVE_MINIMA ?> caracteres. Una frase que recuerdes es mejor
          que ocho caracteres raros que acabes apuntando en un papel.
        </p>
        <button class="btn btn-primary" type="submit" style="width:100%;margin-top:16px;padding:12px">
          Cambiar</button>
      </form>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:14px;padding-top:14px;
                border-top:1px solid var(--lf-linea);line-height:1.5">
        Al cambiarla se cierra tu sesión. Si la cambias porque crees que alguien la
        sabía, dejarla abierta no serviría de nada.
      </p>
    </div>
  </section>

  <div>
    <section class="card">
      <header class="card-header">Mi foto</header>
      <div class="card-body">
        <form method="post" action="/cuenta/foto" enctype="multipart/form-data">
          <input type="hidden" name="token" value="<?= P::e($token) ?>">
          <div class="lf-foto">
            <span class="prev" id="prevFoto"
                  style="<?= $foto ? "background-image:url('".P::e($foto)."')" : '' ?>">
              <?= $foto ? '' : P::e(mb_strtoupper(mb_substr($_SESSION['usuario_nombre'] ?? 'U',0,1))) ?></span>
            <div style="flex:1;min-width:0">
              <span class="lf-file">
                <input type="file" name="foto" id="inpFoto"
                       accept="image/png,image/jpeg,image/webp">
                <label class="bt" for="inpFoto">Elegir foto</label>
                <span class="n" data-vacio="Ninguna foto elegida">Ninguna foto elegida</span>
              </span>
              <p style="font-size:11px;color:var(--lf-tinta-4);margin-top:6px">
                Cuadrada. Se recorta en círculo y aparece en el menú.</p>
            </div>
          </div>
          <div style="display:flex;gap:9px;margin-top:16px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit">Guardar foto</button>
            <?php if ($foto): ?>
              <button class="btn btn-secondary" type="submit" name="quitar" value="1">Quitar</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="card">
      <header class="card-header">Mis datos</header>
      <div class="card-body" style="font-size:13px">
        <?php foreach ([
          'Nombre'   => $_SESSION['usuario_nombre'] ?? '',
          'Rol'      => Perm::rotulo($_SESSION['usuario_rol'] ?? ''),
          'Empresa'  => $_SESSION['empresa_nombre'] ?? '',
          'Sucursal' => $_SESSION['sucursal_nombre'] ?? '',
        ] as $k => $v): if ($v === '') continue; ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
            <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
            <b style="font-weight:600;text-align:right"><?= P::e($v) ?></b>
          </div>
        <?php endforeach; ?>
        <p style="margin-top:14px;padding-top:14px;border-top:1px solid var(--lf-linea);
                  font-size:11.5px;color:var(--lf-tinta-4)">
          Para cambiar tu nombre, rol o sucursal, pídeselo a un administrador.
        </p>
        <a class="btn btn-secondary btn-sm" href="/guia" style="width:100%;margin-top:10px">
          <?= W::icono('panel','15px') ?>Volver a ver la guía</a>
      </div>
    </section>
  </div>
</div>
<script>
(function(){
  var i = document.getElementById('inpFoto'), p = document.getElementById('prevFoto');
  if (!i || !p) return;
  i.addEventListener('change', function(){
    var f = i.files && i.files[0];
    if (!f) return;
    p.style.backgroundImage = "url('" + URL.createObjectURL(f) + "')";
    p.textContent = '';
  });
})();
</script>


<?php /* ═══════ PLAN ═══════ */ elseif ($pestana === 'plan'):
$em = $empresa;
$venc = ($em && !empty($em['fecha_vencimiento'])) ? strtotime($em['fecha_vencimiento']) : null;
$dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
?>
<?php if (!$em): ?>
  <div class="alert alert-warning"><?= W::icono('alerta','18px') ?>
    <span>No se pudieron leer los datos del plan. Revisa <code>bd.principal</code>
      en <code>config/config.php</code>.</span></div>
<?php else: ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Tu plan</header>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
        <span class="lf-tile" style="width:52px;height:52px;border-radius:17px;margin:0">
          <?= W::icono('serv','24px') ?></span>
        <div>
          <b style="font-size:21px;font-weight:700;letter-spacing:-.4px;display:block">
            <?= P::e(ucfirst($em['plan'] ?? 'Prueba')) ?></b>
          <small style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($em['nombre_empresa']) ?></small>
        </div>
      </div>

      <?php if ($venc): ?>
        <div style="padding:14px 16px;border-radius:var(--lf-r);margin-bottom:6px;
             background:<?= $dias<0?'var(--lf-rojo-soft)':($dias<15?'var(--lf-amb-soft)':'var(--lf-brand-soft)') ?>;
             color:<?= $dias<0?'var(--lf-rojo)':($dias<15?'var(--lf-amb)':'var(--lf-brand-2)') ?>">
          <b style="display:block;font-size:15px;margin-bottom:3px">
            <?= $dias < 0 ? 'Venció hace ' . abs($dias) . ' días'
                          : 'Quedan ' . $dias . ' día' . ($dias==1?'':'s') ?></b>
          <span style="font-size:12.5px">Vence el <?= date('d/m/Y', $venc) ?></span>
        </div>
      <?php endif; ?>

      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:16px;line-height:1.55">
        Para cambiar de plan o renovar, escríbele a LibertyFin. El plan, la fecha de
        vencimiento y los límites los administra la plataforma, no la empresa.
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Para empezar a cobrar de verdad</header>
    <div class="card-body">
      <?php
      $pasos = [
        ['Datos fiscales',   'fiscales',
         !empty($fiscales['rfc_fiscal'] ?? '') , 'Para timbrar facturas'],
        ['Alta de comercio', 'comercio',
         false, 'Para procesar tarjeta y SPEI'],
        ['Documentos',       'documentos',
         $ed['estado'] === 'aprobada', 'Los revisa LibertyFin en 24 a 72 horas'],
      ];
      foreach ($pasos as $i => $ps): list($n, $t, $listo, $porque) = $ps; ?>
        <a class="lf-row" href="/cuenta?t=<?= $t ?>">
          <span class="lf-av <?= $listo ? '' : 'gris' ?>" style="flex-shrink:0">
            <?= $listo ? '✓' : ($i+1) ?></span>
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($n) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px"><?= P::e($porque) ?></small>
          </span>
          <?= W::icono('venta','15px') ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php endif; ?>


<?php /* ═══════ DATOS FISCALES ═══════ */ elseif ($pestana === 'fiscales'): ?>
<section class="card" style="max-width:720px">
  <header class="card-header">
    <div><span>Datos fiscales</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Los que el SAT necesita para timbrar tus facturas</p></div>
  </header>
  <div class="card-body">
    <form method="post" action="/cuenta/fiscales" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">

      <div style="width:100%">
        <label class="form-label">Tipo de persona</label>
        <div style="display:flex;gap:9px;flex-wrap:wrap">
          <?php foreach (['fisica'=>'Persona física','moral'=>'Persona moral'] as $k=>$v): ?>
            <label class="lf-pill <?= ($fiscales['tipo_persona'] ?? '')===$k ? 'active':'' ?>"
                   style="cursor:pointer">
              <input type="radio" name="tipo_persona" value="<?= $k ?>" hidden
                     <?= ($fiscales['tipo_persona'] ?? '')===$k ? 'checked':'' ?>><?= $v ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="flex:1;min-width:190px"><label class="form-label">RFC</label>
        <input class="form-control lf-mono" name="rfc_fiscal" style="text-transform:uppercase"
               value="<?= P::e($fiscales['rfc_fiscal'] ?? '') ?>" maxlength="13"></div>
      <div style="width:150px"><label class="form-label">CP fiscal</label>
        <input class="form-control lf-mono" name="cp_fiscal" inputmode="numeric" maxlength="5"
               value="<?= P::e($fiscales['cp_fiscal'] ?? '') ?>"></div>
      <div style="flex:2;min-width:250px"><label class="form-label">Razón social</label>
        <input class="form-control" name="razon_social"
               value="<?= P::e($fiscales['razon_social'] ?? '') ?>"></div>
      <div style="width:100%"><label class="form-label">Régimen fiscal</label>
        <select class="form-select" name="regimen_sat">
          <option value="">Sin definir</option>
          <?php foreach (C::REGIMENES as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($fiscales['regimen_sat'] ?? '')===$k?'selected':'' ?>>
              <?= P::e($v) ?></option>
          <?php endforeach; ?>
        </select></div>

      <button class="btn btn-primary" type="submit">Guardar</button>
      <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0;line-height:1.5">
        Tienen que coincidir <b>exactamente</b> con tu constancia de situación fiscal.
        Un solo carácter distinto y el SAT rechaza el timbrado.
      </p>
    </form>
  </div>
</section>


<?php /* ═══════ COMERCIO ═══════ */ elseif ($pestana === 'comercio'):
$c = $comercio;
$v = function ($k) use ($c) { return P::e($c[$k] ?? ''); };
$grupos = [
  ['Datos generales del titular', [
    ['titular_nombre','Nombre del titular','Como aparece en el estado de cuenta',2],
    ['nombre_comercio','Nombre del comercio','',1],
    ['titular_correo','Correo','',1,'email'],
    ['giro','Actividad o giro','',1],
    ['telefono_celular','Celular','',0.7],
    ['telefono_oficina','Oficina','',0.7],
    ['calle_numero','Calle y número exterior','',2],
    ['numero_interior','Interior','',0.6],
    ['colonia','Colonia','',1],
    ['delegacion_municipio','Delegación o municipio','',1],
    ['ciudad','Ciudad','',1],
    ['estado_direccion','Estado','',1],
    ['pais','País','',1],
    ['nombre_vendedor','Nombre del vendedor','',1],
  ]],
  ['Representante legal', [
    ['rep_legal_nombre','Nombre completo','',2],
    ['rep_legal_escritura','Número y fecha de escritura','',1],
    ['rep_legal_notaria_numero','Notaría número','',0.7],
    ['rep_legal_notario_nombre','Nombre del notario','',1],
    ['rep_legal_ciudad','Ciudad','',1],
  ]],
  ['Identificación del titular', [
    ['id_tipo','Tipo de identificación','',1],
    ['id_numero','Número','',1],
    ['id_fecha_expedicion','Fecha de expedición','',1,'date'],
    ['id_vigencia','Vigencia','',1,'date'],
  ]],
  ['Datos bancarios', [
    ['banco','Banco','',1],
    ['plaza','Plaza','',1],
    ['sucursal_bancaria','Sucursal','',1],
    ['cuenta_cheques','Cuenta de cheques','',1],
    ['cuenta_clabe','CLABE','18 dígitos',1.4],
  ]],
];
?>
<form method="post" action="/cuenta/comercio">
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <?php foreach ($grupos as $g): ?>
    <section class="card">
      <header class="card-header"><?= P::e($g[0]) ?></header>
      <div class="card-body">
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <?php foreach ($g[1] as $campo):
            $k = $campo[0]; $tipo = $campo[4] ?? 'text';
            $ancho = max(150, (int)($campo[3] * 170)); ?>
            <div style="flex:<?= $campo[3] ?>;min-width:<?= $ancho ?>px">
              <label class="form-label"><?= P::e($campo[1]) ?></label>
              <input class="form-control<?= in_array($k,['cuenta_clabe','cuenta_cheques'],true)?' lf-mono':'' ?>"
                     type="<?= $tipo ?>" name="<?= $k ?>" value="<?= $v($k) ?>"
                     <?= $k==='cuenta_clabe' ? 'inputmode="numeric" maxlength="18"' : '' ?>
                     <?= $campo[2] ? 'placeholder="'.P::e($campo[2]).'"' : '' ?>>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>

  <section class="card">
    <div class="card-body">
      <label style="display:flex;gap:11px;align-items:flex-start;cursor:pointer;font-size:13px;
             line-height:1.55;color:var(--lf-tinta-2)">
        <input type="checkbox" name="clausulado" value="1" style="margin-top:3px;flex-shrink:0"
               <?= !empty($c['clausulado_aceptado_en']) ? 'checked disabled' : 'required' ?>>
        <span>He leído y acepto el clausulado del contrato de procesamiento de
          transacciones.
          <?php if (!empty($c['clausulado_aceptado_en'])): ?>
            <b style="display:block;color:var(--lf-brand-2);font-size:12px;margin-top:4px">
              Aceptado el <?= date('d/m/Y', strtotime($c['clausulado_aceptado_en'])) ?></b>
          <?php else: ?>
            <b style="display:block;font-size:12px;margin-top:4px">Sin esto no se puede enviar.</b>
          <?php endif; ?>
        </span>
      </label>
      <button class="btn btn-primary" type="submit" style="margin-top:18px">Guardar datos</button>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:12px;line-height:1.55">
        Se puede guardar incompleto y seguir después: no se envía a ningún lado hasta
        que la documentación esté aprobada.
        <?php if (!empty($c['actualizado_en'])): ?>
          Última actualización: <?= date('d/m/Y H:i', strtotime($c['actualizado_en'])) ?>.
        <?php endif; ?>
      </p>
    </div>
  </section>
</form>


<?php /* ═══════ DOCUMENTOS ═══════ */ else: ?>
<div class="alert alert-<?= $ed['estado']==='aprobada'?'success':($ed['estado']==='rechazada'?'danger':'info') ?>"
     style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span><b><?= P::e($textoDoc) ?>.</b>
    <?= (int)$ed['aprobados'] ?> de <?= (int)$ed['total'] ?> obligatorios aprobados.
    JPG, PNG o PDF, máximo 10 MB. Un administrador de LibertyFin los revisa en 24 a 72 horas.</span>
</div>

<div class="lf-docs">
  <?php foreach (C::DOCUMENTOS as $k => $d):
    $doc = $documentos[$k] ?? null;
    $est = $doc['estado'] ?? null; ?>
    <section class="card lf-doc">
      <div class="card-body">
        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:14px">
          <span class="lf-tile <?= $est==='aprobado'?'':($est==='rechazado'?'r':'g') ?>"
                style="width:38px;height:38px;border-radius:12px;margin:0;flex-shrink:0">
            <?= W::icono($est==='aprobado'?'cobro':($est==='rechazado'?'alerta':'serv'),'17px') ?></span>
          <div style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px;font-weight:600;line-height:1.35">
              <?= P::e($d[0]) ?></b>
            <small style="font-size:11px;color:var(--lf-tinta-4)">
              <?= $d[1] ? 'Obligatorio' : 'Opcional' ?></small>
          </div>
          <?php if ($est === 'aprobado'): ?><span class="badge bg-success">Aprobado</span>
          <?php elseif ($est === 'rechazado'): ?><span class="badge bg-danger">Rechazado</span>
          <?php elseif ($est): ?><span class="badge bg-warning">En revisión</span>
          <?php endif; ?>
        </div>

        <?php if ($est === 'rechazado' && !empty($doc['motivo_rechazo'])): ?>
          <p style="font-size:12px;color:var(--lf-rojo);background:var(--lf-rojo-soft);
                    padding:10px 12px;border-radius:var(--lf-r);margin-bottom:12px;line-height:1.5">
            <b>Por qué se rechazó:</b> <?= P::e($doc['motivo_rechazo']) ?></p>
        <?php endif; ?>

        <?php if ($doc): ?>
          <div style="display:flex;align-items:center;gap:9px;font-size:11.5px;
               color:var(--lf-tinta-4);margin-bottom:12px">
            <a href="<?= P::e($doc['ruta_archivo']) ?>" target="_blank" rel="noopener"
               style="font-weight:600">Ver archivo</a>
            <span>·</span>
            <span><?= date('d/m/Y', strtotime($doc['subido_en'])) ?></span>
            <?php if ($doc['tamano_bytes']): ?>
              <span>·</span><span><?= round($doc['tamano_bytes']/1024) ?> KB</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($est !== 'aprobado'): ?>
          <form method="post" action="/cuenta/documento" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= P::e($token) ?>">
            <input type="hidden" name="tipo" value="<?= P::e($k) ?>">
            <input type="file" name="archivo" accept="image/png,image/jpeg,image/webp,application/pdf" required
                   style="font-size:11.5px;width:100%;margin-bottom:10px">
            <button class="btn btn-secondary btn-sm" type="submit" style="width:100%">
              <?= $doc ? 'Reemplazar' : 'Subir' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
