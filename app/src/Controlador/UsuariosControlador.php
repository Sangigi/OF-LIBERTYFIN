<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\UsuarioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

final class UsuariosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new UsuarioRepo($db);
        $this->soloAdmin();

        $editar = Peticion::entero('editar');
        Plantilla::pagina('usuarios/index', [
            'titulo'     => 'Usuarios',
            'icono'      => 'cliente',
            'subtitulo'  => $_SESSION['empresa_nombre'] ?? '',
            'usuarios'   => $repo->todos_(),
            'sucursales' => $repo->sucursales(),
            'editando'   => $editar ? $repo->uno_($editar) : null,
            'abrir'      => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();

        $repo = new UsuarioRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) { $repo->actualizar($id, $_POST); $m = 'Usuario actualizado.'; }
            else     { $repo->crear($_POST);           $m = 'Usuario dado de alta.'; }
            $this->volver('/usuarios', $m, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar usuario: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo guardar el usuario.', 'error');
        }
    }

    /** Un administrador le pone contraseña nueva a otro. */
    public function restablecer()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $n = (new UsuarioRepo($db))->restablecerClave(
                (int)($_POST['id'] ?? 0), $_POST['clave'] ?? '');
            $this->volver('/usuarios',
                'Contraseña de ' . $n . ' restablecida. Dísela en persona, no por escrito.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] restablecer: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo restablecer la contraseña.', 'error');
        }
    }

    public function alternar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->soloAdmin(); $this->token();
        try {
            $a = (new UsuarioRepo($db))->alternar(
                (int)($_POST['id'] ?? 0), $_SESSION['usuario_id'] ?? 0);
            $this->volver('/usuarios', $a ? 'Usuario activado.'
                : 'Usuario desactivado. Ya no puede entrar.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/usuarios', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar usuario: ' . $e->getMessage());
            $this->volver('/usuarios', 'No se pudo cambiar el estado.', 'error');
        }
    }

    // ── Mi cuenta · sin rol de administrador ──

    public function miCuenta()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $foto = (new UsuarioRepo($db))->foto($_SESSION['usuario_id'] ?? 0);
        $_SESSION['lf_foto'] = $foto;

        Plantilla::pagina('usuarios/cuenta', [
            'foto'      => $foto,
            'titulo'    => 'Mi cuenta',
            'icono'     => 'cliente',
            'subtitulo' => $_SESSION['usuario_nombre'] ?? '',
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function cambiarClave()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta');
        try {
            (new UsuarioRepo($db))->cambiarClave(
                (int)($_SESSION['usuario_id'] ?? 0),
                $_POST['actual'] ?? '', $_POST['nueva'] ?? '');

            // Se cierra la sesión a propósito: si alguien cambió la
            // contraseña porque sospecha que se la sabían, dejar la sesión
            // viva no sirve de nada.
            Autenticar::salir();
            session_start();
            $_SESSION['lf_error'] = 'Contraseña cambiada. Entra de nuevo.';
            header('Location: /login'); exit;
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] cambiarClave: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo cambiar la contraseña.', 'error');
        }
    }

    private function soloAdmin()
    {
        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            http_response_code(403);
            Plantilla::pagina('errores/404', ['titulo'=>'Sin permiso','icono'=>'alerta','subtitulo'=>'']);
            exit;
        }
    }

    private function token($destino = '/usuarios')
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver($destino, 'No se pudo verificar el formulario.', 'error');
        }
    }

    private function volver($destino, $texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: ' . $destino); exit;
    }

    /** Foto de perfil. Cada quien la suya, sin pedir permiso a nadie. */
    public function guardarFoto()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $this->token('/cuenta');
        $repo = new UsuarioRepo($db);
        $id   = (int)($_SESSION['usuario_id'] ?? 0);
        try {
            if (!empty($_POST['quitar'])) {
                $antes = $repo->foto($id);
                $repo->guardarFoto($id, '');
                unset($_SESSION['lf_foto']);
                if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
                $this->volver('/cuenta', 'Foto quitada.', 'ok');
            }
            $antes = $repo->foto($id);
            $ruta  = \LibertyFin\Servicio\Archivos::imagen($_FILES['foto'] ?? [], 'perfil');
            $repo->guardarFoto($id, $ruta);
            $_SESSION['lf_foto'] = $ruta;
            if ($antes) \LibertyFin\Servicio\Archivos::borrar($antes);
            $this->volver('/cuenta', 'Foto actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver('/cuenta', $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] foto: ' . $e->getMessage());
            $this->volver('/cuenta', 'No se pudo guardar la foto.', 'error');
        }
    }
}
