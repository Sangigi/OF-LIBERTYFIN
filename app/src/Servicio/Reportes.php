<?php
namespace LibertyFin\Servicio;

use LibertyFin\Datos\ReporteRepo;
use LibertyFin\Dominio\Dinero;
use PDO;

/**
 * Los reportes que se pueden sacar, y cómo se arman.
 *
 * UN SOLO LUGAR DEFINE CADA REPORTE
 *
 * El mismo arreglo alimenta la tabla en pantalla, el Excel y la hoja
 * imprimible. Si cada salida armara lo suyo, en dos meses el PDF diría
 * una cosa y el Excel otra, y nadie sabría cuál creer.
 */
final class Reportes
{
    const TIPOS = [
        'area' => [
            'rotulo' => 'Por área',
            'nota'   => 'El área sale del servicio contratado, no del cliente. Una venta con '
                      . 'servicios de dos áreas reparte su dinero entre las dos.',
        ],
        'colaborador' => [
            'rotulo' => 'Por colaborador',
            'nota'   => 'Comisiones generadas por los pagos recibidos en el periodo.',
        ],
        'servicio' => [
            'rotulo' => 'Por servicio',
            'nota'   => 'Qué se vende más y qué deja más. No son lo mismo.',
        ],
        'cliente' => [
            'rotulo' => 'Por cliente',
            'nota'   => 'Lo que cada cliente ha comprado y lo que todavía debe.',
        ],
        'cobranza' => [
            'rotulo' => 'Cobranza',
            'nota'   => 'Lo que falta por cobrar, de lo más viejo a lo más nuevo.',
        ],
        'metodo' => [
            'rotulo' => 'Por forma de pago',
            'nota'   => 'Cómo entra el dinero. Sirve para cuadrar contra el banco.',
        ],
        'dia' => [
            'rotulo' => 'Día por día',
            'nota'   => 'Lo cobrado cada día del periodo.',
        ],
        'detalle' => [
            'rotulo' => 'Detalle de pagos',
            'nota'   => 'Cada abono, uno por renglón. Es el que se audita.',
        ],
    ];

    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    /**
     * Arma un reporte.
     * @return array  ['titulo','nota','columnas','filas','totales']
     */
    public function armar($tipo, $desde, $hasta)
    {
        $r = new ReporteRepo($this->db);
        $L = Libro::class;

        switch ($tipo) {
            case 'area':
                $f = $r->porArea($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Área', Libro::TEXTO, 30], ['Ventas', Libro::NUMERO, 10],
                     ['Vendido', Libro::MONEDA, 15], ['Cobrado', Libro::MONEDA, 15],
                     ['Gastos', Libro::MONEDA, 14], ['Comisiones', Libro::MONEDA, 14],
                     ['Queda', Libro::MONEDA, 15]],
                    array_map(function ($x) {
                        return [$x['area'], (int)$x['ventas'], $x['vendido'], $x['cobrado'],
                                $x['gastos'], $x['comisiones'],
                                $x['cobrado'] - $x['gastos'] - $x['comisiones']];
                    }, $f),
                    $this->sumar($f, ['ventas','vendido','cobrado','gastos','comisiones'],
                        function ($t) { return ['TOTAL', (int)$t['ventas'], $t['vendido'],
                            $t['cobrado'], $t['gastos'], $t['comisiones'],
                            $t['cobrado'] - $t['gastos'] - $t['comisiones']]; }));

            case 'colaborador':
                $f = $r->porColaborador($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Colaborador', Libro::TEXTO, 28], ['Área', Libro::TEXTO, 24],
                     ['Ventas', Libro::NUMERO, 10], ['Comisión', Libro::MONEDA, 15]],
                    array_map(function ($x) {
                        return [$x['nombre'] ?: 'POR ASIGNAR', $x['area'],
                                (int)$x['ventas'], $x['devengado']];
                    }, $f),
                    $this->sumar($f, ['ventas','devengado'],
                        function ($t) { return ['TOTAL', '', (int)$t['ventas'], $t['devengado']]; }));

            case 'servicio':
                $f = $r->porServicio($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Servicio', Libro::TEXTO, 44], ['Veces', Libro::NUMERO, 10],
                     ['Vendido', Libro::MONEDA, 16]],
                    array_map(function ($x) {
                        return [$x['nombre'], (int)$x['veces'], $x['facturado']];
                    }, $f),
                    $this->sumar($f, ['veces','facturado'],
                        function ($t) { return ['TOTAL', (int)$t['veces'], $t['facturado']]; }));

            case 'cliente':
                $f = $r->porCliente($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Cliente', Libro::TEXTO, 34], ['Área', Libro::TEXTO, 24],
                     ['Compras', Libro::NUMERO, 10], ['Vendido', Libro::MONEDA, 15],
                     ['Cobrado', Libro::MONEDA, 15], ['Debe', Libro::MONEDA, 15]],
                    array_map(function ($x) {
                        return [$x['cliente'], $x['area'] ?? '', (int)$x['compras'],
                                $x['vendido'], $x['cobrado'], $x['vendido'] - $x['cobrado']];
                    }, $f),
                    $this->sumar($f, ['compras','vendido','cobrado'],
                        function ($t) { return ['TOTAL', '', (int)$t['compras'], $t['vendido'],
                            $t['cobrado'], $t['vendido'] - $t['cobrado']]; }));

            case 'cobranza':
                $f = $r->cobranza($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Folio', Libro::TEXTO, 18], ['Cliente', Libro::TEXTO, 30],
                     ['Fecha', Libro::FECHA, 12], ['Días', Libro::NUMERO, 8],
                     ['Total', Libro::MONEDA, 14], ['Cobrado', Libro::MONEDA, 14],
                     ['Debe', Libro::MONEDA, 14]],
                    array_map(function ($x) {
                        return [$x['codigo_venta'], $x['cliente'], $x['fecha'], (int)$x['dias'],
                                $x['total'], $x['cobrado'], $x['saldo']];
                    }, $f),
                    $this->sumar($f, ['total','cobrado','saldo'],
                        function ($t) { return ['TOTAL', '', '', '', $t['total'],
                            $t['cobrado'], $t['saldo']]; }));

            case 'metodo':
                $f = $r->porMetodo($desde, $hasta);
                $gran = array_sum(array_column($f, 'monto'));
                return $this->envolver($tipo, $desde, $hasta,
                    [['Forma de pago', Libro::TEXTO, 24], ['Cobros', Libro::NUMERO, 10],
                     ['Monto', Libro::MONEDA, 16], ['Parte', Libro::PORCENT, 10]],
                    array_map(function ($x) use ($gran) {
                        return [ucfirst($x['metodo']), (int)$x['cobros'], $x['monto'],
                                $gran > 0 ? $x['monto'] / $gran : 0];
                    }, $f),
                    $this->sumar($f, ['cobros','monto'],
                        function ($t) { return ['TOTAL', (int)$t['cobros'], $t['monto'], 1]; }));

            case 'dia':
                $f = $r->porDia($desde, $hasta);
                return $this->envolver($tipo, $desde, $hasta,
                    [['Fecha', Libro::FECHA, 14], ['Pagos', Libro::NUMERO, 10],
                     ['Cobrado', Libro::MONEDA, 16]],
                    array_map(function ($x) {
                        return [$x['dia'], (int)$x['pagos'], $x['cobrado']];
                    }, $f),
                    $this->sumar($f, ['pagos','cobrado'],
                        function ($t) { return ['TOTAL', (int)$t['pagos'], $t['cobrado']]; }));

            default: // detalle
                $f = $r->detalle($desde, $hasta);
                return $this->envolver('detalle', $desde, $hasta,
                    [['Folio', Libro::TEXTO, 18], ['Cliente', Libro::TEXTO, 30],
                     ['Área', Libro::TEXTO, 24], ['Fecha', Libro::FECHA, 13],
                     ['Base', Libro::MONEDA, 13], ['IVA', Libro::MONEDA, 12],
                     ['Total', Libro::MONEDA, 14], ['Cobrado', Libro::MONEDA, 14],
                     ['Debe', Libro::MONEDA, 13], ['Gastos', Libro::MONEDA, 13],
                     ['Comisión', Libro::MONEDA, 13], ['Queda', Libro::MONEDA, 14]],
                    array_map(function ($x) {
                        return [$x['folio'], $x['cliente'], $x['area'], $x['fecha'],
                                $x['subtotal'], $x['iva'], $x['total'], $x['cobrado'],
                                $x['saldo'], $x['gastos'], $x['comision'],
                                $x['cobrado'] - $x['gastos'] - $x['comision']];
                    }, $f),
                    $this->sumar($f, ['subtotal','iva','total','cobrado','saldo','gastos','comision'],
                        function ($t) { return ['TOTAL', '', '', '',
                            $t['subtotal'], $t['iva'], $t['total'], $t['cobrado'],
                            $t['saldo'], $t['gastos'], $t['comision'],
                            $t['cobrado'] - $t['gastos'] - $t['comision']]; }));
        }
    }

    private function envolver($tipo, $desde, $hasta, $columnas, $filas, $totales)
    {
        return [
            'tipo'      => $tipo,
            'titulo'    => self::TIPOS[$tipo]['rotulo'] ?? 'Reporte',
            'nota'      => self::TIPOS[$tipo]['nota'] ?? '',
            'periodo'   => date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)),
            'columnas'  => $columnas,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    private function sumar(array $f, array $campos, callable $armar)
    {
        $t = [];
        foreach ($campos as $c) $t[$c] = array_sum(array_column($f, $c));
        $t['saldo'] = isset($t['saldo']) ? $t['saldo']
                    : (isset($t['total'], $t['cobrado']) ? $t['total'] - $t['cobrado'] : 0);
        return $armar($t);
    }

    /** Los ocho reportes del periodo, para pintarlos todos de una vez. */
    public function todos($desde, $hasta)
    {
        $r = [];
        foreach (array_keys(self::TIPOS) as $t) $r[$t] = $this->armar($t, $desde, $hasta);
        return $r;
    }

    /** Un .xlsx con todos los reportes del periodo, uno por hoja. */
    public function libroCompleto($desde, $hasta)
    {
        $l = new Libro();
        foreach ($this->todos($desde, $hasta) as $rep) {
            $l->hoja($rep['titulo'], $rep['columnas'], $rep['filas'], [
                'titulo'    => $rep['titulo'],
                'subtitulo' => $rep['periodo'] . ' · ' . $rep['nota'],
                'totales'   => $rep['totales'],
            ]);
        }
        return $l;
    }
}
