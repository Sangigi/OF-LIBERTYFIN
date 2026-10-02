<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\RutaLigaRepo;

/**
 * Apuntar un cobro en el directorio de la base principal.
 *
 * Es una línea de trabajo y cuatro de cuidados: la base principal puede
 * no estar configurada, puede estar caída, y la sesión puede ser de
 * plataforma y no tener empresa. Ninguna de esas tres cosas debe impedir
 * cobrar, así que se envuelve aquí en vez de repetir el try/catch en
 * cada sitio que genera un cobro.
 *
 * Si falla, lo único que se pierde es que el aviso del proveedor tenga
 * que buscar la referencia recorriendo las bases —más lento, pero
 * funciona— y queda el botón de "ya me pagó" como salida.
 */
final class Cobros
{
    public static function apuntar($referencia, $metodo = null)
    {
        $base = $_SESSION['empresa_db'] ?? '';
        if (trim((string)$base) === '') return false;

        $principal = trim((string)($GLOBALS['lf_bd_principal'] ?? ''));
        if ($principal === '') {
            error_log('[LibertyFin] sin `bd.principal`: el cobro ' . $referencia
                . ' no quedó en el directorio y su aviso tardará más en casar.');
            return false;
        }

        try {
            return (new RutaLigaRepo(Conexion::de($principal)))->apuntar(
                $referencia, $base, $_SESSION['empresa_id'] ?? null, $metodo);
        } catch (\Throwable $e) {
            error_log('[LibertyFin] apuntar cobro: ' . $e->getMessage());
            return false;
        }
    }
}
