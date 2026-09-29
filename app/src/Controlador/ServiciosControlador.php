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

        Plantilla::pagina('servicios/index', [
            'titulo'    => 'Servicios',
            'icono'     => 'serv',
            'subtitulo' => Fechas::rotulo($desde, $hasta) . ' · catálogo y desempeño',
            'resumen'   => $repo->resumen($desde, $hasta),
            'catalogo'  => $repo->catalogo($desde, $hasta, $buscar),
            'top'       => $repo->masFacturan($desde, $hasta, 5),
            'areas'     => $repo->porArea($desde, $hasta),
            'desde'     => $desde, 'hasta' => $hasta, 'buscar' => $buscar,
        ]);
    }
}
