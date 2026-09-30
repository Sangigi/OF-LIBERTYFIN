<?php
namespace LibertyFin\Servicio;

use PDO;

/**
 * Migraciones por empresa.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * LibertyFin crea una base de datos por empresa, y el esquema vive como
 * 36 CREATE TABLE escritos dentro de registroEmpresa.php. Cada columna
 * que agrega una versión nueva del sistema hay que agregarla a mano en
 * TODAS las bases existentes, y además acordarse de meterla en ese
 * archivo para las que nazcan después.
 *
 * Nadie se acuerda. Y el síntoma es el peor posible: una empresa nueva
 * estrena el sistema y le falta una columna, con un error que el resto
 * de los clientes no tiene.
 *
 * CÓMO FUNCIONA
 *
 * Cada migración tiene un número y es IDEMPOTENTE: correrla dos veces
 * no hace nada la segunda. La versión aplicada se guarda en
 * sistema_config de cada empresa, así que cada base sabe dónde va.
 *
 * Se ejecutan solas al entrar, si la base está atrasada. En una base al
 * día el costo es una consulta.
 */
final class Migraciones
{
    /** Súbelo al agregar una migración nueva. */
    const VERSION = 4;

    public static function versionDe(PDO $db)
    {
        try {
            $st = $db->query("SELECT valor FROM sistema_config WHERE clave = 'esquema.version'");
            return (int)($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;   // sin tabla de config, la base está en cero
        }
    }

    public static function alDia(PDO $db) { return self::versionDe($db) >= self::VERSION; }

    /**
     * Aplica lo que falte. Devuelve qué se corrió.
     * Nunca lanza: una migración que falla no debe dejar a nadie fuera
     * del sistema. Se anota y se sigue.
     */
    public static function aplicar(PDO $db)
    {
        $desde = self::versionDe($db);
        $hecho = [];
        for ($v = $desde + 1; $v <= self::VERSION; $v++) {
            $metodo = 'v' . $v;
            if (!method_exists(__CLASS__, $metodo)) continue;
            try {
                self::$metodo($db);
                self::marcar($db, $v);
                $hecho[] = $v;
            } catch (\Throwable $e) {
                error_log('[LibertyFin] migración ' . $v . ': ' . $e->getMessage());
                break;   // no seguir: la siguiente puede depender de esta
            }
        }
        return $hecho;
    }

    private static function marcar(PDO $db, $v)
    {
        $db->prepare("
            INSERT INTO sistema_config (clave, valor, actualizado) VALUES ('esquema.version',?,NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado = NOW()
        ")->execute([(string)$v]);
    }

    /** ¿Existe la columna? Evita el error 1060 al correr dos veces. */
    private static function hayColumna(PDO $db, $tabla, $columna)
    {
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$tabla, $columna]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function hayTabla(PDO $db, $tabla)
    {
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $st->execute([$tabla]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function hayIndice(PDO $db, $tabla, $indice)
    {
        if (!self::hayTabla($db, $tabla)) return true;   // nada que hacer
        $st = $db->prepare("
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $st->execute([$tabla, $indice]);
        return (int)$st->fetchColumn() > 0;
    }

    // ══════════════════════════════════════════════════════════════
    // LAS MIGRACIONES
    // ══════════════════════════════════════════════════════════════

    /** 1 · La tabla de configuración. Todo lo demás depende de ella. */
    private static function v1(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS sistema_config (
                clave VARCHAR(80) NOT NULL PRIMARY KEY,
                valor TEXT NULL,
                actualizado DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** 2 · Área del cliente. Antes salía de su última venta y no se editaba. */
    private static function v2(PDO $db)
    {
        if (!self::hayColumna($db, 'clientes', 'area')) {
            $db->exec("ALTER TABLE clientes ADD COLUMN area VARCHAR(60) NULL DEFAULT NULL AFTER rfc");
        }
    }

    /** 3 · Foto de perfil. */
    private static function v3(PDO $db)
    {
        if (!self::hayColumna($db, 'usuarios', 'foto')) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN foto VARCHAR(200) NULL DEFAULT NULL AFTER email");
        }
        if (!self::hayColumna($db, 'productos', 'imagen')) {
            $db->exec("ALTER TABLE productos ADD COLUMN imagen VARCHAR(200) NULL DEFAULT NULL");
        }
    }

    /**
     * 4 · Índices.
     *
     * En una base recién creada no se notan. En una con años de ventas
     * son la diferencia entre un listado instantáneo y uno que recorre
     * la tabla entera. Ponerlos desde el día uno evita descubrirlo tarde.
     */
    private static function v4(PDO $db)
    {
        $indices = [
            ['ventas','ix_v_fecha','`fecha`'],
            ['ventas','ix_v_fecha_estado','`fecha`,`estado`'],
            ['ventas','ix_v_cliente','`cliente_id`'],
            ['ventas','ix_v_codigo','`codigo_venta`'],
            ['venta_pagos','ix_vp_venta_cancel','`venta_id`,`cancelado`'],
            ['venta_pagos','ix_vp_fecha_cancel','`fecha_pago`,`cancelado`'],
            ['venta_detalles','ix_vd_venta','`venta_id`'],
            ['venta_detalles','ix_vd_producto','`producto_id`'],
            ['venta_comisiones','ix_vc_venta_cancel','`venta_id`,`cancelada`'],
            ['pago_comisiones','ix_pc_venta','`venta_id`'],
            ['pago_comisiones','ix_pc_comision','`venta_comision_id`'],
            ['gastos','ix_g_venta_tipo','`venta_id`,`tipo`'],
            ['productos','ix_p_activo','`activo`'],
            ['clientes','ix_c_nombre','`nombre`(40)'],
        ];
        foreach ($indices as $i) {
            list($tabla, $nombre, $cols) = $i;
            if (self::hayIndice($db, $tabla, $nombre)) continue;
            try {
                $db->exec("ALTER TABLE `$tabla` ADD INDEX `$nombre` ($cols)");
            } catch (\Throwable $e) {
                // Una tabla que no existe en esta empresa no es un error.
                error_log('[LibertyFin] índice ' . $tabla . '.' . $nombre . ': ' . $e->getMessage());
            }
        }
    }

    /** Lo que hace cada versión, para mostrarlo en Mantenimiento. */
    const DESCRIPCIONES = [
        1 => 'Tabla de configuración por empresa',
        2 => 'Área del cliente',
        3 => 'Foto de perfil e imagen del servicio',
        4 => 'Índices de rendimiento',
    ];
}
