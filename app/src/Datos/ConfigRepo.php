<?php
namespace LibertyFin\Datos;

/**
 * Configuración por empresa, en `sistema_config`.
 *
 * Aquí viven las decisiones que cambian de una empresa a otra y que no
 * son credenciales: qué secciones están encendidas, sobre todo.
 *
 * Las credenciales NO van aquí: van en config/integraciones.php, fuera
 * de la base y fuera del repositorio. Una contraseña en una tabla la ve
 * cualquiera con acceso a la base, y todo respaldo se la lleva.
 */
final class ConfigRepo extends Repo
{
    private static $cache = null;

    private function asegurar()
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS sistema_config (
                    clave VARCHAR(80) NOT NULL PRIMARY KEY,
                    valor TEXT NULL,
                    actualizado DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] sistema_config: ' . $e->getMessage());
        }
    }

    public function todo()
    {
        if (self::$cache !== null) return self::$cache;
        try {
            $f = $this->todos("SELECT clave, valor FROM sistema_config");
            self::$cache = array_column($f, 'valor', 'clave');
        } catch (\Throwable $e) { self::$cache = []; }
        return self::$cache;
    }

    public function valorDe($clave, $porDefecto = null)
    {
        $t = $this->todo();
        return array_key_exists($clave, $t) ? $t[$clave] : $porDefecto;
    }

    public function guardar($clave, $valor)
    {
        $this->asegurar();
        $this->db->prepare("
            INSERT INTO sistema_config (clave, valor, actualizado) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado = NOW()
        ")->execute([$clave, (string)$valor]);
        self::$cache = null;
        return true;
    }

    // ── Secciones encendidas o apagadas ──

    /**
     * Las que se pueden apagar. Panel, Caja y Ventas NO están aquí:
     * apagarlas dejaría un sistema donde no se puede trabajar, y el
     * usuario pensaría que se rompió.
     */
    const APAGABLES = [
        'cobranza'   => 'Cobranza',
        'corte'      => 'Corte de caja',
        'comisiones' => 'Comisiones',
        'gastos'     => 'Gastos',
        'reportes'   => 'Reportes',
        'recargas'   => 'Recargas',
    ];

    public function seccionActiva($clave)
    {
        if (!isset(self::APAGABLES[$clave])) return true;   // no se puede apagar
        return $this->valorDe('seccion.' . $clave, '1') !== '0';
    }

    public function secciones()
    {
        $r = [];
        foreach (self::APAGABLES as $k => $rotulo) {
            $r[$k] = ['rotulo' => $rotulo, 'activa' => $this->seccionActiva($k)];
        }
        return $r;
    }

    public function alternarSeccion($clave)
    {
        if (!isset(self::APAGABLES[$clave])) {
            throw new \InvalidArgumentException('Esa sección no se puede apagar');
        }
        return $this->guardar('seccion.' . $clave, $this->seccionActiva($clave) ? '0' : '1');
    }

    // ── Diagnóstico ──

    /** Qué tan sana está la base. Lo que soporte necesita ver primero. */
    public function diagnostico()
    {
        $r = [];
        $r['php']     = PHP_VERSION;
        $r['mysql']   = (string)$this->valor("SELECT VERSION()");
        $r['zona_php']= date_default_timezone_get() . ' (UTC' . date('P') . ')';
        $r['zona_sql']= (string)$this->valor("SELECT @@session.time_zone");
        $r['hora_php']= date('Y-m-d H:i:s');
        $r['hora_sql']= (string)$this->valor("SELECT NOW()");

        // Las señales que de verdad avisan de un problema
        $r['ventas_sin_area'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas
            WHERE estado <> 'cancelada' AND (area_nombre IS NULL OR area_nombre = '')");
        $r['ventas_desfasadas'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas
            WHERE codigo_venta REGEXP '^[0-9]{14}$'
              AND ABS(TIMESTAMPDIFF(MINUTE, STR_TO_DATE(codigo_venta,'%Y%m%d%H%i%s'), fecha)) > 5");
        $r['cobrado_mayor_total'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas v
            LEFT JOIN ( SELECT venta_id, SUM(monto) c FROM venta_pagos
                        WHERE cancelado = 0 GROUP BY venta_id ) p ON p.venta_id = v.id
            WHERE v.estado <> 'cancelada' AND COALESCE(p.c,0) - v.total > 0.01");
        $r['comisiones_sin_dueno'] = (int)$this->valor("
            SELECT COUNT(*) FROM venta_comisiones
            WHERE cancelada = 0 AND colaborador_nombre = 'POR ASIGNAR'");
        $r['ventas_sin_cliente'] = (int)$this->valor("
            SELECT COUNT(*) FROM ventas WHERE estado <> 'cancelada' AND cliente_id IS NULL");
        $r['cajas_abiertas'] = (int)$this->valor("
            SELECT COUNT(*) FROM caja WHERE estado = 'abierta'");

        $r['tablas'] = $this->todos("
            SELECT TABLE_NAME AS tabla, TABLE_ROWS AS filas,
                   ROUND((DATA_LENGTH + INDEX_LENGTH)/1024) AS kb,
                   ROUND(INDEX_LENGTH/1024) AS ikb
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 12");
        return $r;
    }
}
