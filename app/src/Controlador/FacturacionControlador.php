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
    /**
     * Matriz de compatibilidad del catálogo c_UsoCFDI.
     *
     * El SAT no acepta cualquier pareja régimen+uso: G03 no existe para
     * 616, D01-D10 solo son para físicas, y así. Validarlo aquí permite
     * decirle al usuario "el cliente está mal capturado" en vez de dejar
     * que el PAC devuelva un rechazo sobre el que nadie sabe qué corregir.
     *
     * S01 y CP01 se dejan fuera de la validación por diseño: S01 (sin
     * efectos fiscales) aplica a cualquier régimen, y CP01 va en
     * comprobantes tipo P — que este controlador no emite.
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
        // El archivo usa `llave` (producción) y `llave_prueba` (sandbox),
        // más un apagador maestro `activo`. Si está apagado, no es un error
        // de configuración del cliente — es que la integración no se ha
        // activado, así que el mensaje lo dice tal cual.
        $fac = Integraciones::de('facturapi');
        if (empty($fac['activo'])) {
            $this->aviso('La facturación con Facturapi está apagada. Actívala en '
                       . 'config/integraciones.php (`activo => true`).', 'error');
        }
        $sandbox = !empty($fac['sandbox']);
        $clave = $sandbox
            ? trim((string) ($fac['llave_prueba'] ?? ''))
            : trim((string) ($fac['llave'] ?? ''));
        // Si el archivo solo tiene la llave de producción cargada y sandbox
        // está prendido, se cae a esa — así no hay que llenar dos campos
        // cuando el dueño solo tiene una cuenta de pruebas.
        if ($clave === '' && $sandbox) {
            $clave = trim((string) ($fac['llave'] ?? ''));
        }
        if ($clave === '') {
            $nombre = $sandbox ? 'llave_prueba (o llave)' : 'llave';
            $this->aviso("No hay credenciales de Facturapi configuradas. "
                       . "Llena `$nombre` en config/integraciones.php.", 'error');
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

        // ── Uso CFDI compatible con el régimen ──────────────────────────
        // Se resuelve ANTES de armar el payload: si el cliente trae una
        // pareja inválida (lo más común: G03 con régimen 616), el PAC
        // rechaza con "La clave del campo UsoCFDI debe corresponder con el
        // tipo de persona...", que no le dice nada al usuario. Aquí se
        // sustituye por un valor válido y se reporta en el aviso final.
        [$usoCfdi, $ajusteUso] = $this->usoValido(
            (string) ($r['uso_cfdi'] ?? ''),
            (string) ($r['regimen'] ?? ''),
            (string) $r['rfc']
        );

        $regimenReceptor = trim((string) ($r['regimen'] ?: '616'));

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
                'tax_system' => $regimenReceptor,
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
            'use'            => $usoCfdi,
            'payment_form'   => $this->formaPagoSat((string) ($venta['metodo_pago'] ?? '')),
            'payment_method' => 'PUE',  // el candado de saldo garantiza cobro completo
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

        // Guardar de vuelta los fiscales capturados, para pre-rellenar la
        // próxima. No es crítico: si falla, el timbrado ya ocurrió. Se
        // guarda el uso que de verdad viajó al PAC, no el que traía el
        // cliente — así la próxima vez ya sale corregido.
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

        // Si el uso CFDI se tuvo que ajustar, se avisa para que el dato del
        // cliente se corrija en su ficha — pero el timbrado ya se hizo.
        $extra = $ajusteUso !== null
            ? " Se ajustó el uso CFDI ($ajusteUso). Corrige el régimen/uso del cliente."
            : '';
        $this->aviso(
            'Venta facturada' . ($sandbox ? ' (PRUEBAS, sin validez fiscal)' : '')
            . '. UUID: ' . ($d['uuid'] ?? 'pendiente') . $extra,
            'ok'
        );
    }

    /**
     * Devuelve un uso CFDI válido para el régimen del receptor.
     *
     * No confía en el dato capturado: si la pareja régimen+uso no está en
     * el catálogo, cae a un valor que sí lo está en vez de mandarla al SAT
     * y comerse el rechazo. Devuelve [uso_final, motivo_del_ajuste_o_null].
     *
     *   · 616 (sin obligaciones fiscales, típico de público en general)
     *     solo admite S01. Cualquier otro uso revienta.
     *   · 605 (sueldos) no admite G03 — solo D01-D10.
     *   · D01-D10 son exclusivos de persona física.
     */
    private function usoValido(string $uso, string $regimen, string $rfc): array
    {
        $uso     = strtoupper(trim($uso));
        $regimen = trim($regimen);
        $rfc     = strtoupper(trim($rfc));

        // RFC genérico -> régimen 616 -> S01, sin discusión. El SAT no
        // permite otra cosa para el público en general.
        if ($rfc === 'XAXX010101000' || $rfc === 'XEXX010101000' || $regimen === '616') {
            $motivo = ($uso !== '' && $uso !== 'S01') ? 'régimen 616 exige S01' : null;
            return ['S01', $motivo];
        }

        // Sin uso capturado: el default seguro es G03, que aplica a
        // cualquier régimen de los que sí lo admiten.
        if ($uso === '') {
            return ['G03', 'el cliente no tenía uso CFDI capturado'];
        }

        $validos = self::USOS_VALIDOS[$uso] ?? null;
        if ($validos === null) {
            // Uso desconocido o reservado (CP01 en tipo I, por ejemplo).
            // No lo adivinamos: cae a G03 si el régimen lo admite.
            return in_array($regimen, self::USOS_VALIDOS['G03'], true)
                ? ['G03', "el uso $uso no aplica a este tipo de comprobante"]
                : ['S01', "el uso $uso no es válido para el régimen $regimen"];
        }
        if (!in_array($regimen, $validos, true)) {
            // La pareja no existe. Para 605 el uso correcto es D01-D10;
            // para el resto, G03 si se puede; si no, S01.
            $alt = ($regimen === '605' && str_starts_with($uso, 'G'))
                ? 'D01'
                : (in_array($regimen, self::USOS_VALIDOS['G03'], true) ? 'G03' : 'S01');
            return [$alt, "el uso $uso no es válido con el régimen $regimen"];
        }
        return [$uso, null];
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