<?php
namespace LibertyFin\Datos;

/**
 * Reportes.
 *
 * La cifra que importa no es lo vendido ni lo cobrado: es lo que queda
 * después de pagar lo que la venta costó. Al empezar la migración el
 * sistema decía $558,057.60 de "monto total" sumando `ventas.total`, y
 * lo cobrado de verdad eran $289,468.05.
 *
 * Por eso aquí todo parte del COBRADO y se le restan las tres salidas:
 * gastos de operación, gastos generales y comisiones devengadas.
 *
 * Los criterios de fecha NO son iguales para todo, a propósito:
 *   cobrado             -> venta_pagos.fecha_pago  (cuándo entró el dinero)
 *   gastos de operación -> ventas.fecha            (cuelgan de su venta)
 *   gastos generales    -> gastos.fecha            (cuándo se pagó)
 *   comisiones          -> ventas.fecha            (a qué venta pertenecen)
 */
final class ReporteRepo extends Repo
{
    private function rango($desde, $hasta)
    {
        return [$desde . ' 00:00:00',
                date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00'];
    }

    /**
     * El resultado del periodo, línea por línea.
     *
     * @param bool $conAnteriores  separar lo cobrado de ventas de antes del
     *                             periodo. La gráfica de meses no lo usa y
     *                             se ahorra la consulta.
     */
    public function resultado($desde, $hasta, $conAnteriores = true)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        $cobrado = (float)$this->valor("
            SELECT COALESCE(SUM(p.monto),0)
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?", [$a, $b]);

        $vendido = (float)$this->valor("
            SELECT COALESCE(SUM(total),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        $operacion = (float)$this->valor("
            SELECT COALESCE(SUM(g.monto),0)
            FROM gastos g INNER JOIN ventas v ON v.id = g.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
              AND g.tipo = 'manual' AND g.categoria <> 'Costo de venta'", [$a, $b]);

        $generales = (float)$this->valor("
            SELECT COALESCE(SUM(monto),0) FROM gastos
            WHERE venta_id IS NULL AND fecha >= ? AND fecha < ?", [$a, $b]);

        $comisiones = (float)$this->valor("
            SELECT COALESCE(SUM(pc.monto),0)
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?", [$a, $b]);

        $iva = (float)$this->valor("
            SELECT COALESCE(SUM(iva),0) FROM ventas
            WHERE estado <> 'cancelada' AND fecha >= ? AND fecha < ?", [$a, $b]);

        // LO QUE ENTRÓ POR VENTAS DE ANTES DEL PERIODO.
        //
        // Ya está dentro de `cobrado`: un abono de octubre sobre una venta de
        // septiembre es dinero de octubre. Se separa para poder DECIRLO,
        // porque las tablas que van por fecha de venta (por área, por
        // cliente, el desglose…) no lo enseñan, y sin esta cifra "Entraron"
        // parecía no cuadrar con nada.
        $anteriores = $conAnteriores ? $this->deAnteriores($desde, $hasta)
                                     : ['monto' => 0, 'cobros' => 0, 'ventas' => 0];

        return [
            'de_anteriores'        => round((float)$anteriores['monto'], 2),
            'de_anteriores_cobros' => (int)$anteriores['cobros'],
            'de_anteriores_ventas' => (int)$anteriores['ventas'],
            'cobrado'    => $cobrado,
            'vendido'    => $vendido,
            'por_cobrar' => max(0, $vendido - $cobrado),
            'iva'        => $iva,
            'operacion'  => $operacion,
            'generales'  => $generales,
            'comisiones' => $comisiones,
            'queda'      => round($cobrado - $operacion - $generales - $comisiones, 2),
        ];
    }

    /** Lo mismo mes a mes, para ver la tendencia. */
    public function porMes($meses = 6)
    {
        $desde = date('Y-m-01', strtotime("-" . ((int)$meses - 1) . " month"));
        $salida = [];
        for ($i = 0; $i < $meses; $i++) {
            $m  = date('Y-m', strtotime("+{$i} month", strtotime($desde)));
            $r  = $this->resultado($m . '-01', date('Y-m-t', strtotime($m . '-01')), false);
            $r['mes'] = $m;
            $salida[] = $r;
        }
        return $salida;
    }

    /**
     * Lo que entró en el periodo por ventas de ANTES del periodo: anticipos
     * y abonos que llegaron ahora sobre ventas de otro mes.
     *
     * Mismos filtros que el "cobrado" de resultado() más la fecha de la
     * venta, así que es la parte de "Entraron" que las tablas por fecha de
     * venta no enseñan.
     *
     * @return array ['monto' => float, 'cobros' => int, 'ventas' => int]
     */
    public function deAnteriores($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        $r = $this->uno("
            SELECT COALESCE(SUM(p.monto),0)   AS monto,
                   COUNT(*)                   AS cobros,
                   COUNT(DISTINCT p.venta_id) AS ventas
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
              AND v.fecha < ?", [$a, $b, $a]);
        return ['monto'  => round((float)($r['monto'] ?? 0), 2),
                'cobros' => (int)($r['cobros'] ?? 0),
                'ventas' => (int)($r['ventas'] ?? 0)];
    }

    /**
     * Lo mismo que deAnteriores(), pero POR ÁREA.
     *
     * Con el mismo criterio que porArea(): el área sale del producto de cada
     * renglón de la venta, y el pago se reparte entre sus renglones según lo
     * que pesa cada uno. Un abono de $3,000 a una venta que fue mitad legal
     * y mitad contable pone $1,500 en cada área.
     *
     * `cobros` cuenta los pagos que tocan el área. Un pago repartido entre
     * dos áreas cuenta en las dos, así que la suma por área puede pasar del
     * total de cobros; el total real es el de deAnteriores().
     */
    public function anterioresPorArea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT area,
                   COUNT(DISTINCT pago_id) AS cobros,
                   ROUND(SUM(monto), 2)    AS monto
            FROM (
                SELECT p.id AS pago_id,
                       COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       p.monto * (d.subtotal / NULLIF(tot.suma,0)) AS monto
                FROM venta_pagos p
                INNER JOIN ventas v          ON v.id = p.venta_id
                INNER JOIN venta_detalles d  ON d.venta_id = v.id
                LEFT  JOIN productos pr      ON pr.id = d.producto_id
                LEFT  JOIN categorias cat    ON cat.id = pr.categoria_id
                INNER JOIN ( SELECT venta_id, SUM(subtotal) suma
                             FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
                  AND p.fecha_pago >= ? AND p.fecha_pago < ?
                  AND v.fecha < ?
            ) x
            GROUP BY area
            ORDER BY monto DESC", [$a, $b, $a]);
    }

    /**
     * Por área: las ventas del periodo y, además, lo que entró por ventas de
     * meses anteriores.
     *
     * Cada renglón trae `cobros_ant` y `de_anteriores`: los anticipos y
     * abonos que llegaron en el periodo sobre ventas de antes, de esa área.
     * Un área que en el periodo SOLO recibió eso —ninguna venta nueva— se
     * agrega al final con lo demás en cero: si no, ese dinero no salía en
     * ningún lado de la tabla.
     *
     * @param bool $conAnteriores  false = solo las ventas del periodo.
     */
    public function porArea($desde, $hasta, $conAnteriores = true)
    {
        $filas = $this->porAreaVentas($desde, $hasta);
        if (!$conAnteriores) return $filas;

        $ant = [];
        foreach ($this->anterioresPorArea($desde, $hasta) as $x) $ant[$x['area']] = $x;

        foreach ($filas as $i => $f) {
            $x = $ant[$f['area']] ?? null;
            $filas[$i]['cobros_ant']    = $x ? (int)$x['cobros'] : 0;
            $filas[$i]['de_anteriores'] = $x ? round((float)$x['monto'], 2) : 0.0;
            unset($ant[$f['area']]);
        }
        foreach ($ant as $area => $x) {
            $filas[] = ['area' => $area, 'ventas' => 0, 'vendido' => 0, 'cobrado' => 0,
                        'gastos' => 0, 'comisiones' => 0,
                        'cobros_ant'    => (int)$x['cobros'],
                        'de_anteriores' => round((float)$x['monto'], 2)];
        }
        return $filas;
    }

    /** Las ventas del periodo por área (sin lo de ventas anteriores). */
    private function porAreaVentas($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        // EL ÁREA SALE DEL PRODUCTO, NO DE LA VENTA NI DEL CLIENTE.
        //
        // `ventas.area_nombre` guarda UN área por venta, y eso está mal
        // en cuanto alguien contrata dos cosas: un cliente que pide un
        // juicio y su contabilidad genera una sola venta, y todo el
        // dinero se le cargaba a un área sola. El mismo cliente aparecía
        // en Legal un mes y en Contabilidad el siguiente, según qué se
        // capturó primero.
        //
        // Aquí cada RENGLÓN lleva el área de su producto, y el dinero de
        // la venta —lo cobrado, los gastos y las comisiones— se reparte
        // entre sus renglones en proporción a lo que pesa cada uno.
        //
        // Si un producto no tiene categoría se cae al área de la venta, y
        // si tampoco, a "Sin área": es preferible un renglón honesto que
        // perder la venta del reporte.
        return $this->todos("
            SELECT area,
                   COUNT(DISTINCT venta_id)  AS ventas,
                   ROUND(SUM(vendido),2)     AS vendido,
                   ROUND(SUM(cobrado),2)     AS cobrado,
                   ROUND(SUM(gastos),2)      AS gastos,
                   ROUND(SUM(comisiones),2)  AS comisiones
            FROM (
                SELECT v.id AS venta_id,
                       COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                       -- El peso del renglón dentro de su venta
                       (d.subtotal * 1.0 / NULLIF(tot.suma,0))                   AS peso,
                       -- SE REPARTE `v.total`, NO SE SUMA `d.subtotal`.
                       --
                       -- No siempre son lo mismo: el IVA, los descuentos
                       -- y los ajustes se aplican a la venta, no a sus
                       -- renglones. Sumar los renglones perdía esa
                       -- diferencia —en este histórico, $655.85 que
                       -- salían de un área y no entraban a ninguna—.
                       --
                       -- El renglón solo decide la PROPORCIÓN. Lo que se
                       -- reparte es el total de la venta, así el reporte
                       -- siempre suma lo mismo que las ventas.
                       v.total * (d.subtotal / NULLIF(tot.suma,0))              AS vendido,
                       COALESCE(pg.cobrado,0) * (d.subtotal / NULLIF(tot.suma,0)) AS cobrado,
                       COALESCE(g.gastos,0)   * (d.subtotal / NULLIF(tot.suma,0)) AS gastos,
                       COALESCE(cm.comision,0)* (d.subtotal / NULLIF(tot.suma,0)) AS comisiones
                FROM venta_detalles d
                INNER JOIN ventas v   ON v.id = d.venta_id
                LEFT  JOIN productos p ON p.id = d.producto_id
                LEFT  JOIN categorias cat ON cat.id = p.categoria_id
                INNER JOIN ( SELECT venta_id, SUM(subtotal) suma
                             FROM venta_detalles GROUP BY venta_id ) tot
                       ON tot.venta_id = v.id
                LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                            WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
                LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                            WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                            GROUP BY venta_id ) g ON g.venta_id = v.id
                LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                            GROUP BY venta_id ) cm ON cm.venta_id = v.id
                WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            ) x
            GROUP BY area ORDER BY cobrado DESC", [$a, $b]);
    }

    public function porServicio($desde, $hasta, $tope = 12)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.nombre, p.codigo,
                   COUNT(*) AS veces,
                   SUM(vd.subtotal) AS facturado
            FROM venta_detalles vd
            INNER JOIN ventas v    ON v.id = vd.venta_id
            INNER JOIN productos p ON p.id = vd.producto_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY p.id, p.nombre, p.codigo
            ORDER BY facturado DESC LIMIT " . (int)$tope, [$a, $b]);
    }

    public function porMetodo($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(NULLIF(p.metodo_pago,''),'sin método') AS metodo,
                   SUM(p.monto) AS monto, COUNT(*) AS cobros
            FROM venta_pagos p INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            GROUP BY metodo ORDER BY monto DESC", [$a, $b]);
    }

    public function porColaborador($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            -- El area es la del SERVICIO vendido, no el equipo de quien
            -- comisiono: una venta de contabilidad cerrada por alguien
            -- de Administracion es de contabilidad.
            SELECT pc.colaborador_nombre AS nombre,
                   GROUP_CONCAT(DISTINCT COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area')
                       ORDER BY 1 SEPARATOR ', ') AS area,
                   ROUND(SUM(pc.monto),2) AS devengado,
                   COUNT(*) AS ventas
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY pc.colaborador_nombre
            ORDER BY (pc.colaborador_nombre = 'POR ASIGNAR'), devengado DESC", [$a, $b]);
    }

    /**
     * Una VENTA por renglón: las ventas hechas en el periodo.
     *
     * Su "cobrado" es todo lo que se le ha cobrado a cada venta, sin
     * importar cuándo. Lo que ENTRÓ en el periodo —con los abonos de ventas
     * de meses anteriores— está en pagosDelPeriodo().
     *
     * Las canceladas no entran: antes sí, y su total se sumaba al de la
     * pestaña aunque ninguna otra tabla las contara.
     */
    public function detalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT v.id AS venta_id, v.codigo_venta AS folio, DATE(v.fecha) AS fecha,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   -- Mismo criterio que el resumen: el área del producto
                   -- de la línea, no la de la venta.
                   COALESCE(NULLIF((
                       SELECT cat.nombre FROM venta_detalles d2
                       LEFT JOIN productos p2  ON p2.id = d2.producto_id
                       LEFT JOIN categorias cat ON cat.id = p2.categoria_id
                       WHERE d2.venta_id = v.id AND cat.nombre IS NOT NULL
                       GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1
                   ),''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                   v.subtotal, v.iva, v.total,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   v.total - COALESCE(pg.cobrado,0) AS saldo,
                   COALESCE(g.gastos,0) AS gastos,
                   COALESCE(cm.comision,0) AS comision,
                   v.estado
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY venta_id ) cm ON cm.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            ORDER BY v.fecha", [$a, $b]);
    }

    /**
     * UN RENGLÓN POR COBRO: lo que entró en el periodo, venga de la venta
     * que venga.
     *
     * Va por `venta_pagos.fecha_pago`, con los mismos filtros que el
     * "Entraron" de resultado(), así que la suma de `monto` es exactamente
     * esa cifra. Es la tabla que enseña lo que las tablas por fecha de
     * venta no pueden: el anticipo o abono que llegó este mes sobre una
     * venta del mes pasado, y a qué venta pertenece.
     *
     * `posicion` sirve para armar el subfolio de los cobros guardados antes
     * de que existiera (ver Folio::deRespaldo); se cuenta igual que en el
     * detalle de la venta: por fecha y luego por id, con cancelados.
     *
     * `le_falta` es lo que la venta quedó debiendo DESPUÉS DE ESE PAGO, no
     * lo que debe hoy. En un renglón de pago eso es lo que se lee: "pagó
     * tanto y le faltan tanto". El saldo de hoy confundía: en un abono de
     * hace tres semanas enseñaba lo que faltaba después de abonos que
     * todavía no existían cuando se hizo. Se suman los pagos vivos de la
     * misma venta hasta este, en el mismo orden (fecha y luego id).
     */
    public function pagosDelPeriodo($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT p.id, p.folio, p.tipo, p.monto, p.fecha_pago, p.metodo_pago,
                   v.id AS venta_id, v.codigo_venta, v.fecha AS fecha_venta, v.total,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   COALESCE(NULLIF((
                       SELECT cat.nombre FROM venta_detalles d2
                       LEFT JOIN productos p2  ON p2.id = d2.producto_id
                       LEFT JOIN categorias cat ON cat.id = p2.categoria_id
                       WHERE d2.venta_id = v.id AND cat.nombre IS NOT NULL
                       GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1
                   ),''), NULLIF(v.area_nombre,''), 'Sin área') AS area,
                   ROUND(v.total - (
                       SELECT COALESCE(SUM(p4.monto),0) FROM venta_pagos p4
                       WHERE p4.venta_id = p.venta_id AND p4.cancelado = 0
                         AND (p4.fecha_pago < p.fecha_pago
                              OR (p4.fecha_pago = p.fecha_pago AND p4.id <= p.id))
                   ), 2) AS le_falta,
                   CASE WHEN p.folio IS NULL OR p.folio = '' THEN (
                       SELECT COUNT(*) FROM venta_pagos p3
                       WHERE p3.venta_id = p.venta_id
                         AND (p3.fecha_pago < p.fecha_pago
                              OR (p3.fecha_pago = p.fecha_pago AND p3.id <= p.id))
                   ) END AS posicion
            FROM venta_pagos p
            INNER JOIN ventas v  ON v.id = p.venta_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            ORDER BY p.fecha_pago, p.id", [$a, $b]);
    }

    /**
     * Qué compró cada cliente y qué debe.
     *
     * El área es la de mayor peso en dinero entre lo que contrató, no un
     * dato capturado: un cliente que pidió un juicio y su contabilidad
     * pertenece a las dos, y lo honesto es decir cuál pesa más.
     */
    public function porCliente($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT COALESCE(c.nombre,'Público general') AS cliente,
                   (SELECT cat.nombre
                    FROM venta_detalles d2
                    INNER JOIN ventas v2    ON v2.id = d2.venta_id
                    LEFT  JOIN productos p2 ON p2.id = d2.producto_id
                    LEFT  JOIN categorias cat ON cat.id = p2.categoria_id
                    WHERE v2.cliente_id = v.cliente_id AND v2.estado <> 'cancelada'
                      AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                    GROUP BY cat.nombre ORDER BY SUM(d2.subtotal) DESC LIMIT 1) AS area,
                   COUNT(DISTINCT v.id)        AS compras,
                   COALESCE(SUM(v.total),0)    AS vendido,
                   COALESCE(SUM(pg.cobrado),0) AS cobrado
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY v.cliente_id, cliente
            ORDER BY vendido DESC", [$a, $b]);
    }

    /**
     * Lo que falta por cobrar.
     *
     * De lo más viejo a lo más nuevo: una deuda de noventa días necesita
     * otra conversación que una de tres, y ordenarla por monto esconde
     * justo las que llevan más tiempo.
     */
    public function cobranza($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT v.id AS venta_id, v.codigo_venta, v.fecha,
                   COALESCE(c.nombre,'Público general') AS cliente,
                   v.total,
                   COALESCE(pg.cobrado,0) AS cobrado,
                   ROUND(v.total - COALESCE(pg.cobrado,0), 2) AS saldo,
                   DATEDIFF(CURDATE(), COALESCE(pg.ultimo, v.fecha)) AS dias
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado, MAX(fecha_pago) ultimo
                        FROM venta_pagos WHERE cancelado = 0 GROUP BY venta_id ) pg
                   ON pg.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
              AND v.total - COALESCE(pg.cobrado,0) > 0.01
            ORDER BY dias DESC, saldo DESC", [$a, $b]);
    }

    /** Lo cobrado día por día. */
    public function porDia($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT DATE(p.fecha_pago) AS dia,
                   COUNT(*) AS pagos,
                   COALESCE(SUM(p.monto),0) AS cobrado
            FROM venta_pagos p
            INNER JOIN ventas v ON v.id = p.venta_id
            WHERE p.cancelado = 0 AND v.estado <> 'cancelada'
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            GROUP BY dia ORDER BY dia", [$a, $b]);
    }

    /**
     * El detalle, UN RENGLON POR LINEA DE VENTA.
     *
     * POR QUE POR LINEA Y NO POR VENTA
     *
     * Para poner la columna "Producto" hace falta saber de cual se
     * habla, y una venta puede tener varios. Por venta habria que
     * amontonarlos en una celda, y entonces no se puede filtrar ni
     * sumar por producto.
     *
     * El dinero de la venta se reparte entre sus lineas segun lo que
     * pesa cada una, igual que en el reporte por area. Asi la suma de
     * los renglones sigue dando el total de las ventas.
     *
     * LAS DOS AREAS, Y POR QUE VAN LAS DOS
     *
     *   area_servicio  de que fue el trabajo (la categoria del producto)
     *   area_origen    de donde salio la venta (el area escrita en ella)
     *
     * Una venta hecha en Administracion que incluye un servicio
     * contable pertenece a las dos: a Administracion por origen, a
     * Contabilidad por trabajo. No hay que elegir una; hay que poder
     * agrupar por cualquiera de las dos.
     *
     * Y dentro de contabilidad se separa persona fisica de moral, que
     * es la division que lleva la oficina.
     */
    public function detallePorLinea($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);

        // El especialista llega con la migracion 10, que se aplica al
        // ENTRAR. Quien ya tenia sesion abierta al actualizar todavia
        // no la tiene, y el reporte reventaba con 'Unknown column'.
        //
        // Un reporte no puede depender de que alguien haya vuelto a
        // entrar: si la columna no esta, se devuelve 'Sin asignar' y
        // todo lo demas sigue funcionando.
        $hayEsp = $this->hayColumna('ventas', 'especialista_nombre');
        $esp = $hayEsp
             ? "COALESCE(NULLIF(TRIM(v.especialista_nombre),''), 'Sin asignar')"
             : "'Sin asignar'";

        return $this->todos("
            SELECT v.id                                    AS venta_id,
                   v.codigo_venta                          AS folio,
                   v.fecha,
                   COALESCE(cl.nombre, 'Publico general')  AS cliente,
                   COALESCE(NULLIF(cat.nombre,''), NULLIF(v.area_nombre,''), 'Sin area')
                                                           AS area_servicio,
                   COALESCE(NULLIF(v.area_nombre,''), NULLIF(cat.nombre,''), 'Sin area')
                                                           AS area_origen,
                   -- Dentro de contabilidad: fisica o moral.
                   -- Se mira el nombre del servicio, que es lo unico que
                   -- lo dice hoy. 'PF' y 'PM' cuentan: asi se escriben
                   -- varios, y buscar solo 'FISICA' los dejaba fuera.
                   CASE
                     WHEN UPPER(COALESCE(cat.nombre,'')) NOT LIKE '%CONTAB%' THEN NULL
                     WHEN UPPER(p.nombre) LIKE '%MORAL%'
                       OR UPPER(p.nombre) REGEXP '(^| )PM( |$)'      THEN 'Personas morales'
                     WHEN UPPER(p.nombre) LIKE '%FISICA%'
                       OR UPPER(p.nombre) LIKE '%FÍSICA%'
                       OR UPPER(p.nombre) REGEXP '(^| )PF( |$)'      THEN 'Personas fisicas'
                     WHEN CHAR_LENGTH(TRIM(COALESCE(cl.rfc,''))) = 12 THEN 'Personas morales'
                     WHEN CHAR_LENGTH(TRIM(COALESCE(cl.rfc,''))) = 13 THEN 'Personas fisicas'
                     ELSE 'Sin clasificar'
                   END                                     AS tipo_persona,
                   COALESCE(p.nombre, 'Sin producto')      AS producto,
                   COALESCE(NULLIF(TRIM(p.descripcion),''), '')  AS producto_desc,
                   {$esp}                                  AS especialista,
                   COALESCE(NULLIF(TRIM(v.descripcion),''), '')  AS nota_venta,
                   d.cantidad,
                   d.precio_unitario,
                   -- El peso de la linea dentro de su venta
                   ROUND(v.total     * (d.subtotal / NULLIF(tot.suma,0)), 2) AS total_linea,
                   ROUND(COALESCE(pg.cobrado,0) * (d.subtotal / NULLIF(tot.suma,0)), 2) AS cobrado,
                   ROUND((v.total - COALESCE(pg.cobrado,0))
                         * (d.subtotal / NULLIF(tot.suma,0)), 2) AS saldo,
                   ROUND(COALESCE(g.gastos,0)   * (d.subtotal / NULLIF(tot.suma,0)), 2) AS gastos,
                   ROUND(COALESCE(cm.comision,0)* (d.subtotal / NULLIF(tot.suma,0)), 2) AS comision,
                   v.metodo_pago                           AS metodo,
                   suc.nombre                              AS sucursal
            FROM venta_detalles d
            INNER JOIN ventas v     ON v.id = d.venta_id
            LEFT  JOIN productos p  ON p.id = d.producto_id
            LEFT  JOIN categorias cat ON cat.id = p.categoria_id
            LEFT  JOIN clientes cl  ON cl.id = v.cliente_id
            LEFT  JOIN sucursales suc ON suc.id = v.sucursal_id
            INNER JOIN ( SELECT venta_id, SUM(subtotal) suma
                         FROM venta_detalles GROUP BY venta_id ) tot ON tot.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) cobrado FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) pg ON pg.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) gastos FROM gastos
                        WHERE tipo = 'manual' AND categoria <> 'Costo de venta'
                        GROUP BY venta_id ) g ON g.venta_id = v.id
            LEFT JOIN ( SELECT venta_id, SUM(monto) comision FROM pago_comisiones
                        GROUP BY venta_id ) cm ON cm.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            ORDER BY area_servicio, tipo_persona, v.fecha DESC, v.codigo_venta",
            [$a, $b]);
    }

    /**
     * Existe esa columna?
     *
     * Se consulta una vez por peticion y se recuerda: preguntarlo en
     * cada reporte seria una consulta mas por cada tabla.
     */
    protected function hayColumna($tabla, $columna)
    {
        static $visto = [];
        $k = $tabla . '.' . $columna;
        if (isset($visto[$k])) return $visto[$k];
        try {
            $visto[$k] = (bool)$this->valor("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$tabla, $columna]);
        } catch (\Throwable $e) { $visto[$k] = false; }
        return $visto[$k];
    }

    /**
     * Cada comision, con el porque al lado.
     *
     * UN RENGLON POR COMISION, NO POR COLABORADOR
     *
     * El resumen dice que Gisselle gano $7,336.91. Esto dice de donde:
     * de que venta, de que pago, con que porcentaje y sobre cuanto.
     *
     * Las tres columnas que explican el numero son el porcentaje, lo
     * cobrado en ese pago y la proporcion: una comision del 30% sobre un
     * anticipo del 25% no da el 30% de la venta, da el 30% de ese
     * cuarto. Sin esas tres juntas, el monto parece sacado de la nada y
     * el colaborador llega a preguntar.
     */
    public function comisionesDetalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT pc.colaborador_nombre              AS colaborador,
                   v.id                               AS venta_id,
                   v.codigo_venta                     AS folio,
                   COALESCE(cl.nombre,'Publico general') AS cliente,
                   v.fecha                            AS fecha_venta,
                   p.fecha_pago,
                   p.tipo                             AS tipo_pago,
                   p.metodo_pago                      AS metodo,
                   -- El area del SERVICIO, igual que en el resto
                   COALESCE((
                       SELECT cat.nombre
                       FROM venta_detalles d
                       LEFT JOIN productos pr   ON pr.id = d.producto_id
                       LEFT JOIN categorias cat ON cat.id = pr.categoria_id
                       WHERE d.venta_id = v.id AND cat.nombre IS NOT NULL AND cat.nombre <> ''
                       GROUP BY cat.nombre ORDER BY SUM(d.subtotal) DESC LIMIT 1
                   ), NULLIF(v.area_nombre,''), 'Sin area') AS area,
                   pc.area_nombre                     AS equipo,
                   v.total                            AS total_venta,
                   p.monto                            AS cobrado,
                   pc.porcentaje,
                   pc.proporcion_cobrada              AS proporcion,
                   pc.monto                           AS comision
            FROM pago_comisiones pc
            INNER JOIN venta_pagos p ON p.id = pc.pago_id
            INNER JOIN ventas v      ON v.id = pc.venta_id
            LEFT  JOIN clientes cl   ON cl.id = v.cliente_id
            WHERE v.estado <> 'cancelada' AND p.cancelado = 0
              AND p.fecha_pago >= ? AND p.fecha_pago < ?
            ORDER BY pc.colaborador_nombre, p.fecha_pago, v.codigo_venta",
            [$a, $b]);
    }
}
