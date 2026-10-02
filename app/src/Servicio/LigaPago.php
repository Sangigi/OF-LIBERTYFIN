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
     * Que metodos acepta cada liga.
     *
     * SOLO SE CONOCEN DOS CODIGOS, Y HAY QUE DECIRLO
     *
     * En todo el sistema anterior aparecen unicamente `41` y `401`. Los
     * de "solo tarjeta" y "solo SPEI" los invente, y el proveedor los
     * ignora: devuelve siempre lo mismo y por eso todas las opciones
     * acababan en SPEI.
     *
     * Asi que `tarjeta` y `spei` mandan `41` —todos los metodos— y el
     * modal muestra la parte que corresponde al boton elegido. El
     * cliente puede pagar de la otra forma si quiere, que no es un
     * problema: su dinero entra igual.
     *
     * En cuanto Pagadetodo diga los codigos reales, se ponen en
     * config/integraciones.php (`tipo_tarjeta`, `tipo_spei`) y esto
     * empieza a restringir de verdad, sin tocar codigo.
     */
    const METODOS = [
        'todos'    => ['Todos los métodos', '41',  'Tarjeta, transferencia y tiendas'],
        'tarjeta'  => ['Tarjeta',           '41',  'Débito o crédito, en línea'],
        'spei'     => ['Transferencia SPEI','41',  'A una CLABE, se detecta solo'],
        'efectivo' => ['Efectivo en tienda','401', 'OXXO y tiendas participantes'],
    ];

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

        $metodo = $d['metodo'] ?? 'todos';
        if (!isset(self::METODOS[$metodo])) $metodo = 'todos';
        $tipo = $this->cfg['tipo_' . $metodo] ?? self::METODOS[$metodo][1];

        $pruebas = $this->enPruebas();
        // SPEI va a su propia direccion cuando esta configurada: es el
        // endpoint que entrega la CLABE, y pide campos distintos al de
        // la liga. Mandarle el cuerpo equivocado es lo que produce "El
        // Account es obligatorio".
        $propia = ($metodo === 'spei' && !empty($this->cfg['url_clabe']))
                ? $this->cfg['url_clabe'] : null;
        $url = $propia ?: ($pruebas
             ? ($this->cfg['url_sandbox'] ?? $this->cfg['url'] ?? '')
             : ($this->cfg['url'] ?? ''));
        if (trim((string)$url) === '') {
            $this->ultimoError = 'Falta la dirección del servicio en config/integraciones.php';
            return null;
        }

        $dias = max(1, (int)($d['dias'] ?? $this->cfg['dias_vigencia'] ?? 3));

        $referencia = self::referencia($d['referencia'] ?? '');

        $cuerpo = [
            'User'           => $pruebas ? ($this->cfg['usuario_prueba'] ?: $this->cfg['usuario'])
                                         : $this->cfg['usuario'],
            'Password'       => $pruebas ? ($this->cfg['clave_prueba'] ?: $this->cfg['clave'])
                                         : $this->cfg['clave'],
            'IntegrationID'  => $this->cfg['integracion_id'],
            'BusinessID'     => $this->cfg['negocio_id'] ?? '',
            'PaymentTypes'   => (string)$tipo,
            'Id'             => (string)($d['id'] ?? $d['referencia']),
            // El proveedor corta a 40 y si se pasa, rechaza. Se corta aquí
            // para que el error no venga de su lado sin explicación.
            'Description'    => mb_substr((string)($d['descripcion'] ?? 'Pago'), 0, 40),
            // En CENTAVOS y como cadena. Mandar "150.00" en vez de "15000"
            // genera una liga por un peso y medio.
            'Amount'         => (string)(int)round($monto * 100),
            'Reference'      => $referencia,
            // `Account` es OBLIGATORIO y no estaba.
            //
            // Son 15 digitos que identifican el cobro del lado del
            // proveedor. En el sistema anterior se generaba igual que la
            // referencia, y se manda el MISMO valor: si fueran distintos
            // habria dos identificadores para una sola operacion y
            // conciliar seria adivinar cual mirar.
            'Account'        => $referencia,
            'CustomerName'   => mb_substr(trim((string)($d['cliente'] ?? '')), 0, 60) ?: 'Publico general',
            'CustomerEmail'  => filter_var($d['correo'] ?? '', FILTER_VALIDATE_EMAIL)
                                ? $d['correo'] : '',
            'ExpirationDate' => date('Y-m-d', strtotime('+' . $dias . ' days')),
        ];

        // Campos que algunos convenios piden y otros no. Se mandan solo
        // si estan configurados: enviarlos vacios hace que el proveedor
        // los rechace por invalidos en vez de ignorarlos.
        foreach (['MontoTotal' => $monto] as $k => $v) {
            if (!empty($this->cfg['manda_' . strtolower($k)])) $cuerpo[$k] = $v;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => max(10, (int)($this->cfg['timeout'] ?? 30)),
            // El certificado SÍ se verifica. El sistema anterior lo
            // desactivaba, y por aquí viajan montos y referencias de pago.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp   = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errCurl = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->ultimoError = 'No se pudo contactar al proveedor: ' . $errCurl;
            return null;
        }
        // Lo que respondio, tal cual, para poder verlo si falla. Sin
        // esto un rechazo del proveedor obliga a adivinar que campo
        // esta mal.
        $this->ultimaRespuesta = mb_substr((string)$resp, 0, 800);

        $j = json_decode($resp, true);
        if (!is_array($j)) {
            $this->ultimoError = 'El proveedor respondió algo que no se entiende (HTTP '
                               . $codigo . '): ' . mb_substr(strip_tags($resp), 0, 160);
            return null;
        }

        $liga = self::campo($j, ['Url','url','liga','PaymentUrl','link']);
        // El proveedor escribe 'Clabe' en un endpoint y 'CLABE' en otro.
        $clabe = self::campo($j, ['Clabe','CLABE','clabe','Cuenta','account']);
        $barras = self::campo($j, ['Barcode','barcode','codigo_barras','Reference']);

        if (!$liga && !$clabe && !$barras) {
            $msg = self::campo($j, ['Message','message','error','Error','ErrorMessage']);
            $this->ultimoError = $msg
                ? 'El proveedor rechazó la liga: ' . $msg
                : 'El proveedor no devolvió ninguna forma de pagar.';
            error_log('[LibertyFin] liga rechazada · enviado: ' . json_encode($cuerpo)
                . ' · recibido: ' . $this->ultimaRespuesta);
            return null;
        }

        // SE AVISA SI NO LLEGO LO QUE SE PIDIO.
        //
        // Pedir tarjeta y recibir solo una CLABE no es un error del
        // proveedor: es que el codigo de metodo no restringe. Pero el
        // cajero tiene que saberlo antes de decirle al cliente que pase
        // su tarjeta.
        $falta = '';
        if ($metodo === 'tarjeta'  && !$liga)   $falta = 'tarjeta';
        if ($metodo === 'spei'     && !$clabe)  $falta = 'spei';
        if ($metodo === 'efectivo' && !$barras) $falta = 'efectivo';

        return [
            'liga'       => $liga,
            'clabe'      => $clabe,
            'barras'     => $barras,
            'falta'      => $falta,
            'referencia' => $cuerpo['Reference'],
            'vence'      => $cuerpo['ExpirationDate'],
            'metodo'     => $metodo,
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
    public static function referencia($semilla = '')
    {
        $s = preg_replace('/\D/', '', (string)$semilla);
        if (strlen($s) >= 15) return substr($s, 0, 15);
        return str_pad($s, 15, (string)random_int(0, 9), STR_PAD_LEFT);
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
