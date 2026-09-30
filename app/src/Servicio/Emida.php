<?php
namespace LibertyFin\Servicio;

/**
 * Recargas telefónicas · Emida.
 *
 * Es SOAP sobre un WSDL, no REST. Los datos y los códigos de respuesta
 * salen del archivo de configuración del sistema anterior, que sí estaba
 * probado contra la cuenta real.
 *
 * DOS COSAS QUE NO SE COPIAN DEL SISTEMA ANTERIOR:
 *
 *  · El WSDL apunta a `http://104.248.179.142` SIN cifrar. Por ahí viajan
 *    el usuario, la clave y el número del cliente en claro. Aquí se avisa
 *    en pantalla y se deja pasar solo porque es el único endpoint que hay;
 *    en cuanto Emida dé uno con HTTPS, se cambia en la configuración.
 *
 *  · `verify_peer => false`. Eso convierte HTTPS en decoración: cualquiera
 *    en medio puede presentar su propio certificado. Aquí se verifica, y
 *    si el certificado falla la recarga no sale.
 */
final class Emida
{
    /** Los códigos que el proveedor documenta. */
    const ERRORES = [
        '16'   => 'El número no existe o no admite recargas',
        '51'   => 'Monto no válido para esa compañía',
        '12'   => 'Terminal o comercio no válidos',
        '294'  => 'Ya se hizo esa misma recarga hace menos de 5 minutos',
        '504'  => 'El proveedor no respondió a tiempo',
        '2518' => 'Error de comunicación con la compañía telefónica',
    ];

    private $cfg;

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    /** ¿El endpoint va cifrado? Se muestra en pantalla. */
    public function cifrado()
    {
        return strncasecmp((string)($this->cfg['wsdl'] ?? ''), 'https://', 8) === 0;
    }

    private function cliente()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') {
            throw new \RuntimeException('Falta la dirección del WSDL en config/integraciones.php');
        }
        if (!class_exists('SoapClient')) {
            throw new \RuntimeException(
                'Este servidor no tiene la extensión SOAP de PHP. Actívala para usar recargas.');
        }

        $seg = max(5, (int)($this->cfg['timeout'] ?? 30));
        $ctx = stream_context_create([
            'ssl' => [
                // El certificado SÍ se verifica, al contrario del sistema
                // anterior. Con verify_peer en false, HTTPS no protege nada.
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
            'http' => ['timeout' => $seg, 'user_agent' => 'LibertyFin/1.0'],
        ]);

        return new \SoapClient($wsdl, [
            'trace'              => true,
            'exceptions'         => true,
            'cache_wsdl'         => WSDL_CACHE_DISK,
            'connection_timeout' => $seg,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'encoding'           => 'UTF-8',
            'stream_context'     => $ctx,
        ]);
    }

    /** Los campos que toda petición lleva. */
    private function base()
    {
        return [
            'UserId'         => $this->cfg['usuario'] ?? '',
            'Password'       => $this->cfg['clave'] ?? '',
            'MerchantId'     => $this->cfg['merchant_id'] ?? '',
            'ClerkPassword'  => $this->cfg['clerk_password'] ?? ($this->cfg['clave'] ?? ''),
        ];
    }

    /** El saldo disponible con el proveedor. */
    public function saldo()
    {
        try {
            $r = $this->cliente()->__soapCall('GetBalance', [$this->base()]);
            return ['ok' => true, 'saldo' => $r->Balance ?? $r->balance ?? null, 'crudo' => $r];
        } catch (\SoapFault $e) {
            return ['ok' => false, 'error' => 'El proveedor respondió con un error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Valida un número antes de cobrarle al cliente.
     *
     * Se consulta ANTES de aceptar el dinero. Si se cobrara primero y el
     * número resultara inválido, habría que devolver efectivo de una caja
     * que ya cuadró.
     */
    public function validar($numero, $productoId)
    {
        try {
            $r = $this->cliente()->__soapCall('LookupTransaction', [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
            ])]);
            return ['ok' => true, 'crudo' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ejecuta la recarga.
     *
     * `SalesId` es un identificador propio que el proveedor usa para
     * detectar duplicados: si la red se cae después de enviar y se
     * reintenta con el mismo, Emida devuelve 294 en vez de recargar dos
     * veces. Por eso lo recibe y no lo genera él.
     */
    public function recargar($numero, $productoId, $monto, $salesId)
    {
        if (!$this->cifrado()) {
            error_log('[LibertyFin] Emida sobre HTTP sin cifrar: ' . ($this->cfg['wsdl'] ?? ''));
        }
        try {
            $r = $this->cliente()->__soapCall('SubmitTransaction', [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
                'Amount'    => number_format((float)$monto, 2, '.', ''),
                'SalesId'   => $salesId,
            ])]);

            $resp = (string)($r->ResponseCode ?? '');
            $h2h  = (string)($r->H2HResultCode ?? '');

            if (self::exitosa($resp, $h2h)) {
                return ['ok' => true, 'duplicada' => $h2h === '294',
                        'folio' => $r->CarrierControlNo ?? $r->TransactionId ?? null,
                        'crudo' => $r];
            }
            return ['ok' => false, 'error' => self::mensaje($resp, $h2h),
                    'codigo' => $resp, 'h2h' => $h2h];

        } catch (\SoapFault $e) {
            // Un timeout NO es un fallo: la recarga pudo haber salido. Se
            // avisa de otra forma para que nadie la reintente a ciegas.
            $esTimeout = stripos($e->getMessage(), 'timed out') !== false
                      || stripos($e->getMessage(), 'timeout') !== false;
            return ['ok' => false, 'incierta' => $esTimeout,
                    'error' => $esTimeout
                        ? 'El proveedor no respondió a tiempo. La recarga PUDO haber salido: '
                        . 'consulta el saldo antes de reintentar.'
                        : 'El proveedor respondió con un error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Según el proveedor: ResponseCode "00" y H2H "0".
     * El 294 también cuenta como éxito: es una recarga que ya se hizo,
     * no una que falló.
     */
    public static function exitosa($responseCode, $h2h)
    {
        return $responseCode === '00' && ($h2h === '0' || $h2h === '294');
    }

    public static function mensaje($responseCode, $h2h)
    {
        if (isset(self::ERRORES[$h2h]))          return self::ERRORES[$h2h];
        if (isset(self::ERRORES[$responseCode])) return self::ERRORES[$responseCode];
        return 'El proveedor rechazó la recarga (código ' . $responseCode . '/' . $h2h . ')';
    }
}
