<?php
namespace LibertyFin\Datos;

use PDO;

/**
 * Conexiones a la base, una por nombre y reutilizada toda la petición.
 * En el sistema anterior cada llamada abría un PDO nuevo: caja.php abría
 * tres por carga.
 */
final class Conexion
{
    private static $vivas = [];
    private static $cfg = null;

    public static function configurar(array $cfg) { self::$cfg = $cfg; }

    public static function de($base)
    {
        if (isset(self::$vivas[$base])) return self::$vivas[$base];
        if (self::$cfg === null) throw new \RuntimeException('Conexion::configurar() no se ha llamado');
        $c = self::$cfg;
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$base};charset=utf8mb4",
            $c['usuario'], $c['clave'],
            [
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES         => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
        // La zona de la SESIÓN, no la del servidor: así funciona aunque
        // el hosting esté en UTC y no se pueda cambiar.
        if (!empty($c['zona_sql'])) {
            $pdo->exec("SET time_zone = '" . str_replace("'", '', $c['zona_sql']) . "'");
        }
        return self::$vivas[$base] = $pdo;
    }
}
