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
        return self::$vivas[$base] = $pdo;
    }
}
