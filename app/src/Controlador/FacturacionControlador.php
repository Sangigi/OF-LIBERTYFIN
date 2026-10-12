<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\ConfigRepo;
use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\CuentaRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Http\Peticion;
use LibertyFin\Servicio\Facturapi;
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

    /**
     * Timbra una venta contra Facturapi.
     *
     * La venta tiene que estar cobrada al 100% y tener cliente con RFC/CP.
     * El CFDI se emite como PUE (Pago en Una Exhibición): como el candado
     * de saldo ya exigió cobro completo, no hay rama PPD. Si algún día se
     * quiere facturar anticipos o parcialidades, ahí hay que meter la
     * lógica de PPD + complemento de pago por cada abono.
     */
    public function timbrar($id)
    {
        $id   = (int) $id;
        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);
        $cfg  = new ConfigRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            $this->aviso('Venta no encontrada.', 'error');
        }

        // ── Mismos candados que la vista anuncia ────────────────────────
        if ((float) $venta['saldo'] > 0.01) {
            $this->aviso('No se puede facturar: la venta aún tiene saldo pendiente. '
                       . 'El SAT timbra el pago, no la promesa.', 'error');
        }
        if (empty($venta['cliente_id'])) {
            $this->aviso('No se puede facturar: la venta no tiene cliente. '
                       . 'El CFDI necesita el RFC de quien recibe.', 'error');
        }
        if (!empty($venta['factura_uuid'])) {
            $this->aviso('Esta venta ya fue facturada (UUID ' . $venta['factura_uuid'] . ').', 'error');
        }

        $docs = (new CuentaRepo($db))->estadoDocumentacion();
        if (($docs['estado'] ?? '') !== 'aprobada') {
            $this->aviso('La documentación del negocio todavía no está aprobada.', 'error');
        }

        // ── Credenciales del PAC ────────────────────────────────────────
        $fac   = Integraciones::de('facturapi');
        $clave = trim((string) ($fac['api_key'] ?? ''));
        if ($clave === '') {
            $this->aviso('No hay credenciales de Facturapi configuradas.', 'error');
        }

        // ── Datos fiscales del emisor ───────────────────────────────────
        // Se validan aquí y no solo en la vista porque entre abrir la
        // pantalla y apretar "Timbrar" alguien pudo haber borrado un campo
        // en Mi cuenta.
        $emisor = [
            'rfc'     => strtoupper(trim((string) $cfg->valorDe('fiscal.rfc_fiscal', ''))),
            'razon'   => strtoupper(trim((string) $cfg->valorDe('fiscal.razon_social', ''))),
            'regimen' => trim((string) $cfg->valorDe('fiscal.regimen_sat', '')),
            'cp'      => trim((string) $cfg->valorDe('fiscal.cp_fiscal', '')),
        ];
        foreach ($emisor as $campo => $valor) {
            if ($valor === '') {
                $this->aviso("Faltan los datos fiscales del negocio ($campo). "
                           . "Complétalos en Mi cuenta.", 'error');
            }
        }

        // ── Datos del receptor (ya viven en `clientes`) ─────────────────
        $r = $repo->datosFiscalesCliente((int) $venta['cliente_id']);
        if (!$r || trim((string) $r['rfc']) === '') {
            $this->aviso('El cliente no tiene RFC capturado. El CFDI 4.0 lo exige.', 'error');
        }
        if (trim((string) $r['cp']) === '') {
            $this->aviso('El cliente no tiene código postal fiscal. El CFDI 4.0 lo exige.', 'error');
        }

        // ── Importes ────────────────────────────────────────────────────
        // `ventas.iva_modo` dice si `total` ya trae IVA (default 'incluido').
        // El despeje se hace sobre el total para que el CFDI cuadre exacto
        // con lo que se le cobró al cliente — no con la columna `subtotal`,
        // que puede no incluir los descuentos aplicados.
        $total = (float) $venta['total'];
        if (($venta['iva_modo'] ?? 'incluido') === 'incluido') {
            $subtotal = round($total / 1.16, 2);
        } else {
            $subtotal = (float) $venta['subtotal'];
        }
        $iva = round($total - $subtotal, 2);

        $cpReceptor = trim((string) $r['cp']);

        // Facturapi 4.0 solo exige `zip` en address, pero los demás campos
        // ayudan al PAC a validar contra el padrón del SAT.
        $address = ['zip' => $cpReceptor, 'country' => 'MEX'];
        if (!empty($r['calle_numero']))     $address['street']       = trim($r['calle_numero']);
        if (!empty($r['numero_interior']))  $address['exterior']     = trim($r['numero_interior']);
        if (!empty($r['colonia']))          $address['neighborhood'] = trim($r['colonia']);
        if (!empty($r['ciudad']))           $address['city']         = trim($r['ciudad']);
        if (!empty($r['estado_direccion'])) $address['state']        = trim($r['estado_direccion']);

        $payload = [
            'customer' => [
                'legal_name' => strtoupper(trim((string) ($r['razon_social'] ?: $venta['cliente']))),
                'tax_id'     => strtoupper(trim((string) $r['rfc'])),
                'tax_system' => trim((string) ($r['regimen'] ?: '616')),
                'address'    => $address,
                'email'      => !empty($r['email']) ? $r['email'] : null,
            ],
            'items' => [[
                'quantity' => 1,
                'product'  => [
                    'description' => trim((string) ($venta['descripcion'] ?: 'Servicios prestados')),
                    'product_key' => '84111506',   // Servicios de facturación
                    'price'       => $subtotal,
                    'taxes'       => [['type' => 'IVA', 'rate' => 0.16]],
                ],
            ]],
            'use'            => trim((string) ($r['uso_cfdi'] ?: 'G03')),
            'payment_form'   => $this->formaPagoSat((string) ($venta['metodo_pago'] ?? '')),
            'payment_method' => 'PUE',  // el candado de saldo garantiza cobro completo
        ];

        // ── Envío al PAC ────────────────────────────────────────────────
        $cli = new Facturapi($clave);
        $res = $cli->crearFactura($payload);

        if ($res['error'] !== '') {
            error_log("Facturapi cURL (venta {$id}): " . $res['error']);
            $this->aviso('Error de red al contactar al PAC. Intenta de nuevo.', 'error');
        }
        if ($res['http'] < 200 || $res['http'] >= 300 || empty($res['json']['id'])) {
            $msg = $res['json']['message'] ?? 'Error desconocido al timbrar.';
            error_log("Facturapi HTTP {$res['http']} (venta {$id}): " . $res['body']);
            $this->aviso('El PAC rechazó el timbrado: ' . $msg, 'error');
        }

        // ── Persistencia ────────────────────────────────────────────────
        $d = $res['json'];
        $repo->marcarFacturada($id, [
            'facturapi_id'           => (string) $d['id'],
            'factura_uuid'           => (string) ($d['uuid'] ?? ''),
            'factura_folio'          => (string) ($d['folio_number'] ?? ''),
            'urlfacturacion'         => (string) ($d['verification_url'] ?? ''),
            'factura_fecha_timbrado' => (string) ($d['created_at'] ?? date('Y-m-d H:i:s')),
            'factura_subtotal'       => $subtotal,
            'factura_iva'            => $iva,
            'factura_total'          => $total,
            'factura_rfc_receptor'   => $payload['customer']['tax_id'],
            'factura_razon_social'   => $payload['customer']['legal_name'],
            'factura_uso_cfdi'       => $payload['use'],
            'factura_metodo_pago'    => 'PUE',
            'factura_forma_pago'     => $payload['payment_form'],
            'factura_cp_receptor'    => $cpReceptor,
            'factura_email_receptor' => $payload['customer']['email'] ?? null,
        ]);

        // Guardar de vuelta los fiscales capturados, para pre-rellenar la
        // próxima. No es crítico: si falla, el timbrado ya ocurrió.
        try {
            $repo->guardarFiscalesCliente((int) $venta['cliente_id'], [
                'rfc'          => $payload['customer']['tax_id'],
                'razon_social' => $payload['customer']['legal_name'],
                'regimen'      => $payload['customer']['tax_system'],
                'uso_cfdi'     => $payload['use'],
                'cp'           => $cpReceptor,
            ]);
        } catch (\Throwable $e) {
            error_log("No se pudieron guardar fiscales del cliente "
                    . "{$venta['cliente_id']}: " . $e->getMessage());
        }

        $this->aviso('Venta facturada. UUID: ' . ($d['uuid'] ?? 'pendiente'), 'ok');
    }

    /**
     * Catálogo c_FormaPago del SAT, desde el enum `ventas.metodo_pago`.
     *
     *   efectivo       -> 01  Efectivo
     *   transferencia  -> 03  Transferencia electrónica de fondos
     *   tarjeta        -> 04  Tarjeta de crédito
     *   paypal         -> 03  (el abono llega como transferencia; no hay
     *                          código SAT específico para PayPal)
     *
     * El enum no distingue crédito (04) de débito (28). Si algún día el
     * negocio necesita separarlas, hay que agregar el dato al cobro y
     * leerlo aquí.
     */
    private function formaPagoSat(string $metodoVenta): string
    {
        return match (strtolower(trim($metodoVenta))) {
            'efectivo'      => '01',
            'transferencia' => '03',
            'tarjeta'       => '04',
            'paypal'        => '03',
            default         => '03',
        };
    }

    private function aviso(string $texto, string $tipo): void
    {
        $_SESSION['lf_aviso'] = ['texto' => $texto, 'tipo' => $tipo];
        header('Location: /facturacion');
        exit;
    }
}