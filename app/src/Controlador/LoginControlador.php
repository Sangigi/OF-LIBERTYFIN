<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Servicio\Autenticar;
use LibertyFin\Vista\Plantilla;

final class LoginControlador
{
    public function mostrar()
    {
        if (Autenticar::sesionValida()) { header('Location: /'); exit; }
        if (empty($_SESSION['lf_token'])) $_SESSION['lf_token'] = bin2hex(random_bytes(16));

        Plantilla::pagina('login', [
            'titulo'  => 'Entrar',
            'error'   => $_SESSION['lf_error'] ?? null,
            'usuario' => $_SESSION['lf_usuario'] ?? '',
            'bloqueo' => Autenticar::bloqueoRestante(),
        ], 'layout-limpio');
        unset($_SESSION['lf_error'], $_SESSION['lf_usuario']);
    }

    public function entrar()
    {
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            return $this->fallo('La sesión expiró. Intenta de nuevo.', '');
        }

        $id = trim($_POST['usuario'] ?? '');
        try {
            $principal = Conexion::de($GLOBALS['lf_bd_principal']);
            (new \LibertyFin\Datos\AutenticacionRepo($principal))->prepararIndice();
            (new Autenticar($principal))->entrar($id, $_POST['clave'] ?? '');
            header('Location: /'); exit;
        } catch (\RuntimeException $e) {
            return $this->fallo($e->getMessage(), $id);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] login: ' . $e->getMessage());
            return $this->fallo('No se pudo conectar. Inténtalo en un momento.', $id);
        }
    }

    public function salir()
    {
        Autenticar::salir();
        session_start();
        $_SESSION['lf_error'] = 'Sesión cerrada.';
        header('Location: /login'); exit;
    }

    private function fallo($mensaje, $usuario)
    {
        $_SESSION['lf_error']   = $mensaje;
        $_SESSION['lf_usuario'] = $usuario;
        header('Location: /login'); exit;
    }
}
