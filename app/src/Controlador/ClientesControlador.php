<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ClienteRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class ClientesControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new ClienteRepo($db);

        $desde  = Peticion::fecha('desde', date('Y-m-01'));
        $hasta  = Peticion::fecha('hasta', date('Y-m-t'));
        $buscar = Peticion::texto('q');
        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = 25;

        $total = $repo->cuantos($desde, $hasta, $buscar);

        Plantilla::pagina('clientes/index', [
            'titulo'     => 'Clientes',
            'icono'      => 'cliente',
            'subtitulo'  => Fechas::rotulo($desde, $hasta) . ' · ' . number_format($total) . ' con actividad',
            'resumen'    => $repo->resumen($desde, $hasta),
            'clientes'   => $repo->listado($desde, $hasta, $buscar, $porPag, ($pagina-1)*$porPag),
            'top'        => $repo->masFacturan($desde, $hasta, 5),
            'antiguedad' => $repo->antiguedad(),
            'desde'      => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($total / $porPag)),
            'total'      => $total,
        ]);
    }
}
