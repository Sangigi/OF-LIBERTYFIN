<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\CobranzaRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class CobranzaControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new CobranzaRepo($db);

        $tramo  = Peticion::opcion('tramo', ['t30','t60','t60mas'], 'todos');
        $orden  = Peticion::opcion('orden', ['dias','saldo','cliente'], 'dias');
        $buscar = Peticion::texto('q');
        $vista  = Peticion::opcion('ver', ['ventas','clientes'], 'ventas');

        $resumen = $repo->resumen();

        Plantilla::pagina('cobranza/index', [
            'titulo'    => 'Cobranza',
            'icono'     => 'reloj',
            'subtitulo' => (int)($resumen['ventas'] ?? 0) . ' ventas con saldo en '
                         . (int)($resumen['clientes'] ?? 0) . ' clientes',
            'resumen'   => $resumen,
            'filas'     => $vista === 'clientes' ? [] : $repo->listado($orden, $tramo, $buscar),
            'clientes'  => $vista === 'clientes' ? $repo->porCliente(40) : [],
            'tramo'     => $tramo, 'orden' => $orden, 'buscar' => $buscar, 'vista' => $vista,
        ]);
    }
}
