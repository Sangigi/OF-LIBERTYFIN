<?php
namespace LibertyFin\Vista;

/**
 * Render de plantillas. Sin motor, sin dependencias: PHP es el motor.
 * Lo único que agrega es escapado por defecto y un layout.
 */
final class Plantilla
{
    private static $base;
    public static function base($ruta) { self::$base = rtrim($ruta, '/'); }

    /** Escapa. Se usa SIEMPRE que se imprima algo que venga de la base. */
    public static function e($v)
    { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function parcial($nombre, array $datos = [])
    {
        extract($datos, EXTR_SKIP);
        include self::$base . '/' . $nombre . '.php';
    }

    public static function pagina($nombre, array $datos = [], $layout = 'layout')
    {
        extract($datos, EXTR_SKIP);
        ob_start();
        include self::$base . '/' . $nombre . '.php';
        $contenido = ob_get_clean();
        include self::$base . '/' . $layout . '.php';
    }
}
