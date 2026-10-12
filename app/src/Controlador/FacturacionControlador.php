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
 * Recargas con Emida. Y aunque existan, timbrar necesita tres cosas más
 * que el negocio tiene que haber llenado: sus datos fiscales, los del
 * cliente, y que la venta esté cobrada. La pantalla lo dice antes de
 * dejar intentarlo, en vez de fallar contra el SAT con un código que
 * nadie entiende.
 */
final class FacturacionControlador
{
    private const USOS_VALIDOS = [
        'G01' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'G02' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'G03' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I01' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I02' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I03' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I04' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I05' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I06' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I07' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'I08' => ['601','603','606','612','620','621','622','623','624','625','626'],
        'D01' => ['605','606','607','608','611','612','614','615','625'],
        'D02' => ['605','606','607','608','611','612','614','615','625'],
        'D03' => ['605','606','607','608','611','612','614','615','625'],
        'D04' => ['605','606','607','608','611','612','614','615','625'],
        'D05' => ['605','606','607','608','611','612','614','615','625'],
        'D06' => ['605','606','607','608','611','612','614','615','625'],
        'D07' => ['605','606','607','608','611','612','614','615','625'],
        'D08' => ['605','606','607','608','611','612','614','615','625'],
        'D09' => ['605','606','607','608','611','612','614','615','625'],
        'D10' => ['605','606','607','608','611','612','614','615','625'],
    ];

    public function index()
    {
        $db   = Conexion::de($_SESSION['empresa_db']);
        $cfg  = new ConfigRepo($db);
        $repo = new VentaRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

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
     * Cada partida de `venta_detalles` viaja como un concepto independiente.
     * El importe de cada concepto es el TOTAL de esa línea (lo que pagó el
     * cliente, con IVA incluido si el negocio cobra así). Se manda el IVA
     * en 0% para que Facturapi no sume nada encima y el CFDI muestre tal
     * cual el precio unitario y el importe que el usuario quiere.
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

        $usoOverride = strtoupper(trim((string) Peticion::texto('uso', '')));

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
            $this->aviso('Esta venta ya fue facturada.', 'error');
        }

        $docs = (new CuentaRepo($db))->estadoDocumentacion();
        if (($docs['estado'] ?? '') !== 'aprobada') {
            $this->aviso('La documentación del negocio todavía no está aprobada.', 'error');
        }

        // ── Credenciales del PAC ────────────────────────────────────────
        $fac = Integraciones::de('facturapi');
        if (empty($fac['activo'])) {
            $this->aviso('La facturación con Facturapi está apagada. Actívala en '
                       . 'config/integraciones.php (`activo => true`).', 'error');
        }
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            $nombre = $sandbox ? 'llave_prueba (o llave)' : 'llave';
            $this->aviso("No hay credenciales de Facturapi configuradas. "
                       . "Llena `$nombre` en config/integraciones.php.", 'error');
        }

        // ── Datos fiscales del emisor ───────────────────────────────────
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

        // ── Datos del receptor ──────────────────────────────────────────
        $r = $repo->datosFiscalesCliente((int) $venta['cliente_id']);
        if (!$r || trim((string) $r['rfc']) === '') {
            $this->aviso('El cliente no tiene RFC capturado. El CFDI 4.0 lo exige.', 'error');
        }
        if (trim((string) $r['cp']) === '') {
            $this->aviso('El cliente no tiene código postal fiscal. El CFDI 4.0 lo exige.', 'error');
        }

        // ── Uso CFDI compatible con el régimen ──────────────────────────
        [$usoCfdi, $ajusteUso] = $this->usoValido(
            $usoOverride !== '' ? $usoOverride : (string) ($r['uso_cfdi'] ?? ''),
            (string) ($r['regimen'] ?? ''),
            (string) $r['rfc']
        );
        if ($usoOverride !== '' && $usoCfdi !== $usoOverride) {
            $ajusteUso = "el uso $usoOverride no aplica con el régimen "
                       . trim((string) $r['regimen']) . "; se usó $usoCfdi";
        }

        $regimenReceptor = trim((string) ($r['regimen'] ?: '616'));

        // ── Partidas reales de la venta ─────────────────────────────────
        // Cada partida viaja como un concepto. El `price` que mandamos es
        // el TOTAL de la línea (lo que el cliente pagó por ese producto),
        // NO el subtotal sin IVA. Y la tasa de IVA va en 0% para que
        // Facturapi no le sume nada encima.
        $lineas = $repo->lineas($id);
        if (!$lineas) {
            $this->aviso('La venta no tiene partidas capturadas. No hay qué facturar.', 'error');
        }

        $ivaIncluido = (($venta['iva_modo'] ?? 'incluido') === 'incluido');

        $items    = [];
        $subtotal = 0.0;

        foreach ($lineas as $l) {
            $cant = (float) ($l['cantidad'] ?? 1);
            if ($cant <= 0) { $cant = 1; }

            // Importe de la línea: queremos el TOTAL, no el subtotal.
            //   1) Si el schema ya trae `total` por partida, lo usamos.
            //   2) Si no, calculamos: base × 1.16 (si IVA incluido) o base.
            if (isset($l['total']) && (float) $l['total'] > 0) {
                $importeLinea = (float) $l['total'];
            } else {
                if (isset($l['subtotal'])) {
                    $base = (float) $l['subtotal'];
                } else {
                    $pu = 0.0;
                    foreach (['precio_unitario','precio','precio_venta'] as $k) {
                        if (isset($l[$k])) { $pu = (float) $l[$k]; break; }
                    }
                    $base = $pu * $cant;
                }
                $importeLinea = $ivaIncluido ? $base * 1.16 : $base;
            }
            $importeLinea = round($importeLinea, 2);

            // Descripción. Si la cantidad es > 1, la anteponemos al texto
            // porque en el CFDI vamos a mandar quantity = 1.
            $desc = $l['descripcion'] ?? $l['concepto'] ?? $l['producto'] ?? 'Servicios prestados';
            $desc = trim((string) $desc);
            if ($cant > 1) {
                $desc = rtrim(rtrim(number_format($cant, 2, '.', ''), '0'), '.') . ' x ' . $desc;
            }

            $items[] = [
                'quantity' => 1,
                'product'  => [
                    'description' => $desc,
                    'product_key' => (string) ($l['clave_prodserv'] ?? '84111506'),
                    'unit_key'    => (string) ($l['clave_unidad']  ?? 'E48'),
                    'price'       => $importeLinea,
                    // IVA 0%: el importe YA trae el impuesto incluido.
                    // Si Facturapi sumara 16% encima, el CFDI no cuadraría
                    // con lo que el cliente pagó.
                    'taxes'       => [['type' => 'IVA', 'rate' => 0]],
                ],
            ];
            $subtotal += $importeLinea;
        }

        // Totales: como todo viene con IVA incluido y la tasa es 0%,
        // el subtotal y el total del CFDI son el mismo número.
        $subtotal = round($subtotal, 2);
        $iva      = 0.0;
        $total    = $subtotal;

        $cpReceptor = trim((string) $r['cp']);

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
                'tax_system' => $regimenReceptor,
                'address'    => $address,
                'email'      => !empty($r['email']) ? $r['email'] : null,
            ],
            'items'          => $items,
            'use'            => $usoCfdi,
            'payment_form'   => $this->formaPagoSat((string) ($venta['metodo_pago'] ?? '')),
            'payment_method' => 'PUE',
        ];

        // ── Envío al PAC ────────────────────────────────────────────────
        $cli = new Facturapi($clave, (string) ($fac['organizacion'] ?? ''));
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
            'factura_uso_cfdi'       => $usoCfdi,
            'factura_metodo_pago'    => 'PUE',
            'factura_forma_pago'     => $payload['payment_form'],
            'factura_cp_receptor'    => $cpReceptor,
            'factura_email_receptor' => $payload['customer']['email'] ?? null,
        ]);

        try {
            $repo->guardarFiscalesCliente((int) $venta['cliente_id'], [
                'rfc'          => $payload['customer']['tax_id'],
                'razon_social' => $payload['customer']['legal_name'],
                'regimen'      => $payload['customer']['tax_system'],
                'uso_cfdi'     => $usoCfdi,
                'cp'           => $cpReceptor,
            ]);
        } catch (\Throwable $e) {
            error_log("No se pudieron guardar fiscales del cliente "
                    . "{$venta['cliente_id']}: " . $e->getMessage());
        }

        $extra = $ajusteUso !== null
            ? " Se ajustó el uso CFDI ($ajusteUso). Corrige el régimen/uso del cliente."
            : '';
        $this->aviso(
            'Venta facturada' . ($sandbox ? ' (PRUEBAS, sin validez fiscal)' : '')
            . '. UUID: ' . ($d['uuid'] ?? 'pendiente') . $extra,
            'ok'
        );
    }

    public function descargarCfdi($id)
    {
        $id = (int) $id;

        $db   = Conexion::de($_SESSION['empresa_db']);
        $repo = new VentaRepo($db);

        $venta = $repo->detalle($id);
        if (!$venta) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Venta no encontrada.');
        }
        if (empty($venta['factura_uuid'])) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Esta venta no está facturada.');
        }
        $facturapiId = trim((string) ($venta['facturapi_receipt_id'] ?? ''));
        if ($facturapiId === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('La venta está marcada como facturada pero no tiene ID del PAC guardado.');
        }

        $tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'pdf')));
        if (!in_array($tipo, ['xml', 'pdf'], true)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('El tipo debe ser xml o pdf.');
        }

        $fac = Integraciones::de('facturapi');
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Facturapi sin credenciales configuradas.');
        }

        $cli = new Facturapi($clave, (string) ($fac['organizacion'] ?? ''));
        $res = $cli->descargarFactura($facturapiId, $tipo);

        if ($res['error'] !== '') {
            error_log("Facturapi cURL descarga (venta {$id}, {$tipo}): " . $res['error']);
            http_response_code(502);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Error de red al contactar al PAC.');
        }
        if ($res['http'] !== 200) {
            error_log("Facturapi HTTP {$res['http']} descarga (venta {$id}, {$tipo}): "
                    . substr($res['body'], 0, 500));
            http_response_code(
                $res['http'] >= 400 && $res['http'] < 600 ? $res['http'] : 502
            );
            header('Content-Type: text/plain; charset=UTF-8');
            $msg = $res['json']['message']
                 ?? "El PAC rechazó la descarga (HTTP {$res['http']}).";
            exit($msg);
        }

        $mime     = $tipo === 'pdf' ? 'application/pdf' : 'application/xml; charset=UTF-8';
        $filename = 'cfdi-' . $venta['factura_uuid'] . '.' . $tipo;

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($res['body']));
        header('Cache-Control: no-cache, must-revalidate');
        echo $res['body'];
        exit;
    }

    private function usoValido(string $uso, string $regimen, string $rfc): array
    {
        $uso     = strtoupper(trim($uso));
        $regimen = trim($regimen);
        $rfc     = strtoupper(trim($rfc));

        if ($rfc === 'XAXX010101000' || $rfc === 'XEXX010101000' || $regimen === '616') {
            $motivo = ($uso !== '' && $uso !== 'S01') ? 'régimen 616 exige S01' : null;
            return ['S01', $motivo];
        }

        if ($regimen === '605') {
            if (preg_match('/^D(0[1-9]|10)$/', $uso)) {
                return [$uso, null];
            }
            $motivo = ($uso === '' || $uso === 'S01')
                ? null
                : "régimen 605 solo admite D01-D10 o S01";
            return ['S01', $motivo];
        }

        if ($uso === '') {
            return ['G03', 'el cliente no tenía uso CFDI capturado'];
        }

        $validos = self::USOS_VALIDOS[$uso] ?? null;
        if ($validos === null) {
            return in_array($regimen, self::USOS_VALIDOS['G03'], true)
                ? ['G03', "el uso $uso no aplica a este tipo de comprobante"]
                : ['S01', "el uso $uso no es válido para el régimen $regimen"];
        }
        if (!in_array($regimen, $validos, true)) {
            $alt = in_array($regimen, self::USOS_VALIDOS['G03'], true) ? 'G03' : 'S01';
            return [$alt, "el uso $uso no es válido con el régimen $regimen"];
        }
        return [$uso, null];
    }

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