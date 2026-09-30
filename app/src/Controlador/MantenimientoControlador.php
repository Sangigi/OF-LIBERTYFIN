<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Dominio\Permisos;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Mantenimiento · para el rol de soporte.
 *
 * Dos cosas: apagar secciones que una empresa no usa, y ver el
 * diagnóstico de su base sin tener que pedir accesos ni abrir un
 * cliente de MySQL.
 *
 * Soporte NO mueve dinero. Puede mirar todo y cambiar qué se ve, pero
 * no cobra, no cancela pagos ni asigna comisiones. Si además pudiera,
 * no habría forma de saber si un descuadre lo causó la empresa o quien
 * vino a ayudar.
 */
final class MantenimientoControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ConfigRepo($db);

        Plantilla::pagina('mantenimiento/index', [
            'titulo'       => 'Mantenimiento',
            'icono'        => 'alerta',
            'subtitulo'    => $_SESSION['empresa_nombre'] ?? '',
            'secciones'    => $repo->secciones(),
            'diagnostico'  => $repo->diagnostico(),
            'integraciones'=> Integraciones::estado(),
            'roles'        => Permisos::ROLES,
            'aviso'        => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function secciones()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }
        try {
            (new ConfigRepo($db))->alternarSeccion($_POST['seccion'] ?? '');
            $this->volver('Sección actualizada.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] mantenimiento: ' . $e->getMessage());
            $this->volver('No se pudo cambiar la sección.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /mantenimiento'); exit;
    }
}
