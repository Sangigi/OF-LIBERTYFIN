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

    /** El resultado del periodo, línea por línea. */
    public function resultado($desde, $hasta)
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

        return [
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
            $r  = $this->resultado($m . '-01', date('Y-m-t', strtotime($m . '-01')));
            $r['mes'] = $m;
            $salida[] = $r;
        }
        return $salida;
    }

    public function porArea($desde, $hasta)
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
                   COUNT(DISTINCT pc.venta_id) AS ventas
            FROM pago_comisiones pc INNER JOIN ventas v ON v.id = pc.venta_id
            WHERE v.estado <> 'cancelada' AND v.fecha >= ? AND v.fecha < ?
            GROUP BY pc.colaborador_nombre
            ORDER BY (pc.colaborador_nombre = 'POR ASIGNAR'), devengado DESC", [$a, $b]);
    }

    /** El detalle en crudo, para exportar. */
    public function detalle($desde, $hasta)
    {
        list($a, $b) = $this->rango($desde, $hasta);
        return $this->todos("
            SELECT v.codigo_venta AS folio, DATE(v.fecha) AS fecha,
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
            WHERE v.fecha >= ? AND v.fecha < ?
            ORDER BY v.fecha", [$a, $b]);
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
            SELECT v.codigo_venta, v.fecha,
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
            SELECT v.codigo_venta                          AS folio,
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
}
