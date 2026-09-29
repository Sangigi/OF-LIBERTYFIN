<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ComisionesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ComisionRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $sinDueno = $repo->sinDueno();

        // Para cada renglón sin dueño, los candidatos de su área.
        $candidatos = [];
        foreach ($sinDueno as $s) {
            $a = $s['area_nombre'];
            if (!isset($candidatos[$a])) $candidatos[$a] = $repo->colaboradoresDe($a);
        }

        Plantilla::pagina('comisiones/index', [
            'titulo'     => 'Comisiones',
            'icono'      => 'comi',
            'subtitulo'  => Fechas::rotulo($desde, $hasta),
            'resumen'    => $repo->resumen($desde, $hasta),
            'equipo'     => $repo->porColaborador($desde, $hasta),
            'areas'      => $repo->porArea($desde, $hasta),
            'sin_dueno'  => $sinDueno,
            'candidatos' => $candidatos,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Asigna dueño a un renglón que estaba en POR ASIGNAR. */
    public function reasignar()
    {
        $db = Conexion::de($_SESSION['empresa_db']);

        if (($_SESSION['usuario_rol'] ?? '') !== 'admin') {
            $this->volver('Solo un administrador puede asignar comisiones.', 'error');
        }
        if (empty($_SESSION['lf_token']) || empty($_POST['token'])
            || !hash_equals($_SESSION['lf_token'], $_POST['token'])) {
            $this->volver('No se pudo verificar el formulario.', 'error');
        }

        try {
            $nombre = (new ComisionRepo($db))->reasignar(
                (int)($_POST['renglon'] ?? 0),
                (int)($_POST['colaborador'] ?? 0)
            );
            $this->volver('Comisión asignada a ' . $nombre . '.', 'ok');
        } catch (\InvalidArgumentException $e) {
            $this->volver($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[LibertyFin] reasignar: ' . $e->getMessage());
            $this->volver('No se pudo asignar. Quedó anotado el error.', 'error');
        }
    }

    private function volver($texto, $tipo)
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /comisiones'); exit;
    }

}
