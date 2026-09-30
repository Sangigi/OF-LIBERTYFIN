<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ServicioRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ServiciosControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ServicioRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $buscar = Peticion::texto('q');
        $editar = Peticion::entero('editar');

        Plantilla::pagina('servicios/index', [
            'titulo'    => 'Servicios',
            'icono'     => 'serv',
            'subtitulo' => Fechas::rotulo($desde, $hasta) . ' · catálogo y desempeño',
            'resumen'   => $repo->resumen($desde, $hasta),
            'catalogo'  => $repo->catalogo($desde, $hasta, $buscar),
            'top'       => $repo->masFacturan($desde, $hasta, 5),
            'areas'     => $repo->porArea($desde, $hasta),
            'desde'     => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'categorias'=> $repo->categorias(),
            'editando'  => $editar ? $repo->uno_($editar) : null,
            'abrir'     => $editar > 0 || Peticion::texto('nuevo') !== '',
            'aviso'     => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    public function guardar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');

        $repo = new ServicioRepo($db);
        $id   = (int)($_POST['id'] ?? 0);
        try {
            if ($id) { $repo->actualizar($id, $_POST); $msg = 'Servicio actualizado.'; }
            else     { $repo->crear($_POST);           $msg = 'Servicio dado de alta.'; }
            $this->volver($msg, 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] guardar servicio: ' . $e->getMessage());
            $this->volver('No se pudo guardar el servicio.', 'error');
        }
    }

    /** Activa o desactiva. Nunca se borra: rompería las ventas viejas. */
    public function alternar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        if (!$this->tokenValido()) $this->volver('No se pudo verificar el formulario.', 'error');
        try {
            $a = (new ServicioRepo($db))->alternar((int)($_POST['id'] ?? 0));
            $this->volver($a ? 'Servicio activado.' : 'Servicio desactivado. Ya no aparece en Caja.', 'ok');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] alternar servicio: ' . $e->getMessage());
            $this->volver('No se pudo cambiar el estado.', 'error');
        }
    }

    private function tokenValido()
    {
        return !empty($_SESSION['lf_token']) && !empty($_POST['token'])
            && hash_equals($_SESSION['lf_token'], $_POST['token']);
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /servicios'); exit;
    }
}
