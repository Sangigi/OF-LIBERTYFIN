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

    const CATALOGO = [
        'emida'         => ['Recargas telefónicas', 'Tiempo aire y pago de servicios',
                            ['usuario','clave','terminal_id']],
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
        $ruta = $raiz . '/config/integraciones.php';
        self::$cfg = is_readable($ruta) ? (array)require $ruta : [];
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
