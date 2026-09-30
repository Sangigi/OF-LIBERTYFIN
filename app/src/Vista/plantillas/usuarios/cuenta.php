<?php
use LibertyFin\Vista\Plantilla as P;
use LibertyFin\Vista\Widget as W;
use LibertyFin\Datos\UsuarioRepo as U;
use LibertyFin\Dominio\Permisos as Perm;
if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['lf_token'];
?>

<?php if ($aviso): ?>
<div class="alert alert-<?= $aviso['tipo']==='error'?'danger':'success' ?>" style="margin-bottom:18px">
  <?= W::icono('alerta','18px') ?><span><?= P::e($aviso['texto']) ?></span>
</div>
<?php endif; ?>

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
        <button class="btn btn-primary" type="submit"
                style="width:100%;margin-top:16px;padding:12px">Cambiar</button>
      </form>
      <p style="font-size:11.5px;color:var(--lf-tinta-4);margin-top:14px;padding-top:14px;
                border-top:1px solid var(--lf-linea);line-height:1.5">
        Al cambiarla se cierra tu sesión y tendrás que entrar de nuevo.
        Si la cambias porque crees que alguien la sabía, dejar la sesión abierta
        no serviría de nada.
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card-header">Mis datos</header>
    <div class="card-body" style="font-size:13px">
      <?php foreach ([
        'Nombre'   => $_SESSION['usuario_nombre'] ?? '',
        'Rol'      => (Perm::ROLES[$_SESSION['usuario_rol'] ?? '']['rotulo'] ?? ($_SESSION['usuario_rol'] ?? '')),
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
    </div>
  </section>
</div>
