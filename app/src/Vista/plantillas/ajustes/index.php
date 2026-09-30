<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Dinero as D;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
// El renglón que se está editando, si lo hay, de la lista que toque.
$buscar_ed = function ($lista) use ($editar) {
    foreach ($lista as $x) if ((int)$x['id'] === $editar) return $x;
    return null;
};
?>

<div class="lf-pills" style="margin-bottom:18px">
  <?php foreach ($pestanas as $k => $v): ?>
    <a class="lf-pill <?= $pestana===$k?'active':'' ?>" href="/ajustes?t=<?= $k ?>"><?= P::e($v) ?></a>
  <?php endforeach; ?>
  <a class="lf-pill" href="/usuarios">Usuarios</a>
</div>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>



<?php /* ═══════════════ EMPRESA ═══════════════ */ if ($pestana === 'empresa'):
$em = $empresa; ?>
<?php if (!$em): ?>
  <div class="alert alert-warning"><?= W::icono('alerta','18px') ?>
    <span>No se pudieron leer los datos de la empresa.</span></div>
<?php else: ?>
<div class="lf-split">
  <section class="card">
    <header class="card-header">Datos de la empresa</header>
    <div class="card-body">
      <form method="post" action="/ajustes/guardar" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="token" value="<?= P::e($token) ?>">
        <input type="hidden" name="que" value="empresa">
        <div style="flex:2;min-width:220px"><label class="form-label">Razón social</label>
          <input class="form-control" name="nombre_empresa" value="<?= P::e($em['nombre_empresa']) ?>" required></div>
        <div style="width:170px"><label class="form-label">RFC</label>
          <input class="form-control" name="rfc" value="<?= P::e($em['rfc'] ?? '') ?>"
                 style="text-transform:uppercase" placeholder="Opcional"></div>
        <div style="flex:1;min-width:170px"><label class="form-label">Giro</label>
          <input class="form-control" name="giro_comercial" value="<?= P::e($em['giro_comercial'] ?? '') ?>" placeholder="Opcional"></div>
        <div style="width:170px"><label class="form-label">Teléfono</label>
          <input class="form-control" name="telefono" value="<?= P::e($em['telefono'] ?? '') ?>" placeholder="Opcional"></div>
        <div style="flex:2;min-width:260px"><label class="form-label">Dirección</label>
          <input class="form-control" name="direccion" value="<?= P::e($em['direccion'] ?? '') ?>" placeholder="Opcional"></div>
        <div style="flex:1;min-width:180px"><label class="form-label">Contacto</label>
          <input class="form-control" name="nombre_contacto" value="<?= P::e($em['nombre_contacto'] ?? '') ?>" placeholder="Opcional"></div>
        <div style="flex:1;min-width:190px"><label class="form-label">Correo</label>
          <input class="form-control" type="email" name="email_admin" value="<?= P::e($em['email_admin'] ?? '') ?>" placeholder="Opcional"></div>
        <button class="btn btn-primary" type="submit">Guardar</button>
        <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
          Estos datos salen en los tickets y en las facturas.
        </p>
      </form>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Cuenta</header>
    <div class="card-body" style="font-size:13px">
      <?php
      $venc = !empty($em['fecha_vencimiento']) ? strtotime($em['fecha_vencimiento']) : null;
      $dias = $venc ? floor(($venc - strtotime('today')) / 86400) : null;
      foreach ([
        'Plan'        => ucfirst($em['plan'] ?? 'prueba'),
        'Base'        => $em['nombre_base_datos'],
        'Distribuidor'=> $em['no_distribuidor'] ?: null,
      ] as $k => $v): if ($v === null) continue; ?>
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
          <span style="color:var(--lf-tinta-3)"><?= P::e($k) ?></span>
          <b style="font-weight:600;text-align:right;font-family:var(--lf-mono);font-size:12.5px">
            <?= P::e($v) ?></b>
        </div>
      <?php endforeach; ?>
      <?php if ($venc): ?>
        <div style="display:flex;justify-content:space-between;gap:12px;padding:7px 0">
          <span style="color:var(--lf-tinta-3)">Vence</span>
          <b style="font-weight:600;text-align:right"><?= date('d/m/Y', $venc) ?></b>
        </div>
        <div style="margin-top:12px;padding:11px 13px;border-radius:var(--lf-r);font-size:12.5px;
             background:<?= $dias<0?'var(--lf-rojo-soft)':($dias<15?'var(--lf-amb-soft)':'var(--lf-brand-soft)') ?>;
             color:<?= $dias<0?'var(--lf-rojo)':($dias<15?'var(--lf-amb)':'var(--lf-brand-2)') ?>">
          <?php if ($dias < 0): ?>La suscripción venció hace <?= abs($dias) ?> días.
          <?php elseif ($dias < 15): ?>Quedan <?= $dias ?> días de suscripción.
          <?php else: ?>Quedan <?= $dias ?> días de suscripción.<?php endif; ?>
        </div>
      <?php endif; ?>
      <p style="margin-top:14px;padding-top:14px;border-top:1px solid var(--lf-linea);
                font-size:11.5px;color:var(--lf-tinta-4);line-height:1.5">
        El plan, la base de datos y la fecha de vencimiento no se editan aquí:
        cambiarlos dejaría a la empresa sin poder entrar. Eso lo maneja quien
        administra la plataforma.
      </p>
    </div>
  </section>
</div>
<?php endif; ?>


<?php /* ═══════════════ SUCURSALES ═══════════════ */ elseif ($pestana === 'sucursales'):
$e = $buscar_ed($sucursales); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e?'serv':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Agregar sucursal' ?></summary>
  <form method="post" action="/ajustes/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="que" value="sucursal">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:1;min-width:180px"><label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="flex:2;min-width:220px"><label class="form-label">Dirección</label>
      <input class="form-control" name="direccion" value="<?= P::e($e['direccion'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="width:150px"><label class="form-label">Teléfono</label>
      <input class="form-control" name="telefono" value="<?= P::e($e['telefono'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="flex:1;min-width:170px"><label class="form-label">Responsable</label>
      <input class="form-control" name="responsable" value="<?= P::e($e['responsable'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Agregar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/ajustes?t=sucursales">Cancelar</a><?php endif; ?>
    </div>
  </form>
</details>

<section class="card">
  <header class="card-header">Sucursales</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Sucursal</th><th>Responsable</th><th class="text-end">Usuarios</th>
        <th class="text-end">Ventas</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sucursales as $s): $a = (int)$s['activo']===1; ?>
        <tr style="<?= $a?'':'opacity:.55' ?>">
          <td data-label="Sucursal"><b style="font-weight:600"><?= P::e($s['nombre']) ?></b>
            <?php if ($s['direccion']): ?><span style="display:block;color:var(--lf-tinta-4);font-size:11.5px">
              <?= P::e($s['direccion']) ?></span><?php endif; ?></td>
          <td data-label="Responsable" style="font-size:12.5px"><?= P::e($s['responsable'] ?: '—') ?></td>
          <td data-label="Usuarios" class="text-end lf-mono"><?= (int)$s['usuarios'] ?></td>
          <td data-label="Ventas" class="text-end lf-mono"><?= (int)$s['ventas'] ?></td>
          <td data-label="Estado"><?= $a?'<span class="badge bg-success">Activa</span>'
                                     :'<span class="badge bg-secondary">Inactiva</span>' ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/ajustes?t=sucursales&editar=<?= (int)$s['id'] ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-alt" data-que="sucursal"
                    data-id="<?= (int)$s['id'] ?>" data-nombre="<?= P::e($s['nombre']) ?>"
                    data-activo="<?= $a?1:0 ?>"><?= $a?'&times;':'✓' ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>


<?php /* ═══════════════ ÁREAS Y COLABORADORES ═══════════════ */ elseif ($pestana === 'comisiones'):
$ea = $buscar_ed($areas); $ec = $buscar_ed($colaboradores); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono('mas','16px') ?>
    <?= $ec ? 'Editar a ' . P::e($ec['nombre']) : ($ea ? 'Editar el área ' . P::e($ea['nombre']) : 'Agregar colaborador o área') ?></summary>
  <div style="padding:18px 20px;display:flex;gap:26px;flex-wrap:wrap">
    <form method="post" action="/ajustes/guardar" style="flex:2;min-width:300px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="colaborador">
      <?php if ($ec): ?><input type="hidden" name="id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
      <div style="flex:2;min-width:180px"><label class="form-label">Colaborador</label>
        <input class="form-control" name="nombre" value="<?= P::e($ec['nombre'] ?? '') ?>" required></div>
      <div style="flex:1;min-width:150px"><label class="form-label">Área</label>
        <select class="form-select" name="area_id" required>
          <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['id'] ?>"
              <?= (isset($ec['area_id']) && (int)$ec['area_id']===(int)$a['id'])?'selected':'' ?>>
              <?= P::e($a['nombre']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn btn-primary" type="submit"><?= $ec?'Guardar':'Agregar' ?></button>
    </form>

    <form method="post" action="/ajustes/guardar" style="flex:1;min-width:230px;display:flex;gap:10px;align-items:flex-end;
          border-left:1px solid var(--lf-linea);padding-left:24px">
      <input type="hidden" name="token" value="<?= P::e($token) ?>">
      <input type="hidden" name="que" value="area">
      <?php if ($ea): ?><input type="hidden" name="id" value="<?= (int)$ea['id'] ?>"><?php endif; ?>
      <div style="flex:1"><label class="form-label">Área nueva</label>
        <input class="form-control" name="nombre" value="<?= P::e($ea['nombre'] ?? '') ?>"
               placeholder="Ej. Legal" required></div>
      <button class="btn btn-secondary" type="submit"><?= $ea?'Guardar':'Crear' ?></button>
    </form>
  </div>
</details>

<div class="lf-split">
  <section class="card">
    <header class="card-header">Colaboradores</header>
    <div class="table-responsive lf-cards" style="padding:0 12px 6px">
      <table class="table table-hover">
        <thead><tr><th>Nombre</th><th>Área</th><th class="text-end">% usual</th>
          <th class="text-end">Devengado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($colaboradores as $c): $a = (int)$c['activo']===1;
              $sis = $c['nombre'] === 'POR ASIGNAR'; ?>
          <tr style="<?= $a?'':'opacity:.55' ?>">
            <td data-label="Nombre"><b style="font-weight:600"><?= P::e($c['nombre']) ?></b>
              <?php if ($sis): ?><span style="display:block;color:var(--lf-tinta-4);font-size:11px">
                del sistema · marca comisiones sin dueño</span><?php endif; ?></td>
            <td data-label="Área"><span class="badge bg-secondary"><?= P::e($c['area']) ?></span></td>
            <td data-label="% usual" class="text-end lf-mono">
              <?= $c['pct_usual'] !== null ? number_format($c['pct_usual'],2).'%' : '—' ?></td>
            <td data-label="Devengado" class="text-end lf-mono" style="font-weight:700">
              <?= D::pesos($c['devengado']) ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if (!$sis): ?>
                <a class="lf-btn-ghost" href="/ajustes?t=comisiones&editar=<?= (int)$c['id'] ?>"
                   title="Editar"><?= W::icono('cliente','15px') ?></a>
                <button type="button" class="lf-btn-ghost lf-alt" data-que="colaborador"
                        data-id="<?= (int)$c['id'] ?>" data-nombre="<?= P::e($c['nombre']) ?>"
                        data-activo="<?= $a?1:0 ?>"><?= $a?'&times;':'✓' ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      El "% usual" sale de su historial, no de una regla fija. Es el que el
      sistema sugiere al asignar una comisión nueva.
    </div>
  </section>

  <section class="card">
    <header class="card-header">Áreas</header>
    <div style="padding:0 10px 8px">
      <?php foreach ($areas as $a): ?>
        <div class="lf-row">
          <span style="flex:1;min-width:0">
            <b style="display:block;font-size:13.5px"><?= P::e($a['nombre']) ?></b>
            <small style="color:var(--lf-tinta-4);font-size:11.5px">
              <?= (int)$a['colaboradores'] ?> colaborador<?= $a['colaboradores']==1?'':'es' ?></small>
          </span>
          <b class="lf-mono" style="font-size:13px"><?= D::corto($a['asignado']) ?></b>
          <a class="lf-btn-ghost" href="/ajustes?t=comisiones&editar=<?= (int)$a['id'] ?>"
             title="Renombrar"><?= W::icono('serv','15px') ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>


<?php /* ═══════════════ INTEGRACIONES ═══════════════ */ elseif ($pestana === 'integraciones'):
$listas = 0; foreach ($integraciones as $i) if ($i['activa']) $listas++;
?>
<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Las credenciales se escriben en <code>config/integraciones.php</code>, fuera de
    la carpeta pública y fuera del repositorio. <b>Nunca se muestran aquí</b>: esta
    pantalla solo dice si están puestas.</span>
</div>

<div class="lf-stats">
  <div class="stat-card lf-hero">
    <div class="lf-tile"><?= W::icono('serv','19px') ?></div>
    <div class="stat-value"><?= $listas ?></div>
    <div class="stat-label">Integraciones activas</div>
    <div class="stat-meta">de <?= count($integraciones) ?> disponibles</div>
  </div>
  <div class="stat-card">
    <div class="lf-tile a"><?= W::icono('reloj','19px') ?></div>
    <div class="stat-value"><?= count($integraciones) - $listas ?></div>
    <div class="stat-label">Pendientes</div>
    <div class="stat-meta">sin credenciales</div>
  </div>
</div>

<section class="card">
  <header class="card-header">Servicios externos</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Servicio</th><th>Para qué</th><th>Estado</th><th>Falta</th></tr></thead>
      <tbody>
      <?php foreach ($integraciones as $k => $i): ?>
        <tr style="<?= $i['activa'] ? '' : 'opacity:.7' ?>">
          <td data-label="Servicio">
            <b style="font-weight:600"><?= P::e($i['nombre']) ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;
                  font-family:var(--lf-mono)"><?= P::e($k) ?></span>
          </td>
          <td data-label="Para qué" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($i['para']) ?></td>
          <td data-label="Estado">
            <?php if ($i['activa'] && $i['sandbox']): ?>
              <span class="badge bg-warning">Pruebas</span>
            <?php elseif ($i['activa']): ?>
              <span class="badge bg-success">Activa</span>
            <?php elseif ($i['lista']): ?>
              <span class="badge bg-secondary">Configurada, apagada</span>
            <?php else: ?>
              <span class="badge bg-secondary">Pendiente</span>
            <?php endif; ?>
          </td>
          <td data-label="Falta" style="font-size:12px;color:var(--lf-tinta-4)">
            <?php if ($i['faltan']): ?>
              <span class="lf-mono"><?= P::e(implode(', ', $i['faltan'])) ?></span>
            <?php elseif (!$i['activa']): ?>
              poner <span class="lf-mono">'activo' =&gt; true</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    Una integración sin credenciales queda apagada sola: su sección no aparece en
    el menú y sus rutas responden 404. No hay que desactivar nada a mano.
  </div>
</section>

<section class="card">
  <header class="card-header">Cómo se configuran</header>
  <div class="card-body" style="font-size:13px;line-height:1.7;color:var(--lf-tinta-2)">
    <p style="margin-bottom:12px">
      1. Copia <code>config/integraciones.php.ejemplo</code> como
      <code>config/integraciones.php</code>.
    </p>
    <p style="margin-bottom:12px">
      2. Llena el bloque del servicio que vayas a usar y pon
      <code>'activo' =&gt; true</code>.
    </p>
    <p style="margin-bottom:12px">
      3. Déjalo en <code>'sandbox' =&gt; true</code> hasta haber probado. En pruebas
      nada cobra ni se envía de verdad.
    </p>
    <p style="margin:0;padding-top:12px;border-top:1px solid var(--lf-linea);
              font-size:12px;color:var(--lf-tinta-4)">
      Agrega <code>config/integraciones.php</code> al <code>.gitignore</code>. En el
      sistema anterior las credenciales de Emida estaban escritas dentro de
      <code>EmidaServicios/inicio.php</code>, y por eso acabaron en el repositorio.
    </p>
  </div>
</section>


<?php /* ═══════════════ CATEGORÍAS ═══════════════ */ else:
$e = $buscar_ed($categorias); ?>
<details class="lf-alta" <?= $abrir ? 'open' : '' ?>>
  <summary><?= W::icono($e?'serv':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Agregar categoría' ?></summary>
  <form method="post" action="/ajustes/guardar" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <input type="hidden" name="que" value="categoria">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:1;min-width:200px"><label class="form-label">Nombre</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="flex:2;min-width:240px"><label class="form-label">Descripción</label>
      <input class="form-control" name="descripcion" value="<?= P::e($e['descripcion'] ?? '') ?>" placeholder="Opcional"></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Agregar' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/ajustes?t=categorias">Cancelar</a><?php endif; ?>
    </div>
    <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
      Las categorías agrupan los servicios del catálogo. Una categoría con
      servicios no se puede quitar: sus ventas quedarían sin clasificar.
    </p>
  </form>
</details>

<section class="card">
  <header class="card-header">Categorías de servicio</header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table table-hover">
      <thead><tr><th>Categoría</th><th>Descripción</th>
        <th class="text-end">Servicios</th><th></th></tr></thead>
      <tbody>
      <?php if (!$categorias): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--lf-tinta-4);padding:34px">
          No hay categorías. Agrega la primera arriba.</td></tr>
      <?php endif; ?>
      <?php foreach ($categorias as $c): ?>
        <tr>
          <td data-label="Categoría"><b style="font-weight:600"><?= P::e($c['nombre']) ?></b></td>
          <td data-label="Descripción" style="font-size:12.5px;color:var(--lf-tinta-3)">
            <?= P::e($c['descripcion'] ?: '—') ?></td>
          <td data-label="Servicios" class="text-end">
            <?php if ((int)$c['servicios'] > 0): ?>
              <a href="/servicios" class="badge bg-secondary" style="text-decoration:none">
                <?= (int)$c['servicios'] ?></a>
            <?php else: ?><span style="color:var(--lf-tinta-4)">0</span><?php endif; ?>
          </td>
          <td style="text-align:right">
            <a class="lf-btn-ghost" href="/ajustes?t=categorias&editar=<?= (int)$c['id'] ?>"
               title="Editar"><?= W::icono('serv','15px') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<form method="post" action="/ajustes/alternar" id="formAlt" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>">
  <input type="hidden" name="que" id="altQue">
  <input type="hidden" name="id" id="altId">
</form>
<script>
document.querySelectorAll('.lf-alt').forEach(function(b){
  b.addEventListener('click', function(){
    var act = b.dataset.activo === '1';
    if (!confirm((act ? '¿Desactivar ' : '¿Activar ') + b.dataset.nombre + '?')) return;
    document.getElementById('altQue').value = b.dataset.que;
    document.getElementById('altId').value = b.dataset.id;
    document.getElementById('formAlt').submit();
  });
});
</script>
