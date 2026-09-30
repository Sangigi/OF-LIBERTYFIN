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
            'desde'        => $desde, 'hasta' => $hasta,
        ]);
    }

    /**
     * Descarga el detalle en CSV.
     *
     * Con BOM y punto y coma: sin eso, Excel en español abre el archivo
     * con todo en una columna y rompe los acentos. Es un detalle tonto
     * que hace la diferencia entre un reporte que se usa y uno que no.
     */
    public function csv()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ReporteRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));
        $filas = $repo->detalle($desde, $hasta);

        $nombre = 'libertyfin_' . $desde . '_a_' . $hasta . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: no-store');

        $s = fopen('php://output', 'w');
        fwrite($s, "\xEF\xBB\xBF");   // BOM para Excel
        fputcsv($s, ['Folio','Fecha','Cliente','Área','Subtotal','IVA','Total',
                     'Cobrado','Saldo','Gastos','Comisión','Estado'], ';');
        foreach ($filas as $f) {
            fputcsv($s, [
                $f['folio'], $f['fecha'], $f['cliente'], $f['area'],
                number_format($f['subtotal'], 2, '.', ''),
                number_format($f['iva'], 2, '.', ''),
                number_format($f['total'], 2, '.', ''),
                number_format($f['cobrado'], 2, '.', ''),
                number_format($f['saldo'], 2, '.', ''),
                number_format($f['gastos'], 2, '.', ''),
                number_format($f['comision'], 2, '.', ''),
                $f['estado'],
            ], ';');
        }
        fclose($s);
        exit;
    }
}
