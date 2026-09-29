<?php
namespace LibertyFin\Controlador;

/** Rótulo del periodo en palabras. Estaba copiado en cada controlador. */
final class Fechas
{
    public static function rotulo($desde, $hasta)
    {
        $m = ['','enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
        $a = strtotime($desde); $b = strtotime($hasta);
        if (date('Y-m', $a) === date('Y-m', $b)) {
            return ucfirst($m[(int)date('n', $a)]) . ' ' . date('Y', $a);
        }
        return ucfirst($m[(int)date('n', $a)]) . ' — ' . $m[(int)date('n', $b)] . ' ' . date('Y', $b);
    }
}
