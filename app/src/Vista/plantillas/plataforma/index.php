<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Dominio\Permisos as Perm;

if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
$e = null;
foreach ($usuarios as $u) if ((int)$u['id'] === (int)$editando) $e = $u;
$yo = (int)($_SESSION['usuario_id'] ?? 0);
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

<div class="alert alert-info" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?>
  <span>Estas cuentas <b>no pertenecen a ninguna empresa</b>. Viven en la base
    principal, así que no aparecen en el equipo de ningún cliente ni su
    administrador puede tocarlas.</span>
</div>

<details class="lf-alta" <?= $e ? 'open' : '' ?>>
  <summary><?= W::icono($e?'cliente':'mas','16px') ?>
    <?= $e ? 'Editar ' . P::e($e['nombre']) : 'Nueva cuenta de plataforma' ?></summary>
  <form method="post" action="/plataforma/usuario" class="lf-form">
    <input type="hidden" name="token" value="<?= P::e($token) ?>">
    <?php if ($e): ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><?php endif; ?>
    <div style="flex:2;min-width:220px"><label class="form-label">Nombre completo</label>
      <input class="form-control" name="nombre" value="<?= P::e($e['nombre'] ?? '') ?>" required></div>
    <div style="width:190px"><label class="form-label">Usuario</label>
      <input class="form-control lf-mono" name="username" value="<?= P::e($e['username'] ?? '') ?>"
             pattern="[a-z0-9._-]{3,30}" required placeholder="solo minúsculas"></div>
    <div style="flex:1;min-width:200px"><label class="form-label">Correo</label>
      <input class="form-control" type="email" name="email" value="<?= P::e($e['email'] ?? '') ?>"
             placeholder="Opcional"></div>
    <div style="width:200px"><label class="form-label">Rol</label>
      <select class="form-select" name="rol" id="selRolP" required>
        <?php foreach ($roles as $k=>$v): ?>
          <option value="<?= $k ?>" data-para="<?= P::e($v['para']) ?>"
            <?= ($e['rol'] ?? '')===$k?'selected':'' ?>><?= P::e($v['rotulo']) ?></option>
        <?php endforeach; ?>
      </select>
      <small id="rolParaP" style="font-size:11px;color:var(--lf-tinta-4);
             margin-top:5px;display:block;line-height:1.4;max-width:190px"></small></div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary" type="submit"><?= $e?'Guardar':'Crear' ?></button>
      <?php if ($e): ?><a class="btn btn-secondary" href="/plataforma">Cancelar</a><?php endif; ?>
    </div>
    <?php if (!$e): ?>
      <p style="width:100%;font-size:11.5px;color:var(--lf-tinta-4);margin:0">
        La contraseña se genera al crear y se muestra <b>una sola vez</b>.
      </p>
    <?php endif; ?>
  </form>
</details>
<script>
(function(){
  var s = document.getElementById('selRolP'), t = document.getElementById('rolParaP');
  if (!s || !t) return;
  function v(){ t.textContent = s.options[s.selectedIndex].dataset.para || ''; }
  s.addEventListener('change', v); v();
})();
</script>

<section class="card">
  <header class="card-header">
    <div><span>Equipo de LibertyFin</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Una cuenta no se borra, se desactiva</p></div>
    <span class="badge bg-secondary"><?= count($usuarios) ?></span>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Usuario</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php if (!$usuarios): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--lf-tinta-4);padding:30px">
          No hay cuentas de plataforma. Crea la primera arriba.</td></tr>
      <?php endif; ?>
      <?php foreach ($usuarios as $u): $esYo = (int)$u['id'] === $yo; ?>
        <tr style="<?= $u['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Usuario">
            <b style="font-weight:600"><?= P::e($u['nombre']) ?>
              <?php if ($esYo): ?><span style="color:var(--lf-tinta-4);font-weight:400"> · tú</span><?php endif; ?></b>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($u['username']) ?><?= $u['email'] ? ' · ' . P::e($u['email']) : '' ?></span>
          </td>
          <td data-label="Rol">
            <span class="badge <?= $u['rol']==='superadmin' ? 'bg-success' : 'bg-secondary' ?>">
              <?= P::e(Perm::rotulo($u['rol'])) ?></span></td>
          <td data-label="Último acceso" class="lf-mono" style="font-size:12px">
            <?= $u['ultimo_acceso'] ? date('d/m/y H:i', strtotime($u['ultimo_acceso']))
                : '<span style="color:var(--lf-tinta-4)">nunca</span>' ?></td>
          <td data-label="Estado">
            <span class="badge <?= $u['activo'] ? 'bg-success' : 'bg-secondary' ?>">
              <?= $u['activo'] ? 'Activa' : 'Desactivada' ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="lf-btn-ghost" href="/plataforma?editar=<?= (int)$u['id'] ?>"
               title="Editar"><?= W::icono('cliente','15px') ?></a>
            <button type="button" class="lf-btn-ghost lf-cl-p" title="Restablecer contraseña"
                    data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>">⚿</button>
            <?php if (!$esYo): ?>
              <button type="button" class="lf-btn-ghost lf-al-p"
                      data-id="<?= (int)$u['id'] ?>" data-n="<?= P::e($u['nombre']) ?>"
                      data-a="<?= $u['activo'] ? 1 : 0 ?>"><?= $u['activo'] ? '&times;' : '✓' ?></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <header class="card-header">
    <div><span>Suspender empresas</span>
      <p style="font-size:12px;color:var(--lf-tinta-4);margin-top:2px;font-weight:400">
        Suspender no borra: sus datos quedan enteros y se puede deshacer</p></div>
  </header>
  <div class="table-responsive lf-cards" style="padding:0 12px 6px">
    <table class="table">
      <thead><tr><th>Empresa</th><th>Plan</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($empresas as $em): ?>
        <tr style="<?= $em['activo'] ? '' : 'opacity:.55' ?>">
          <td data-label="Empresa">
            <a href="/soporte/<?= (int)$em['id'] ?>" style="font-weight:600;color:var(--lf-tinta)">
              <?= P::e($em['nombre_empresa']) ?></a>
            <span style="display:block;color:var(--lf-tinta-4);font-size:11px;font-family:var(--lf-mono)">
              <?= P::e($em['nombre_base_datos']) ?></span></td>
          <td data-label="Plan" style="font-size:12.5px"><?= P::e(ucfirst($em['plan'] ?: 'prueba')) ?></td>
          <td data-label="Estado">
            <span class="badge <?= $em['activo'] ? 'bg-success' : 'bg-warning' ?>">
              <?= $em['activo'] ? 'Activa' : 'Suspendida' ?></span></td>
          <td style="text-align:right">
            <button type="button" class="btn btn-secondary btn-sm lf-susp"
                    data-id="<?= (int)$em['id'] ?>" data-n="<?= P::e($em['nombre_empresa']) ?>"
                    data-a="<?= $em['activo'] ? 1 : 0 ?>">
              <?= $em['activo'] ? 'Suspender' : 'Reactivar' ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">
    <b>No hay forma de eliminar una empresa, y es a propósito.</b> Sus ventas y
    facturas hay que conservarlas cinco años, y la bitácora existe para contestar
    "¿quién hizo esto?" — si se pudiera borrar, dejaría de contestarlo justo
    cuando alguien tenga motivo para querer que no conteste.
  </div>
</section>

<form method="post" action="/plataforma/clave" id="fClP" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="clPId">
</form>
<form method="post" action="/plataforma/alternar" id="fAlP" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="alPId">
</form>
<form method="post" action="/plataforma/empresa" id="fSusp" hidden>
  <input type="hidden" name="token" value="<?= P::e($token) ?>"><input type="hidden" name="id" id="suspId">
</form>
<script>
document.querySelectorAll('.lf-cl-p').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm('¿Restablecer la contraseña de ' + b.dataset.n + '?')) return;
    document.getElementById('clPId').value = b.dataset.id;
    document.getElementById('fClP').submit();
  });
});
document.querySelectorAll('.lf-al-p').forEach(function(b){
  b.addEventListener('click', function(){
    if (!confirm((b.dataset.a === '1' ? '¿Desactivar a ' : '¿Activar a ') + b.dataset.n + '?')) return;
    document.getElementById('alPId').value = b.dataset.id;
    document.getElementById('fAlP').submit();
  });
});
document.querySelectorAll('.lf-susp').forEach(function(b){
  b.addEventListener('click', function(){
    var s = b.dataset.a === '1';
    if (!confirm((s ? '¿Suspender a ' : '¿Reactivar a ') + b.dataset.n + '?\n\n'
      + (s ? 'Su gente deja de entrar. Sus datos NO se borran y esto se puede deshacer.'
           : 'Su gente vuelve a entrar normalmente.'))) return;
    document.getElementById('suspId').value = b.dataset.id;
    document.getElementById('fSusp').submit();
  });
});
</script>
