<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ComisionRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Auditoria;
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
            'desde'      => $desde,
            'hasta'      => $hasta,
            'pagina'     => max(1, Peticion::entero('p', 1)),
            'porPag'     => 12,
            'liberacion' => $repo->liberacion($desde, $hasta),
            // Cinco y cinco: las tres tarjetas de abajo se ven juntas, y
            // con cantidades distintas una queda enana al lado de otra.
            'atadas'     => $repo->atadas($desde, $hasta, 5),
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
            \LibertyFin\Servicio\Auditoria::anota('comision.reasignar',
                'renglón ' . (int)($_POST['renglon'] ?? 0),
                'POR ASIGNAR', $nombre);
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


    /**
     * El contenido de la ventana de un colaborador.
     *
     * Devuelve solo el trozo de HTML, no la pagina: la ventana se abre
     * sin recargar y la caja tiene al cliente enfrente.
     */
    public function colaborador()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $quien = trim(Peticion::texto('quien', ''));
        if ($quien === '') { http_response_code(400); exit; }

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $filas = (new ComisionRepo($db))->detalleColaborador($quien, $desde, $hasta);

        Plantilla::parcial('comisiones/detalle', [
            'quien'  => $quien,
            'filas'  => $filas,
            'total'  => array_sum(array_column($filas, 'comision')),
            'desde'  => $desde,
            'hasta'  => $hasta,
        ]);
        exit;
    }
}
