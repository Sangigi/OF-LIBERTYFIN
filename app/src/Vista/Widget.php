<?php
namespace LibertyFin\Vista;

use LibertyFin\Dominio\Dinero;

/**
 * Los widgets de la interfaz, como funciones.
 *
 * Todo es SVG en línea: ni Chart.js ni Font Awesome. En la medición del
 * sistema anterior, las librerías de gráficas e iconos pesaban más que
 * todo el HTML de la página.
 */
final class Widget
{
    /** Tarjeta de cifra con azulejo de icono, barra de avance y tendencia. */
    public static function cifra(array $o)
    {
        $hero  = !empty($o['hero']) ? ' lf-hero' : '';
        $tono  = isset($o['tono']) ? ' ' . $o['tono'] : '';
        $e = 'LibertyFin\Vista\Plantilla::e';
        echo '<div class="stat-card' . $hero . '">';
        if (isset($o['delta'])) {
            $dir = $o['delta'] >= 0 ? 'up' : 'down';
            echo '<span class="lf-delta ' . $dir . '">' . abs((float)$o['delta']) . '%</span>';
        }
        if (isset($o['icono'])) {
            echo '<div class="lf-tile' . $tono . '">' . self::icono($o['icono']) . '</div>';
        }
        echo '<div class="stat-value">' . $e($o['valor']) . '</div>';
        echo '<div class="stat-label">' . $e($o['etiqueta']) . '</div>';
        if (isset($o['avance'])) self::avance($o['avance'], !empty($o['ambar']));
        if (isset($o['pie'])) echo '<div class="stat-meta">' . $e($o['pie']) . '</div>';
        echo '</div>';
    }

    /** Barra de avance. */
    public static function avance($pct, $ambar = false)
    {
        $pct = max(0, min(100, (float)$pct));
        echo '<div class="lf-prog' . ($ambar ? ' amb' : '') . '">'
           . '<i style="--w:' . $pct . '%"></i></div>';
    }

    /** Avance compacto para una celda de tabla. */
    public static function avanceMini($cobrado, $total)
    {
        $pct = Dinero::pct($cobrado, $total);
        $amb = $pct < 99.5;
        echo '<div class="lf-mini"><span class="lf-prog' . ($amb ? ' amb' : '') . '">'
           . '<i style="--w:' . max(0, min(100, $pct)) . '%"></i></span>'
           . '<b>' . round($pct) . '%</b></div>';
    }

    /** Sparkline. $datos = lista de números. */
    public static function spark(array $datos, $ancho = 180, $alto = 30)
    {
        $datos = array_values(array_map('floatval', $datos));
        if (count($datos) < 2) return;
        $lo = min($datos); $hi = max($datos); $r = ($hi - $lo) ?: 1;
        $n = count($datos); $pts = [];
        foreach ($datos as $i => $v) {
            $x = $i * ($ancho / ($n - 1));
            $y = $alto - (($v - $lo) / $r) * ($alto - 5) - 2.5;
            $pts[] = round($x, 1) . ' ' . round($y, 1);
        }
        $d  = 'M' . implode(' L', $pts);
        $ar = $d . " L{$ancho} {$alto} L0 {$alto} Z";
        // El área rellena puede estirarse sin problema; el trazo no, por eso
        // lleva vector-effect y se queda en su grosor real.
        echo '<svg class="lf-spark" viewBox="0 0 ' . $ancho . ' ' . $alto . '" preserveAspectRatio="none">'
           . '<defs><linearGradient id="lfGrad" x1="0" y1="0" x2="0" y2="1">'
           . '<stop offset="0%" stop-color="currentColor" stop-opacity=".26"/>'
           . '<stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>'
           . '<path class="ar" d="' . $ar . '"/>'
           . '<path class="ln" vector-effect="non-scaling-stroke" d="' . $d . '"/></svg>';
    }

    /** Gráfica de barras apiladas: cobrado y pendiente por mes. */
    public static function barras(array $meses, $alto = 158)
    {
        $max = 1;
        foreach ($meses as $m) $max = max($max, (float)$m['cobrado'] + (float)($m['pendiente'] ?? 0));
        echo '<div class="lf-chart" style="display:flex;align-items:flex-end;gap:16px;height:' . $alto . 'px">';
        foreach ($meses as $m) {
            $c = (float)$m['cobrado']; $p = (float)($m['pendiente'] ?? 0);
            echo '<div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;gap:3px;height:100%">';
            if ($p > 0) echo '<div style="height:' . round($p / $max * 100, 1) . '%;border-radius:6px 6px 0 0;background:var(--lf-brand-soft);border:1px solid var(--lf-brand-20,rgba(39,174,96,.28));border-bottom:none"></div>';
            echo '<div style="height:' . round($c / $max * 100, 1) . '%;border-radius:6px 6px 0 0;background:var(--lf-brand)" title="' . Dinero::pesos($c) . '"></div>';
            echo '</div>';
        }
        echo '</div>';
    }


    /** Marcador circular. Para puntajes, no para dinero. */
    public static function marcador($pct, $rotulo = '', $r = 48, $grosor = 9)
    {
        $pct = max(0, min(100, (float)$pct));
        $c   = 2 * M_PI * $r;
        $d   = $r + $grosor / 2 + 2;
        $lado = $d * 2;
        echo '<div class="lf-ring" style="width:' . $lado . 'px;height:' . $lado . 'px">'
           . '<svg width="' . $lado . '" height="' . $lado . '" viewBox="0 0 ' . $lado . ' ' . $lado . '">'
           . '<circle class="bg" r="' . $r . '" cx="' . $d . '" cy="' . $d . '" stroke-width="' . $grosor . '"/>'
           . '<circle class="fg" r="' . $r . '" cx="' . $d . '" cy="' . $d . '" stroke-width="' . $grosor . '"'
           . ' stroke-dasharray="' . round($c * $pct / 100, 1) . ' ' . round($c, 1) . '"/>'
           . '</svg><div class="mid"><b>' . round($pct) . '</b>'
           . ($rotulo ? '<small>' . Plantilla::e($rotulo) . '</small>' : '') . '</div></div>';
    }

    /**
     * Dos curvas suaves superpuestas. La separación entre ellas ES el dato:
     * lo vendido que todavía no se cobra.
     */
    public static function curvas(array $series, array $rotulos, $ancho = 560, $alto = 180)
    {
        $todos = [];
        foreach ($series as $s) foreach ($s['datos'] as $v) $todos[] = (float)$v;
        if (count($todos) < 2) return;
        $lo = min($todos); $hi = max($todos); $r = ($hi - $lo) ?: 1;
        $pad = 16;

        $trazo = function (array $d) use ($ancho, $alto, $pad, $lo, $r) {
            $n = count($d); $pts = [];
            foreach ($d as $i => $v) {
                $pts[] = [$pad + $i * (($ancho - 2 * $pad) / max(1, $n - 1)),
                          $alto - $pad - ((float)$v - $lo) / $r * ($alto - 2 * $pad)];
            }
            $s = sprintf('M%.1f %.1f', $pts[0][0], $pts[0][1]);
            for ($i = 0; $i < count($pts) - 1; $i++) {
                $p0 = $i > 0 ? $pts[$i-1] : $pts[$i];
                $p1 = $pts[$i]; $p2 = $pts[$i+1];
                $p3 = isset($pts[$i+2]) ? $pts[$i+2] : $p2;
                $s .= sprintf(' C%.1f %.1f, %.1f %.1f, %.1f %.1f',
                    $p1[0] + ($p2[0]-$p0[0])/6, $p1[1] + ($p2[1]-$p0[1])/6,
                    $p2[0] - ($p3[0]-$p1[0])/6, $p2[1] - ($p3[1]-$p1[1])/6,
                    $p2[0], $p2[1]);
            }
            return [$s, $pts];
        };

        // Sin preserveAspectRatio="none": estirar el viewBox al ancho real
        // deforma trazos y convierte los puntos en elipses. Escalado uniforme
        // y el alto lo pone el propio viewBox.
        echo '<svg class="lf-curva" viewBox="0 0 ' . $ancho . ' ' . $alto . '">';
        for ($k = 1; $k < 4; $k++)
            echo '<line class="gl" x1="0" y1="' . ($alto*$k/4) . '" x2="' . $ancho . '" y2="' . ($alto*$k/4) . '"/>';
        $primero = true;
        foreach ($series as $s) {
            list($d, $pts) = $trazo($s['datos']);
            if ($primero) {
                echo '<path class="ar" d="' . $d . sprintf(' L%.1f %d L%.1f %d Z',
                     end($pts)[0], $alto - 6, $pts[0][0], $alto - 6) . '"/>';
            }
            echo '<path class="' . ($primero ? 'l1' : 'l2') . '" d="' . $d . '"/>';
            $primero = false;
        }
        foreach ($series as $j => $s) {
            list($d, $pts) = $trazo($s['datos']);
            foreach ($pts as $i => $pt) {
                $t = isset($rotulos[$i]) ? $rotulos[$i] : '';
                echo '<circle class="' . ($j ? 'p2' : 'p1') . '" cx="' . round($pt[0],1) . '" cy="' . round($pt[1],1)
                   . '" r="' . ($j ? 3.4 : 4.6) . '"><title>' . Plantilla::e($t . ' · ' . Dinero::pesos($s['datos'][$i])) . '</title></circle>';
            }
        }
        echo '</svg>';
    }

    /** Etiqueta de estado de una venta. */
    public static function estado($saldo, $estado = '')
    {
        if ($estado === 'cancelada') { echo '<span class="badge bg-secondary">Cancelada</span>'; return; }
        if ((float)$saldo <= 0.01)   { echo '<span class="badge bg-success">Liquidada</span>'; return; }
        echo '<span class="badge bg-warning">Debe ' . Dinero::corto($saldo) . '</span>';
    }

    /** Iconos de línea. Sin Font Awesome. */
    public static function icono($n, $tam = '1em')
    {
        $p = [
            'panel'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'caja'   => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h4"/>',
            'venta'  => '<path d="M3 6h18M3 12h18M3 18h11"/>',
            'cliente'=> '<circle cx="9" cy="8" r="3.4"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11.2a3 3 0 1 0 0-6M18 20a5.5 5.5 0 0 0-2-4.3"/>',
            'comi'   => '<path d="M12 2v20M17 6.5C17 4.6 14.8 3.5 12 3.5S7 4.6 7 6.5s2.2 2.8 5 3.3 5 1.4 5 3.4-2.2 3.3-5 3.3-5-1.1-5-3"/>',
            'serv'   => '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M12 12v9M3.5 7.5 12 12l8.5-4.5"/>',
            'cobro'  => '<path d="M3 17l5.5-6 4 3.5L21 6"/><path d="M16 6h5v5"/>',
            'reloj'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/>',
            'bolsa'  => '<path d="M5 8h14l1 12H4z"/><path d="M8.5 8V6a3.5 3.5 0 0 1 7 0v2"/>',
            'pct'    => '<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
            'alerta' => '<path d="M12 8v5M12 16.5v.5"/><circle cx="12" cy="12" r="9"/>',
            'buscar' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'mas'    => '<path d="M12 5v14M5 12h14"/>',
            'baja'   => '<path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M4 20h16"/>',
        ];
        $d = isset($p[$n]) ? $p[$n] : $p['panel'];
        return '<svg viewBox="0 0 24 24" style="width:' . $tam . ';height:' . $tam . ';fill:none;stroke:currentColor;'
             . 'stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;display:block">' . $d . '</svg>';
    }
}
