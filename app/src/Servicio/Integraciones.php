<?php
namespace LibertyFin\Servicio;

/**
 * Qué integraciones están disponibles.
 *
 * Las credenciales viven en config/integraciones.php, fuera de public/
 * y fuera del repositorio. Esta clase es lo único que las lee, y NUNCA
 * las devuelve completas hacia una vista: para eso está estado(), que
 * solo dice si están puestas.
 */
final class Integraciones
{
    private static $cfg = null;
    private static $ruta = '';
    private static $hayArchivo = false;

    const CATALOGO = [
        // Emida pide cuatro datos, no tres. `merchant_id` identifica al
        // comercio y `clerk_password` a la terminal: son distintos de la
        // clave de la cuenta aunque el proveedor a veces los reparta igual.
        'emida'         => ['Recargas telefónicas', 'Tiempo aire y pago de servicios',
                            ['usuario','clave','merchant_id']],
        'facturapi'     => ['Facturación CFDI', 'Timbrado de facturas',
                            ['llave','organizacion']],
        'spei'          => ['Ligas de pago SPEI', 'Cobro por transferencia',
                            ['usuario','clave','integracion_id']],
        'domiciliacion' => ['Cargos recurrentes', 'Domiciliación de mensualidades',
                            ['usuario','clave','integracion_id']],
        'paypal'        => ['PayPal', 'Ligas de pago con tarjeta',
                            ['cliente','secreto']],
        'smtp'          => ['Correo saliente', 'Envío de tickets y avisos',
                            ['host','usuario','clave']],
        'cpanel'        => ['cPanel', 'Alta de bases para empresas nuevas',
                            ['host','usuario','token']],
    ];

    public static function cargar($raiz)
    {
        if (self::$cfg !== null) return;
        self::$ruta = $raiz . '/config/integraciones.php';
        self::$hayArchivo = is_readable(self::$ruta);
        self::$cfg = self::$hayArchivo ? (array)require self::$ruta : [];
    }

    /**
     * Si el archivo existe y dónde se buscó.
     *
     * Sin esto, una integración configurada en
     * `integraciones.php.ejemplo` —que es el error natural— se ve
     * exactamente igual que una sin configurar, y no hay forma de saber
     * cuál de las dos cosas pasa.
     */
    public static function archivo()
    {
        return ['ruta' => self::$ruta, 'existe' => self::$hayArchivo,
                'bloques' => is_array(self::$cfg) ? count(self::$cfg) : 0];
    }

    /** La configuración de una integración, o null si no está lista. */
    public static function de($nombre)
    {
        if (self::$cfg === null) return null;
        $c = self::$cfg[$nombre] ?? null;
        return (self::lista($nombre) && !empty($c['activo'])) ? $c : null;
    }

    /** ¿Tiene todas sus credenciales llenas? */
    public static function lista($nombre)
    {
        $c = self::$cfg[$nombre] ?? null;
        if (!$c || !isset(self::CATALOGO[$nombre])) return false;
        foreach (self::CATALOGO[$nombre][2] as $campo) {
            if (trim((string)($c[$campo] ?? '')) === '') return false;
        }
        return true;
    }

    public static function activa($nombre) { return self::de($nombre) !== null; }

    /**
     * El estado de todas, para mostrarlo.
     * Devuelve si están listas y en qué modo, NUNCA las credenciales.
     */
    public static function estado()
    {
        $r = [];
        foreach (self::CATALOGO as $k => $d) {
            $c = self::$cfg[$k] ?? [];
            $lista = self::lista($k);
            $r[$k] = [
                'nombre'    => $d[0],
                'para'      => $d[1],
                'campos'    => $d[2],
                'lista'     => $lista,
                'activa'    => $lista && !empty($c['activo']),
                'sandbox'   => !empty($c['sandbox']),
                'faltan'    => array_values(array_filter($d[2], function ($campo) use ($c) {
                    return trim((string)($c[$campo] ?? '')) === '';
                })),
            ];
        }
        return $r;
    }
}
