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
        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(
                'Este servidor tiene allow_url_fopen apagado y SOAP lo necesita para leer el WSDL.');
        }

        $seg  = max(5, (int)($this->cfg['timeout'] ?? 30));
        $usr  = (string)($this->cfg['usuario'] ?? '');
        $pwd  = (string)($this->cfg['clave'] ?? '');

        // EL WSDL VA PROTEGIDO CON AUTENTICACIÓN BÁSICA.
        //
        // Sin esta cabecera, PHP descarga una página de error en vez del
        // XML y SoapClient falla con "failed to load external entity",
        // que no dice nada de lo que de verdad pasó. El sistema anterior
        // sí la mandaba; yo la había omitido.
        $http = ['timeout' => $seg, 'user_agent' => 'LibertyFin/1.0'];
        if ($usr !== '') {
            $http['header'] = "Authorization: Basic " . base64_encode($usr . ':' . $pwd);
        }

        $ctx = stream_context_create([
            // Para una dirección IP no hay certificado válido posible: se
            // emiten para nombres de dominio. Si el endpoint es https a una
            // IP, verificar siempre falla, y APAGAR la verificación no lo
            // arregla: lo esconde. Por eso esto se queda en true y el
            // aviso dice que hay que pedir un dominio.
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true,
                      'allow_self_signed' => false],
            'http' => $http,
        ]);

        $opciones = [
            'trace'              => true,
            'exceptions'         => true,
            // Sin caché mientras se está probando: un WSDL mal descargado
            // queda guardado y sigue fallando aunque ya se arregle.
            'cache_wsdl'         => !empty($this->cfg['sandbox']) ? WSDL_CACHE_NONE : WSDL_CACHE_DISK,
            'connection_timeout' => $seg,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'encoding'           => 'UTF-8',
            'stream_context'     => $ctx,
        ];
        // Y también como credenciales del propio cliente, para las
        // llamadas que van después de leer el WSDL.
        if ($usr !== '') { $opciones['login'] = $usr; $opciones['password'] = $pwd; }

        try {
            return new \SoapClient($wsdl, $opciones);
        } catch (\SoapFault $e) {
            throw new \RuntimeException(self::explicar($wsdl, $e->getMessage()));
        }
    }

    /**
     * Traduce el error de SOAP a algo accionable.
     *
     * "failed to load external entity" significa que PHP no pudo bajar el
     * XML, y las causas son pocas y conocidas. Decirlas ahorra una tarde.
     */
    private static function explicar($wsdl, $mensaje)
    {
        $esHttps = strncasecmp($wsdl, 'https://', 8) === 0;
        $esIp    = (bool)preg_match('~^https?://\d{1,3}(\.\d{1,3}){3}~', $wsdl);

        if (stripos($mensaje, 'external entity') !== false
            || stripos($mensaje, "Couldn't load from") !== false) {
            if ($esHttps && $esIp) {
                return 'No se pudo leer el WSDL en ' . $wsdl . '. Está apuntando a una '
                     . 'dirección IP por HTTPS, y eso casi nunca funciona: los certificados '
                     . 'se emiten para nombres de dominio, no para IPs. Cámbialo a http:// '
                     . 'y pídele a Emida un dominio con certificado.';
            }
            return 'No se pudo leer el WSDL en ' . $wsdl . '. Puede ser que el servidor de '
                 . 'Emida no responda desde aquí, que las credenciales no sirvan para '
                 . 'descargarlo, o que el hosting bloquee la salida a esa dirección. '
                 . 'Comprueba desde el servidor con: curl -u USUARIO:CLAVE ' . $wsdl;
        }
        return 'No se pudo conectar con Emida: ' . $mensaje;
    }

    /**
     * Prueba la conexión y dice qué falla, paso por paso.
     * Sirve para no adivinar cuando una recarga no sale.
     */
    public function probar()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        $r = [];

        // Un renglón informativo NO detiene la prueba. Antes "va cifrada"
        // contaba como fallo, así que con HTTP —que es lo normal aquí—
        // la revisión se cortaba justo antes de lo único que importa:
        // si el WSDL se lee y a dónde apunta.
        $agrega = function (&$r, $que, $ok, $detalle, $bloquea = true) {
            $r[] = ['que' => $que, 'ok' => $ok, 'detalle' => $detalle, 'bloquea' => $bloquea];
        };

        // La extensión SOAP hace falta para LLAMAR, no para leer el WSDL.
        // Si no está, el resto del diagnóstico sigue siendo útil: dice a
        // dónde apunta y qué ofrece, que es lo que hay que averiguar.
        $agrega($r, 'Extensión SOAP de PHP', class_exists('SoapClient'),
            class_exists('SoapClient')
                ? 'disponible'
                : 'falta: sin ella se puede diagnosticar pero no recargar', false);
        $agrega($r, 'allow_url_fopen', (bool)ini_get('allow_url_fopen'),
            ini_get('allow_url_fopen') ? 'encendido' : 'apagado: SOAP no puede leer el WSDL');
        $agrega($r, 'Dirección del WSDL', $wsdl !== '', $wsdl ?: 'sin configurar');
        $agrega($r, 'Credenciales', trim((string)($this->cfg['usuario'] ?? '')) !== ''
                                 && trim((string)($this->cfg['merchant_id'] ?? '')) !== '',
            'usuario y comercio');
        $agrega($r, 'Va cifrada', $this->cifrado(),
            $this->cifrado() ? 'HTTPS' : 'HTTP: las credenciales viajan en claro', false);

        foreach ($r as $x) if ($x['bloquea'] && !$x['ok']) return $r;

        // 1 · ¿Se baja el XML?
        $d = $this->bajarWsdl();
        $agrega($r, 'Descarga del WSDL', $d['ok'],
            $d['ok'] ? ($d['bytes'] . ' bytes de XML') : $d['error']);
        if (!$d['ok']) return $r;

        // 2 · ¿A dónde manda las llamadas? Esto es lo que suele fallar:
        // el WSDL se lee y el endpoint que declara es otro.
        $ep = $this->endpoint();
        $mismoHost = $ep && parse_url($ep, PHP_URL_HOST) === parse_url($wsdl, PHP_URL_HOST);
        $agrega($r, 'A dónde manda las llamadas', (bool)$ep,
            $ep ? ($ep . ($mismoHost ? '' : '  ← host distinto al del WSDL'))
                : 'el WSDL no declara <soap:address>');

        // 3 · ¿Responde ese endpoint?
        if ($ep) {
            $abre = @fsockopen(
                (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 'ssl://' : '') . parse_url($ep, PHP_URL_HOST),
                parse_url($ep, PHP_URL_PORT) ?: (parse_url($ep, PHP_URL_SCHEME) === 'https' ? 443 : 80),
                $errno, $errstr, 6);
            if ($abre) { fclose($abre); $agrega($r, 'El endpoint responde', true, 'contesta'); }
            else {
                $agrega($r, 'El endpoint responde', false,
                    ($errstr ?: 'no contesta') . '. Por eso sale "Could not connect to host": '
                    . 'el WSDL se lee pero las llamadas van a otra dirección que no abre.');
            }
        }

        // 4 · Qué operaciones ofrece, y cuál sirve para qué.
        $ops = $this->operacionesDelXml();
        if ($ops['ok']) {
            $n = $ops['operaciones'];
            $agrega($r, 'Operaciones que ofrece', count($n) > 0,
                $n ? implode(', ', $n) : 'ninguna');
            foreach ([['saldo', ['Balance','Saldo','Funds']],
                      ['validar número', ['Lookup','Validate','Inquiry','Consulta']],
                      ['recargar', ['Submit','Topup','Recharge','Sale','Payment']]] as $par) {
                $hay = null;
                foreach ($n as $nom) { foreach ($par[1] as $pi)
                    if (stripos($nom, $pi) !== false) { $hay = $nom; break 2; } }
                $agrega($r, 'Operación de ' . $par[0], (bool)$hay,
                    $hay ?: 'no se encontró: dime cuál de las de arriba es', false);
            }
        } else {
            $agrega($r, 'Operaciones que ofrece', false, $ops['error']);
        }
        return $r;
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

    /**
     * Descarga el WSDL como texto, con su autenticación.
     *
     * Se hace aparte de SoapClient a propósito: SoapClient falla con un
     * mensaje único ("Could not connect to host") sin importar si el
     * problema fue la descarga, el XML o el endpoint. Bajándolo a mano
     * se puede decir exactamente cuál de los tres.
     */
    public function bajarWsdl()
    {
        $wsdl = trim((string)($this->cfg['wsdl'] ?? ''));
        if ($wsdl === '') return ['ok' => false, 'error' => 'Sin dirección de WSDL'];

        $http = ['timeout' => max(5, (int)($this->cfg['timeout'] ?? 30)),
                 'user_agent' => 'LibertyFin/1.0', 'ignore_errors' => true];
        $usr = (string)($this->cfg['usuario'] ?? '');
        if ($usr !== '') {
            $http['header'] = 'Authorization: Basic '
                . base64_encode($usr . ':' . (string)($this->cfg['clave'] ?? ''));
        }
        $ctx = stream_context_create(['http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $xml = @file_get_contents($wsdl, false, $ctx);
        if ($xml === false) {
            $e = error_get_last();
            return ['ok' => false, 'error' => 'No se pudo descargar: '
                . ($e['message'] ?? 'sin detalle')];
        }
        if (stripos($xml, '<definitions') === false && stripos($xml, ':definitions') === false) {
            return ['ok' => false,
                    'error' => 'Lo que devolvió no es un WSDL. Primeros caracteres: '
                             . mb_substr(strip_tags($xml), 0, 120)];
        }
        return ['ok' => true, 'xml' => $xml, 'bytes' => strlen($xml)];
    }

    /**
     * A qué dirección se mandan las llamadas.
     *
     * NO es la misma que la del WSDL. El XML declara su propio endpoint
     * en <soap:address>, y si ahí dice https o una IP distinta, las
     * llamadas van a otro lado aunque el WSDL se haya leído bien. Ese es
     * justo el caso de "se descargó el WSDL pero no conecta".
     */
    public function endpoint()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return null;
        if (preg_match('/<(?:\w+:)?address\s+location=["\']([^"\']+)["\']/i', $d['xml'], $m)) {
            return $m[1];
        }
        return null;
    }

    /** Las operaciones, leídas del XML. No necesita conectar a nada. */
    public function operacionesDelXml()
    {
        $d = $this->bajarWsdl();
        if (!$d['ok']) return ['ok' => false, 'error' => $d['error']];
        preg_match_all('/<(?:\w+:)?operation\s+name=["\']([^"\']+)["\']/i', $d['xml'], $m);
        $nombres = array_values(array_unique($m[1] ?? []));
        return ['ok' => true, 'operaciones' => $nombres];
    }

    /**
     * Qué operaciones ofrece de verdad este WSDL.
     *
     * Los nombres que yo usaba —GetBalance, LookupTransaction,
     * SubmitTransaction— salieron de la documentación general de Emida,
     * no de ESTE servicio. El WSDL es la única fuente que no se
     * equivoca: se le pregunta y se acabó la adivinanza.
     */
    public function operaciones()
    {
        try {
            $fns = $this->cliente()->__getFunctions();
            $r = [];
            foreach ((array)$fns as $f) {
                // Vienen como "TipoRespuesta Nombre(TipoPeticion $p)"
                if (preg_match('/\s(\w+)\(/', (string)$f, $m)) $r[$m[1]] = (string)$f;
            }
            return ['ok' => true, 'operaciones' => $r];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Los tipos que espera cada operación, para saber qué campos mandar. */
    public function tipos()
    {
        try { return ['ok' => true, 'tipos' => (array)$this->cliente()->__getTypes()]; }
        catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    /**
     * Busca la operación que sirve para algo, por lo que su nombre
     * contiene. Así funciona aunque el proveedor la llame distinto:
     * GetBalance, ObtenerSaldo, BalanceInquiry…
     */
    private function operacionQueSirvePara(array $pistas)
    {
        // Del XML, no de SoapClient: así se puede elegir la operación
        // aunque el endpoint no responda, y el error queda en la llamada
        // y no antes, donde no se entiende.
        $ops = $this->operacionesDelXml();
        if (!$ops['ok']) return null;
        foreach ($ops['operaciones'] as $nombre) {
            foreach ($pistas as $p) {
                if (stripos($nombre, $p) !== false) return $nombre;
            }
        }
        return null;
    }

    /** Los nombres disponibles, para decirlos en un mensaje de error. */
    private function nombresDisponibles()
    {
        $o = $this->operacionesDelXml();
        return $o['ok'] ? implode(', ', $o['operaciones']) : ('no se pudieron leer: ' . $o['error']);
    }

    /** El saldo disponible con el proveedor. */
    public function saldo()
    {
        try {
            $op = $this->operacionQueSirvePara(['Balance', 'Saldo', 'Funds']);
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de saldo. Las que ofrece son: '
                    . $this->nombresDisponibles()
                    . '. Dime cuál corresponde y la conecto.'];
            }
            $r = $this->cliente()->__soapCall($op, [$this->base()]);
            return ['ok' => true, 'saldo' => $r->Balance ?? $r->balance ?? $r->Amount ?? null,
                    'operacion' => $op, 'crudo' => $r];
        } catch (\SoapFault $e) {
            return ['ok' => false, 'error' => self::explicar(
                (string)($this->cfg['wsdl'] ?? ''), $e->getMessage())];
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
            $op = $this->operacionQueSirvePara(['Lookup', 'Validate', 'Inquiry', 'Consulta']);
            if (!$op) {
                // Sin validación previa se puede seguir: es una protección,
                // no un requisito. Pero se avisa, porque cambia el riesgo.
                return ['ok' => true, 'sin_validar' => true];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
                'AccountId' => $numero,
                'ProductId' => $productoId,
            ])]);
            return ['ok' => true, 'operacion' => $op, 'crudo' => $r];
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
            $op = $this->operacionQueSirvePara(['Submit', 'Topup', 'Recharge', 'Sale', 'Payment']);
            if (!$op) {
                return ['ok' => false, 'error' =>
                    'Este WSDL no tiene una operación de recarga. Las que ofrece son: '
                    . $this->nombresDisponibles()
                    . '. Dime cuál es la de vender y la conecto.'];
            }
            $r = $this->cliente()->__soapCall($op, [array_merge($this->base(), [
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
