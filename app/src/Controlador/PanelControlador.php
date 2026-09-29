<?php
namespace LibertyFin\Controlador;

use LibertyFin\Datos\Conexion;
use LibertyFin\Datos\PanelRepo;
use LibertyFin\Datos\VentaRepo;
use LibertyFin\Dominio\Dinero;
use LibertyFin\Http\Peticion;
use LibertyFin\Vista\Plantilla;

final class PanelControlador
{
    public function index()
    {
        $db    = Conexion::de($_SESSION['empresa_db']);
        $panel = new PanelRepo($db);
        $ventas= new VentaRepo($db);

        $desde = Peticion::fecha('desde', date('Y-m-01'));
        $hasta = Peticion::fecha('hasta', date('Y-m-t'));

        $resumen = $ventas->resumen($desde, $hasta);
        $meses   = $panel->porMes(7);
        $hoy     = $panel->hoy();
        $prom    = $panel->promedioDiario(30);
        $viejo   = $panel->saldoViejo(30);
        $comis   = $panel->comisiones($desde, $hasta);
        $movs    = $panel->movimientos(6);

        $cobrado = (float)($resumen['cobrado'] ?? 0);
        $vendido = (float)($resumen['vendido'] ?? 0);

        Plantilla::pagina('panel/index', [
            'titulo'    => 'Panel',
            'icono'     => 'panel',
            'subtitulo' => self::rotulo($desde, $hasta),
            'saludo'    => self::saludo(),
            'resumen'   => $resumen,
            'avance'    => Dinero::pct($cobrado, $vendido),
            'salud'     => self::salud($cobrado, $vendido, $viejo),
            'sintesis'  => self::sintesis($cobrado, $vendido, $resumen, $viejo),
            'meses'     => $meses,
            'hoy'       => $hoy,
            'promedio'  => $prom,
            'viejo'     => $viejo,
            'comisiones'=> $comis,
            'movimientos'=> $movs,
            'desde'     => $desde,
            'hasta'     => $hasta,
        ]);
    }

    /**
     * Puntaje de salud de la cobranza, 0 a 100.
     *
     * Dos cosas, no una: qué tanto se ha cobrado, y qué tan rancio está
     * lo que falta. Una empresa al 70% cobrado con todo fresco está mejor
     * que otra al 85% con la mitad parada hace tres meses.
     */
    private static function salud($cobrado, $vendido, array $viejo)
    {
        if ($vendido <= 0) return 0;
        $pctCobrado = min(100, ($cobrado / $vendido) * 100);
        $saldo      = max(0, $vendido - $cobrado);
        $rancio     = $saldo > 0 ? min(1, (float)$viejo['monto'] / $saldo) : 0;
        return (int)round($pctCobrado * (1 - $rancio * 0.35));
    }

    /** La frase del encabezado. Dice qué pasa, no adjetivos. */
    private static function sintesis($cobrado, $vendido, array $r, array $viejo)
    {
        $pct   = Dinero::pct($cobrado, $vendido);
        $saldo = max(0, $vendido - $cobrado);
        if ($vendido <= 0) {
            return ['tono' => 'Sin ventas en el periodo', 'detalle' => 'Nada que cobrar todavía.'];
        }
        if ($pct >= 90)      $tono = 'va muy bien';
        elseif ($pct >= 70)  $tono = 'va por buen camino';
        elseif ($pct >= 45)  $tono = 'va a medias';
        else                 $tono = 'está atrasada';

        $det = 'Quedan ' . Dinero::pesos($saldo) . ' abiertos en '
             . (int)($r['con_saldo'] ?? 0) . ' ventas.';
        if ((float)$viejo['monto'] > 0) {
            $det .= ' De eso, ' . Dinero::pesos($viejo['monto']) . ' en '
                 . (int)$viejo['ventas'] . ' ventas lleva más de 30 días sin movimiento.';
        }
        return ['tono' => $tono, 'detalle' => $det, 'pct' => $pct];
    }

    private static function saludo()
    {
        $h = (int)date('G');
        $m = $h < 12 ? 'Buenos días' : ($h < 19 ? 'Buenas tardes' : 'Buenas noches');
        $n = $_SESSION['usuario_nombre'] ?? '';
        $n = $n ? ', ' . explode(' ', trim($n))[0] : '';
        return $m . $n;
    }

    private static function rotulo($d, $h)
    {
        $m = ['','enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
        $a = strtotime($d); $b = strtotime($h);
        if (date('Y-m', $a) === date('Y-m', $b)) return ucfirst($m[(int)date('n',$a)]) . ' ' . date('Y',$a);
        return ucfirst($m[(int)date('n',$a)]) . ' — ' . $m[(int)date('n',$b)] . ' ' . date('Y',$b);
    }
}
