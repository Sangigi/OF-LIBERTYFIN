<?php
namespace LibertyFin\Servicio;

/**
 * Ligas de pago: tarjeta, SPEI y efectivo en tiendas.
 *
 * CÓMO FUNCIONA ESTO DE VERDAD
 *
 * Una liga NO es un cobro. Es una instrucción de pago que el cliente
 * todavía tiene que cumplir: entrar y pasar su tarjeta, hacer la
 * transferencia, o ir al OXXO con el código.
 *
 * Por eso la venta se registra con SALDO, no como pagada. El abono se
 * aplica cuando el proveedor confirma, ni un minuto antes. Darla por
 * cobrada al generar la liga haría que el corte de caja mintiera todos
 * los días: diría que entraron $4,000 que nadie ha pagado.
 *
 * Esa es la diferencia con el sistema anterior, que marcaba la venta al
 * generar la referencia.
 *
 * EL PROVEEDOR
 *
 * Pagadetodo, por HTTP con JSON. Los montos van en CENTAVOS y como
 * cadena; la descripción se corta a 40 caracteres; y la referencia son
 * 15 dígitos que tienen que ser únicos por comercio: si se repite, el
 * proveedor devuelve la liga anterior en vez de crear una nueva.
 */
final class LigaPago
{
    /**
     * Qué métodos acepta cada liga.
     *
     * `PaymentTypes` es un código del proveedor, no una lista legible.
     * El sistema anterior usaba "41" en un lado y "401" en otro sin
     * explicar la diferencia. Estos son los valores que se vieron
     * funcionando; si tu asesor da otros, se cambian en
     * config/integraciones.php sin tocar código.
     */
    /**
     * Cada forma de pago es un ENDPOINT distinto, no un codigo.
     *
     * LO QUE ME TENIA EQUIVOCADO
     *
     * `PaymentTypes` no elige el metodo. `41` y `401` son LA MISMA cosa
     * —"Contado"—: 401 es el de Sandbox y 41 el de produccion. El
     * proveedor lo confirmo por correo. Por eso daba igual cual mandara
     * y todo acababa en lo mismo.
     *
     * El metodo lo decide a que servicio se le pega:
     *
     *   GenerarLigaIndi        liga de tarjeta, pago simple
     *   GenerarLigaDomiciliacionIndi   igual, pero deja la tarjeta
     *                          tokenizada para cobros recurrentes
     *   GenerarClabeIndi       la CLABE para SPEI
     *   GenerarReferenciaIndi  la referencia para pagar en tienda
     *
     * Y cada uno pide campos distintos. Mandarle a uno el cuerpo de
     * otro es lo que producia "El Account es obligatorio".
     */
    const METODOS = [
        'tarjeta'  => ['Tarjeta',            'liga',       'Débito o crédito, en línea'],
        'spei'     => ['Transferencia SPEI', 'clabe',      'A una CLABE, se detecta solo'],
        'efectivo' => ['Efectivo en tienda', 'referencia', 'OXXO y tiendas participantes'],
        'todos'    => ['Tarjeta',            'liga',       'Débito o crédito, en línea'],
    ];

    /**
     * Que servicio y que campos necesita cada forma.
     *
     * Las rutas salen de la configuracion para que un cambio del
     * proveedor no obligue a tocar codigo; aqui van las que se han
     * visto funcionando.
     */
    /**
     * El contrato de cada servicio, sacado de la documentacion.
     *
     *   ruta  ·  campos del cuerpo  ·  campos de la respuesta
     *
     * NO SON INTERCAMBIABLES, Y LAS DIFERENCIAS IMPORTAN:
     *
     *   · La LIGA pide `SchoolID`; SPEI y REFERENCIA piden `BusinessID`.
     *     Mandar el equivocado da "El ID de la escuela es obligatorio".
     *
     *   · SPEI NO LLEVA MONTO. Devuelve una CLABE que acepta lo que el
     *     cliente deposite; el monto se cuadra despues contra la venta.
     *     Mandarle `Amount` lo hace rechazar.
     *
     *   · Solo la LIGA lleva `PaymentTypes`. Es el codigo de Contado,
     *     no un selector de metodo: 41 en produccion, 401 en Sandbox.
     */
    const SERVICIOS = [
        'liga' => [
            '/Service/GenerarLigaIndi',
            ['SchoolID','PaymentTypes','Id','Description','Amount','Reference','ExpirationDate'],
            ['url','Url'],
        ],
        'clabe' => [
            '/Service/GenerarClabeIndi',
            ['BusinessID','Description','Account','CustomerEmail','CustomerName','ExpirationDate'],
            ['Clabe','clabe'],
        ],
        'referencia' => [
            '/Service/GenerarReferenciaIndi',
            ['BusinessID','Description','Amount','Reference','CustomerEmail','CustomerName','ExpirationDate'],
            ['Reference','reference'],
        ],
    ];

    /** Donde se consulta si ya pagaron. */
    const CONSULTA = [
        'liga'       => '/Service/ConsultarEstatusLigaIndi',
        'clabe'      => '/Service/ConsultaReferencia',
        'referencia' => '/Service/ConsultaReferencia',
    ];

    /**
     * El codigo de Contado.
     *
     *   401  Sandbox
     *    41  produccion
     *
     * Solo lo usa la liga de tarjeta; los otros dos servicios ni
     * siquiera lo reciben.
     */
    private function contado()
    {
        $propio = trim((string)($this->cfg['payment_types'] ?? ''));
        if ($propio !== '') return $propio;
        return $this->enPruebas() ? '401' : '41';
    }

    /**
     * Cuantos digitos lleva la referencia.
     *
     * Trece en produccion y quince en el Sandbox de pagadetodo.mx. Con
     * el largo equivocado el proveedor contesta el codigo 22, "El
     * formato de la referencia es incorrecto".
     */
    private function largoReferencia()
    {
        $n = (int)($this->cfg['digitos_referencia'] ?? 0);
        if ($n >= 10 && $n <= 20) return $n;
        return $this->enPruebas() ? 15 : 13;
    }

    private $cfg;
    private $ultimoError = '';
    private $ultimaRespuesta = '';

    /** Lo ultimo que contesto el proveedor, para diagnosticar. */
    public function respuesta() { return $this->ultimaRespuesta; }

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    public function error() { return $this->ultimoError; }

    public function listo()
    {
        foreach (['usuario','clave','integracion_id'] as $c) {
            if (trim((string)($this->cfg[$c] ?? '')) === '') return false;
        }
        return true;
    }

    public function enPruebas() { return !empty($this->cfg['sandbox']); }

    /**
     * Genera la liga.
     *
     * @param array $d  monto, descripcion, referencia, metodo, dias
     * @return array|null
     */
    public function generar(array $d)
    {
        $this->ultimoError = '';
        if (!$this->listo()) { $this->ultimoError = 'SPEI sin configurar'; return null; }

        $monto = round((float)($d['monto'] ?? 0), 2);
        if ($monto <= 0) { $this->ultimoError = 'El monto tiene que ser mayor a cero'; return null; }

        $metodo = $d['metodo'] ?? 'tarjeta';
        if (!isset(self::METODOS[$metodo])) $metodo = 'tarjeta';
        $servicio = self::METODOS[$metodo][1];
        list($ruta, $campos, $devuelve) = self::SERVICIOS[$servicio];

        // La direccion: la del servicio si se configuro, si no la base
        // mas su ruta. Asi un cambio del proveedor se arregla en el
        // config y no en el codigo.
        $url = trim((string)($this->cfg['url_' . $servicio] ?? ''));
        if ($url === '') {
            $base = rtrim(trim((string)($this->cfg['host'] ?? $this->cfg['url'] ?? '')), '/');
            if ($base === '') {
                $this->ultimoError = 'Falta `host` o `url_' . $servicio
                                   . '` en config/integraciones.php';
                return null;
            }
            $url = $base . $ruta;
        }

        $pruebas    = $this->enPruebas();
        $referencia = self::referencia($d['referencia'] ?? '', $this->largoReferencia());
        $dias       = max(1, (int)($d['dias'] ?? $this->cfg['dias_vigencia'] ?? 3));
        $cliente    = mb_substr(trim((string)($d['cliente'] ?? '')), 0, 60) ?: 'Publico general';
        $correo     = filter_var($d['correo'] ?? '', FILTER_VALIDATE_EMAIL) ? $d['correo'] : '';

        // Credenciales e identificadores: los pide siempre, los tres.
        $cuerpo = [
            'User'          => $pruebas ? ($this->cfg['usuario_prueba'] ?: $this->cfg['usuario'])
                                        : $this->cfg['usuario'],
            'Password'      => $pruebas ? ($this->cfg['clave_prueba'] ?: $this->cfg['clave'])
                                        : $this->cfg['clave'],
            'IntegrationID' => $this->cfg['integracion_id'],
        ];

        // Y despues, SOLO los campos que ese servicio espera. Mandarle
        // de mas es lo que lo hace rechazar la peticion.
        $posibles = [
            // Los dos identificadores son distintos y cada servicio pide
            // el suyo. Si solo hay uno configurado, se usa para ambos:
            // en muchos convenios son el mismo numero.
            'SchoolID'       => $this->cfg['escuela_id'] ?? $this->cfg['negocio_id'] ?? '',
            'BusinessID'     => $this->cfg['negocio_id'] ?? $this->cfg['escuela_id'] ?? '',
            'PaymentTypes'   => $this->contado(),
            'Id'             => (string)($d['id'] ?? $referencia),
            // El proveedor corta a 40 y si se pasa, rechaza.
            'Description'    => mb_substr((string)($d['descripcion'] ?? 'Pago'), 0, 40),
            // En CENTAVOS y como cadena: mandar "150.00" en vez de
            // "15000" genera un cobro por peso y medio.
            'Amount'         => (string)(int)round($monto * 100),
            'Reference'      => $referencia,
            // `Account` identifica el cobro del lado del proveedor. Va
            // el mismo valor que la referencia: dos identificadores para
            // una sola operacion harian imposible conciliar.
            'Account'        => $referencia,
            'CustomerName'   => $cliente,
            'CustomerEmail'  => $correo,
            'ExpirationDate' => date('Y-m-d', strtotime('+' . $dias . ' days')),
        ];
        foreach ($campos as $c) {
            if (isset($posibles[$c])) $cuerpo[$c] = $posibles[$c];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => max(10, (int)($this->cfg['timeout'] ?? 30)),
            // El certificado SI se verifica. Por aqui viajan montos y
            // referencias de pago.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp    = curl_exec($ch);
        $codigo  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errCurl = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->ultimoError = 'No se pudo contactar al proveedor: ' . $errCurl;
            return null;
        }
        $this->ultimaRespuesta = mb_substr((string)$resp, 0, 800);

        $j = json_decode($resp, true);
        if (!is_array($j)) {
            $this->ultimoError = 'El proveedor respondió algo que no se entiende (HTTP '
                               . $codigo . '): ' . mb_substr(strip_tags($resp), 0, 160);
            return null;
        }

        // Cada servicio devuelve lo suyo, con el nombre que dice su
        // documentacion. Buscar en todos daria falsos positivos: la
        // liga tambien trae un `reference` que NO es un codigo de
        // barras, y mostrarlo como tal manda al cliente al OXXO con un
        // numero que ahi no sirve.
        $valor  = self::campo($j, $devuelve);
        $liga   = $servicio === 'liga'       ? $valor : null;
        $clabe  = $servicio === 'clabe'      ? $valor : null;
        $barras = $servicio === 'referencia' ? $valor : null;

        // La referencia trae ademas la imagen del codigo de barras y el
        // formato de pago, cuando el convenio los genera.
        $imagen  = $servicio === 'referencia' ? self::campo($j, ['BarCode','barCode']) : null;
        $formato = $servicio === 'referencia' ? self::campo($j, ['PayFormat','payFormat']) : null;

        if (!$liga && !$clabe && !$barras) {
            $msg = self::campo($j, ['Message','message','mensaje','Error','error','ErrorMessage']);
            $this->ultimoError = $msg
                ? 'El proveedor rechazó el cobro: ' . $msg
                : 'El proveedor no devolvió ninguna forma de pagar.';
            error_log('[LibertyFin] cobro rechazado · ' . $url
                . ' · enviado: ' . json_encode($cuerpo)
                . ' · recibido: ' . $this->ultimaRespuesta);
            return null;
        }

        // Cada servicio devuelve lo suyo. Se guarda solo eso: si la
        // liga de tarjeta trae una referencia, no es un codigo de
        // barras y mostrarla como tal manda al cliente al OXXO con un
        // numero que no sirve.
        return [
            'liga'       => $liga,
            'clabe'      => $clabe,
            'barras'     => $barras,
            'imagen'     => $imagen,
            'formato'    => $formato,
            'falta'      => '',
            'referencia' => $referencia,
            'vence'      => $cuerpo['ExpirationDate'],
            'metodo'     => $metodo,
            'servicio'   => $servicio,
            'pruebas'    => $pruebas,
            'crudo'      => $j,
        ];
    }


    /**
     * Pregunta si ya pagaron.
     *
     * Se consulta a mano o desde la pantalla. No hay webhook todavía: el
     * proveedor puede avisar, pero recibir ese aviso sin verificar que
     * venga de él sería dejar que cualquiera marque una venta como
     * pagada escribiendo una URL.
     */
    public function estado($referencia)
    {
        $this->ultimoError = '';
        $url = trim((string)($this->cfg['url_estado'] ?? ''));
        if ($url === '') {
            $this->ultimoError = 'Falta `url_estado` en config/integraciones.php para consultar pagos';
            return null;
        }
        $pruebas = $this->enPruebas();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'User'          => $pruebas ? ($this->cfg['usuario_prueba'] ?: $this->cfg['usuario'])
                                            : $this->cfg['usuario'],
                'Password'      => $pruebas ? ($this->cfg['clave_prueba'] ?: $this->cfg['clave'])
                                            : $this->cfg['clave'],
                'IntegrationID' => $this->cfg['integracion_id'],
                'Reference'     => (string)$referencia,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => max(10, (int)($this->cfg['timeout'] ?? 30)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { $this->ultimoError = $err; return null; }
        // Lo que respondio, tal cual, para poder verlo si falla. Sin
        // esto un rechazo del proveedor obliga a adivinar que campo
        // esta mal.
        $this->ultimaRespuesta = mb_substr((string)$resp, 0, 800);

        $j = json_decode($resp, true);
        if (!is_array($j)) { $this->ultimoError = 'Respuesta ilegible del proveedor'; return null; }

        $estado = strtolower((string)self::campo($j, ['Status','status','estado']));
        $pagado = in_array($estado, ['paid','pagado','success','completed','liquidado'], true);

        return ['pagado' => $pagado, 'estado' => $estado ?: 'desconocido',
                'monto' => self::campo($j, ['Amount','amount','monto']),
                'fecha' => self::campo($j, ['PaymentDate','fecha_pago','date']),
                'crudo' => $j];
    }

    /**
     * La referencia: exactamente 15 dígitos.
     *
     * Si se repite, el proveedor devuelve la liga ANTERIOR en vez de
     * crear una nueva. Eso es lo que se quiere al reintentar una venta
     * que no se completó, y un desastre si dos ventas distintas la
     * comparten: la segunda cobraría el monto de la primera.
     */
    public static function referencia($semilla = '', $largo = 13)
    {
        $largo = max(10, min(20, (int)$largo));
        $s = preg_replace('/\D/', '', (string)$semilla);
        if (strlen($s) >= $largo) return substr($s, -$largo);
        return str_pad($s, $largo, (string)random_int(0, 9), STR_PAD_LEFT);
    }

    private static function campo(array $j, array $nombres)
    {
        foreach ($nombres as $n) {
            if (!empty($j[$n])) return $j[$n];
            // A veces viene envuelto en Data / Result
            foreach (['Data','data','Result','result','Response'] as $w) {
                if (isset($j[$w]) && is_array($j[$w]) && !empty($j[$w][$n])) return $j[$w][$n];
            }
        }
        return null;
    }
}
