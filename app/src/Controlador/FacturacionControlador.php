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
    /**
     * Matriz de compatibilidad del catálogo c_UsoCFDI.
     *
     * S01 y CP01 se dejan fuera por diseño: S01 aplica a cualquier
     * régimen, y CP01 va en comprobantes tipo P — que este controlador
     * no emite.
     */
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

    /**
     * Claves SAT por defecto para cada concepto.
     *
     *   01010101 -> "No existe en el catálogo"  (comodín aceptado por el SAT)
     *   H87      -> "Pieza"                     (unidad genérica)
     */
    private const CLAVE_PROD_SERV = '01010101';
    private const CLAVE_UNIDAD    = 'H87';

    /** Tolerancia (en pesos) al comparar totales por redondeo de centavos. */
    private const TOLERANCIA = 0.05;

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
     * La venta tiene que estar cobrada al 100% y tener cliente con RFC/CP.
     * El CFDI se emite como PUE (Pago en Una Exhibición): como el candado
     * de saldo ya exigió cobro completo, no hay rama PPD.
     *
     * Los conceptos salen de `venta_detalles`: una línea del CFDI por cada
     * producto capturado en la venta, con su cantidad, precio y descuento.
     *
     * Sobre el IVA: NO se asume si `venta_detalles.precio_unitario` trae el
     * IVA dentro o no. Se detecta comparando la suma de las líneas contra
     * `ventas.total`:
     *
     *   - suma de líneas ≈ total          -> precios con IVA: se divide /1.16
     *   - suma de líneas × 1.16 ≈ total   -> precios ya son base: divisor 1.0
     *   - ninguna de las dos              -> no se timbra (datos inconsistentes)
     *
     * Además, antes de enviar al PAC se verifica que el CFDI calculado
     * (subtotal × 1.16) coincida con lo que se cobró. Así nunca vuelve a
     * llegar al SAT un comprobante con un total distinto al de la venta.
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

        // Uso CFDI forzado desde la URL: ?uso=D10.
        $usoOverride = strtoupper(trim((string) Peticion::texto('uso', '')));

        // ── Candados ────────────────────────────────────────────────────
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

        // ── Conceptos: una línea por cada producto de la venta ──────────
        $lineas = $repo->lineas($id);
        if (!$lineas) {
            $this->aviso('La venta no tiene productos capturados: nada que facturar.', 'error');
        }

        $total = (float) $venta['total'];

        // Suma de las líneas tal como están guardadas (después de descuentos).
        $sumLineas = 0.0;
        foreach ($lineas as $ln) {
            $sumLineas += ((float) $ln['cantidad'] * (float) $ln['precio_unitario'])
                        - (float) $ln['descuento']
                        - (float) $ln['descuento_promocion'];
        }

        // ¿Las líneas ya traen IVA (suman el total) o son base (×1.16 = total)?
        if (abs($sumLineas - $total) <= self::TOLERANCIA) {
            $divisor = 1.16;   // precios con IVA incluido -> desglosar UNA vez
        } elseif (abs($sumLineas * 1.16 - $total) <= self::TOLERANCIA) {
            $divisor = 1.0;    // precios ya son base sin IVA -> no dividir
        } else {
            $this->aviso(
                'Las líneas de la venta no cuadran con el total '
                . '(líneas: ' . number_format($sumLineas, 2)
                . ', total: ' . number_format($total, 2) . '). No se timbró.',
                'error'
            );
        }

        $items       = [];
        $sumSubtotal = 0.0;

        foreach ($lineas as $ln) {
            $cant = (float) $ln['cantidad'];
            if ($cant <= 0) {
                continue;
            }

            $pu   = round(((float) $ln['precio_unitario']) / $divisor, 6);
            $desc = round((((float) $ln['descuento']) + ((float) $ln['descuento_promocion'])) / $divisor, 6);

            $descripcion = trim((string) ($ln['producto'] ?? ''));
            if ($descripcion === '') {
                $descripcion = 'Producto ' . (string) ($ln['producto_id'] ?? '');
            }

            $item = [
                'quantity' => $cant,
                'product'  => [
                    'description' => $descripcion,
                    'product_key' => self::CLAVE_PROD_SERV,
                    'unit_key'    => self::CLAVE_UNIDAD,
                    'price'       => $pu,
                    // Facturapi asume por defecto tax_included = true y vuelve a
                    // dividir entre 1.16. El precio que mandamos ya es base.
                    'tax_included' => false,
                    'taxes'       => [['type' => 'IVA', 'rate' => 0.16]],
                ],
            ];

            if ($desc > 0.005) {
                $item['discount'] = $desc;
            }
            if (!empty($ln['codigo'])) {
                $item['product']['sku'] = (string) $ln['codigo'];
            }

            $items[]      = $item;
            $sumSubtotal += ($cant * $pu) - $desc;
        }

        if (!$items) {
            $this->aviso('La venta no tiene líneas facturables.', 'error');
        }

        $subtotal   = round($sumSubtotal, 2);
        $iva        = round($total - $subtotal, 2);
        $cpReceptor = trim((string) $r['cp']);

        // ── Candado final: el CFDI debe sumar lo mismo que se cobró ─────
        $totalCfdi = round($subtotal * 1.16, 2);
        if (abs($totalCfdi - $total) > self::TOLERANCIA) {
            $this->aviso(
                'El CFDI calculado (' . number_format($totalCfdi, 2)
                . ') no coincide con el total cobrado (' . number_format($total, 2)
                . '). No se timbró.',
                'error'
            );
        }

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

        // Preferimos el total que devuelve el PAC (lo que realmente se
        // timbró) sobre el calculado localmente, para que la BD refleje el
        // CFDI real. Si difiere de lo cobrado, se deja constancia en el log.
        $totalPac = isset($d['total']) && is_numeric($d['total'])
            ? round((float) $d['total'], 2)
            : $total;
        $alertaTotal = '';
        if (abs($totalPac - $total) > self::TOLERANCIA) {
            error_log("ALERTA: CFDI de la venta {$id} timbrado por $totalPac "
                    . "pero la venta se cobró por $total (UUID " . ($d['uuid'] ?? '?') . ").");
            $alertaTotal = ' ATENCIÓN: el PAC timbró ' . number_format($totalPac, 2)
                         . ' pero la venta se cobró por ' . number_format($total, 2)
                         . '. Cancela y vuelve a timbrar.';
        }
        $subtotalPac = round($totalPac / 1.16, 2);
        $ivaPac      = round($totalPac - $subtotalPac, 2);

        $repo->marcarFacturada($id, [
            'facturapi_id'           => (string) $d['id'],
            'factura_uuid'           => (string) ($d['uuid'] ?? ''),
            'factura_folio'          => (string) ($d['folio_number'] ?? ''),
            'urlfacturacion'         => (string) ($d['verification_url'] ?? ''),
            'factura_fecha_timbrado' => (string) ($d['created_at'] ?? date('Y-m-d H:i:s')),
            'factura_subtotal'       => $subtotalPac,
            'factura_iva'            => $ivaPac,
            'factura_total'          => $totalPac,
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
            . '. UUID: ' . ($d['uuid'] ?? 'pendiente') . $extra . $alertaTotal,
            $alertaTotal === '' ? 'ok' : 'error'
        );
    }

    /**
     * Descarga el XML o PDF de una venta ya facturada.
     */
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

    /**
     * Devuelve un uso CFDI válido para el régimen del receptor.
     */
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

    /**
     * Catálogo c_FormaPago del SAT, desde el enum `ventas.metodo_pago`.
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