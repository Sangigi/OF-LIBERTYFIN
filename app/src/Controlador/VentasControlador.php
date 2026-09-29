<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

/**
 * Ventas.
 *
 * Compárese con ventas_lista.php del sistema anterior: 2,662 líneas con
 * 12 consultas y 320 bloques HTML mezclados. Aquí el controlador decide,
 * el repositorio consulta y la plantilla pinta.
 */
final class VentasControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        // Por defecto, el mes en curso. Nunca "todo": eso fue lo que hacía
        // que el histórico mostrara meses que nadie quería ver.
        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $filtros = [
            'estado' => Peticion::opcion('estado', ['completada','pendiente','cancelada']),
            'buscar' => Peticion::texto('q'),
        ];

        $pagina  = max(1, Peticion::entero('p', 1));
        $porPag  = 25;
        $desfase = ($pagina - 1) * $porPag;

        $resumen = $repo->resumen($desde, $hasta, $filtros);
        $ventas  = $repo->listado($desde, $hasta, $filtros, $porPag, $desfase);
        $total   = $repo->cuantas($desde, $hasta, $filtros);
        $saldos  = $repo->saldosAbiertos(5);
        $meses   = $repo->cobradoPorMes(6);

        Plantilla::pagina('ventas/index', [
            'titulo'   => 'Ventas',
            'icono'    => 'venta',
            'subtitulo'=> Fechas::rotulo($desde, $hasta) . ' · ' . number_format($total) . ' ventas',
            'resumen'  => $resumen,
            'ventas'   => $ventas,
            'saldos'   => $saldos,
            'meses'    => $meses,
            'desde'    => $desde,
            'hasta'    => $hasta,
            'filtros'  => $filtros,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int)ceil($total / $porPag)),
            'total'    => $total,
        ]);
    }

}
