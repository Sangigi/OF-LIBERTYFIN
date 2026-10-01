<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\ReporteRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ReportesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ReporteRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $tipo  = Peticion::opcion('tipo',
                    array_keys(\LibertyFin\Servicio\Reportes::TIPOS), 'area');

        Plantilla::pagina('reportes/index', [
            'titulo'       => 'Reportes',
            'icono'        => 'pct',
            'subtitulo'    => Fechas::rotulo($desde, $hasta),
            'r'            => $repo->resultado($desde, $hasta),
            'meses'        => $repo->porMes(6),
            'areas'        => $repo->porArea($desde, $hasta),
            'servicios'    => $repo->porServicio($desde, $hasta, 10),
            'metodos'      => $repo->porMetodo($desde, $hasta),
            'colaboradores'=> $repo->porColaborador($desde, $hasta),
            // Los ocho reportes: la lista para las pestañas y el que se
            // está viendo. Salen del mismo sitio que el Excel y la hoja
            // imprimible, para que no puedan decir cosas distintas.
            'tipos'        => \LibertyFin\Servicio\Reportes::TIPOS,
            'tipo'         => $tipo,
            // LOS OCHO DE UNA VEZ, para cambiar de pestaña sin recargar.
            //
            // Son ocho consultas de agregado contra el mismo periodo que
            // ya se está consultando para las cifras de arriba. A cambio,
            // comparar "por área" con "por colaborador" deja de costar
            // dos viajes al servidor y la pérdida del lugar en la página.
            'reportes'     => (new \LibertyFin\Servicio\Reportes($db))->todos($desde, $hasta),
            'desglose'     => (new \LibertyFin\Servicio\Reportes($db))->desglose(
                                $desde, $hasta, Peticion::opcion('por', ['servicio','origen'], 'servicio')),
            'por'          => Peticion::opcion('por', ['servicio','origen'], 'servicio'),
            'desde'        => $desde, 'hasta' => $hasta,
        ]);
    }

    /**
     * Descarga el periodo en Excel, un reporte por hoja.
     *
     * Reemplaza al CSV. Un CSV manda "$1,234.00" como texto: Excel no lo
     * suma, no lo ordena y no lo grafica, y quien lo recibe termina
     * reescribiéndolo a mano. Aquí los montos son números con formato de
     * moneda, las fechas son fechas, y la fila de encabezados queda
     * congelada.
     */
    public function excel()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $libro = (new \LibertyFin\Servicio\Reportes($db))->libroCompleto($desde, $hasta);
        $tmp = tempnam(sys_get_temp_dir(), 'lf') . '.xlsx';
        $libro->guardar($tmp);

        $nombre = 'libertyfin-reportes-' . date('Ymd', strtotime($desde))
                . '-' . date('Ymd', strtotime($hasta)) . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /**
     * La hoja imprimible, que el navegador convierte en PDF.
     *
     * POR QUÉ NO SE ESCRIBE UN PDF
     *
     * Generar PDF en PHP sin librerías significa dibujar texto a mano,
     * sin acentos decentes ni control de saltos de página. Con una hoja
     * HTML y `@media print`, el navegador lo convierte en un PDF que se
     * ve bien, respeta los acentos, repite el encabezado en cada página y
     * numera. Y se imprime directo, que es lo que casi siempre quieren.
     */
    public function imprimir()
    {
        $db = Conexion::de($_SESSION['empresa_db']);
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $tipo = Peticion::opcion('tipo',
            array_keys(\LibertyFin\Servicio\Reportes::TIPOS), 'area');

        $srv = new \LibertyFin\Servicio\Reportes($db);
        // "todos" imprime los ocho, uno tras otro: es el paquete que se
        // manda al contador a fin de mes.
        $reps = Peticion::texto('todos', '') === '1'
              ? array_map(function ($t) use ($srv, $desde, $hasta) {
                    return $srv->armar($t, $desde, $hasta); },
                  array_keys(\LibertyFin\Servicio\Reportes::TIPOS))
              : [$srv->armar($tipo, $desde, $hasta)];

        Plantilla::pagina('reportes/imprimir', [
            'titulo'   => 'Reportes',
            'reportes' => $reps,
            'periodo'  => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'empresa'  => $_SESSION['empresa_nombre'] ?? 'LibertyFin',
            'auto'     => Peticion::texto('auto', '') === '1',
        ], 'layout-limpio');
    }
}
