<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\CrearEmpresa;
use LibertyFin\Vista\Plantilla;

/**
 * Registro de empresas · pantalla PÚBLICA.
 *
 * Es la única ruta sin sesión además del login, así que se trata como
 * hostil: tope por correo, tope por IP y nada que cree recursos de golpe.
 * Lo que se guarda es una SOLICITUD; la base se crea al aprobarla.
 */
final class RegistroControlador
{
    public function formulario()
    {
        if (!empty($_SESSION['logged_in'])) { header('Location: /'); exit; }
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        Plantilla::pagina('registro/index', [
            'titulo'  => 'Registra tu negocio',
            'error'   => $_SESSION['lf_error'] ?? null,
            'ok'      => $_SESSION['lf_ok'] ?? null,
            'datos'   => $_SESSION['lf_registro'] ?? [],
        ], 'layout-limpio');
        unset($_SESSION['lf_error'], $_SESSION['lf_ok'], $_SESSION['lf_registro']);
    }

    public function enviar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.');
        }
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal'] ?? '');
            (new CrearEmpresa($principal))->solicitar($_POST, $_SERVER['REMOTE_ADDR'] ?? '');
            $_SESSION['lf_ok'] = 'Recibimos tu solicitud. Te escribimos a '
                . htmlspecialchars($_POST['email_admin'] ?? '', ENT_QUOTES)
                . ' en cuanto tu cuenta esté lista.';
            header('Location: /registro'); exit;
        } catch (\InvalidArgumentException $e) {
            return $this->fallo($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[LibertyFin] registro: ' . $e->getMessage());
            return $this->fallo('No se pudo enviar. Inténtalo en un momento.');
        }
    }

    private function fallo($mensaje)
    {
        $_SESSION['lf_error'] = $mensaje;
        // Se devuelve lo capturado para no obligar a rellenar todo.
        $_SESSION['lf_registro'] = array_intersect_key($_POST, array_flip([
            'nombre_empresa','giro_comercial','rfc','nombre_contacto',
            'email_admin','telefono','no_distribuidor',
        ]));
        header('Location: /registro'); exit;
    }
}
