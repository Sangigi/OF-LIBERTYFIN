<?php
namespace LibertyFin\Dominio;

/**
 * Importes en pesos.
 *
 * Existe por una razón concreta: en esta base hubo comisiones con doce
 * decimales y pagos de un centavo metidos a mano para cerrar ventas que
 * no cuadraban por redondeo. Centralizar formato y redondeo evita que
 * cada archivo lo resuelva por su cuenta.
 */
final class Dinero
{
    /** Redondeo a centavos. Todo importe que se guarde pasa por aquí. */
    public static function centavos($v) { return round((float)$v, 2); }

    /** $1,234.56 */
    public static function pesos($v, $dec = 2) { return '$' . number_format((float)$v, $dec); }

    /** $1,235 — para cifras grandes en tarjetas, donde los centavos estorban */
    public static function corto($v)
    {
        $v = (float)$v;
        if (abs($v) >= 1000000) return '$' . number_format($v / 1000000, 1) . 'M';
        return '$' . number_format($v, 0);
    }

    /** Porcentaje de a sobre b, sin dividir entre cero. */
    public static function pct($a, $b)
    {
        $b = (float)$b;
        return $b == 0 ? 0.0 : round(((float)$a / $b) * 100, 1);
    }
}
