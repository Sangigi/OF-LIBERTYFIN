<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Integraciones;
use LibertyFin\Vista\Plantilla;

/**
 * Facturación CFDI.
 *
 * La pantalla solo existe si hay credenciales de Facturapi, igual que
 * Recargas con Emida.
 *
 * Y aunque existan, timbrar necesita tres cosas más que el negocio tiene
 * que haber llenado: sus datos fiscales, los del cliente, y que la venta
 * esté cobrada. La pantalla lo dice antes de dejar intentarlo, en vez de
 * fallar contra el SAT con un código que nadie entiende.
 *
 * ESTADO: el timbrado en sí queda marcado y sin implementar hasta tener
 * una cuenta de Facturapi con la que probarlo.
 */
final class FacturacionControlador
{
    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $cfg  = new ConfigRepo($db);
        $repo = new VentaRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        // Qué falta antes de poder timbrar. Se revisa aquí y no al
        // enviar: descubrirlo con la factura a medias es peor.
        $fiscales = [];
        foreach (['tipo_persona','rfc_fiscal','cp_fiscal','razon_social','regimen_sat'] as $c) {
            $fiscales[$c] = $cfg->valorDe('fiscal.' . $c, '');
        }
        $faltan = [];
        if (trim($fiscales['rfc_fiscal']) === '')   $faltan[] = 'tu RFC';
        if (trim($fiscales['cp_fiscal']) === '')    $faltan[] = 'tu código postal fiscal';
        if (trim($fiscales['regimen_sat']) === '')  $faltan[] = 'tu régimen fiscal';
        if (trim($fiscales['razon_social']) === '') $faltan[] = 'tu razón social';

        $cuenta = new CuentaRepo($db);
        $docs   = $cuenta->estadoDocumentacion();

        $pagina = max(1, Peticion::entero('p', 1));
        $porPag = Peticion::POR_PAGINA;
        $totalV = $repo->cuantas($desde, $hasta, []);
        $cfgFac = Integraciones::de('facturapi');

        Plantilla::pagina('facturacion/index', [
            'titulo'     => 'Facturación',
            'icono'      => 'serv',
            'subtitulo'  => Fechas::rotulo($desde, $hasta),
            'sandbox'    => !empty($cfgFac['sandbox']),
            'faltan'     => $faltan,
            'docs'       => $docs,
            'ventas'     => $repo->listado($desde, $hasta, [], $porPag, ($pagina-1)*$porPag),
            'pagina'     => $pagina,
            'paginas'    => max(1, (int)ceil($totalV / $porPag)),
            'totalV'     => $totalV,
            'desde'      => $desde, 'hasta' => $hasta,
            'aviso'      => $_SESSION['lf_aviso'] ?? null,
        ]);
        unset($_SESSION['lf_aviso']);
    }

    /** Timbra una venta. Pendiente de conectar con Facturapi. */
    public function timbrar($id)
    {
        $_SESSION['lf_aviso'] = [
            'texto' => 'Falta conectar el timbrado con Facturapi. La pantalla y las '
                     . 'validaciones están listas; el envío se implementa cuando haya '
                     . 'cuenta para probarlo contra el SAT.',
            'tipo'  => 'error',
        ];
        header('Location: /facturacion'); exit;
    }
}
