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
    const VERSION = 11;

    /**
     * La versión vive en `lf_ajustes`, no en `sistema_config`.
     *
     * `sistema_config` ya existía en el esquema del sistema anterior con
     * columnas fijas —logo, color, datos fiscales— y no tiene forma de
     * clave-valor. Meter ahí la versión obligaba a agregarle una columna
     * más a una tabla que no es nuestra.
     */
    public static function versionDe(PDO $db)
    {
        try {
            $st = $db->query("SELECT valor FROM lf_ajustes WHERE clave = 'esquema.version'");
            return (int)($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;   // sin tabla, la base está en cero
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
            INSERT INTO lf_ajustes (clave, valor, actualizado) VALUES ('esquema.version',?,NOW())
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

    /**
     * 1 · La tabla clave-valor de este sistema.
     *
     * Se llama `lf_ajustes` y no `sistema_config` a propósito: esa ya
     * existe con otra forma. Un nombre propio evita que dos esquemas
     * peleen por la misma tabla.
     */
    private static function v1(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_ajustes (
                clave VARCHAR(80) NOT NULL PRIMARY KEY,
                valor TEXT NULL,
                actualizado DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Y el renglón único de sistema_config, si la empresa no lo trae.
        try {
            $n = (int)$db->query("SELECT COUNT(*) FROM sistema_config")->fetchColumn();
            if ($n === 0) $db->exec("INSERT INTO sistema_config (nombre_empresa) VALUES ('Mi Empresa')");
        } catch (\Throwable $e) { /* la empresa no tiene esa tabla: nada que hacer */ }
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

    /**
     * 5 · Marca de guía vista.
     *
     * No va en localStorage a propósito: eso es por NAVEGADOR, no por
     * usuario. Dos personas de la misma empresa en la misma computadora
     * compartirían el estado y la segunda nunca vería la guía; y la misma
     * persona desde su celular la volvería a ver.
     *
     * Es DATETIME y no un booleano: saber CUÁNDO la vio permite responder
     * "¿esta empresa se atoró el primer día?" sin otra columna después.
     */
    private static function v5(PDO $db)
    {
        if (!self::hayColumna($db, 'usuarios', 'guia_vista_en')) {
            $db->exec("ALTER TABLE usuarios ADD COLUMN guia_vista_en DATETIME NULL DEFAULT NULL");
            // Las cuentas que YA existen no deberían recibir una guía de
            // bienvenida: llevan tiempo usando el sistema y sería ruido.
            $db->exec("UPDATE usuarios SET guia_vista_en = NOW() WHERE guia_vista_en IS NULL");
        }
    }

    /**
     * 6 · Los roles nuevos en el ENUM de `usuarios.rol`.
     *
     * La columna era ENUM('admin','cajero','inventario'). MySQL NO falla
     * al escribir un valor fuera de la lista: guarda cadena vacía y sigue.
     * Por eso asignar "soporte" parecía funcionar y el usuario quedaba sin
     * rol y sin permisos, sin un solo error en ningún lado.
     *
     * Es el peor tipo de fallo: silencioso y con apariencia de éxito.
     *
     * Se agregan los valores nuevos conservando los tres que ya existían,
     * para no invalidar a nadie.
     */
    private static function v6(PDO $db)
    {
        try {
            $st = $db->query("
                SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                  AND COLUMN_NAME = 'rol'");
            $tipo = (string)$st->fetchColumn();
            // Si ya no es ENUM (alguien lo pasó a VARCHAR), no hay nada que hacer.
            if (stripos($tipo, 'enum') !== 0) return;
            if (stripos($tipo, "'soporte'") !== false) return;

            $db->exec("
                ALTER TABLE usuarios MODIFY COLUMN rol
                ENUM('admin','cajero','inventario','soporte','superadmin','validador')
                NOT NULL DEFAULT 'cajero'");
        } catch (\Throwable $e) {
            error_log('[LibertyFin] migración 6: ' . $e->getMessage());
        }
    }

    /**
     * 7 · La bitácora.
     *
     * Vive en la base de CADA empresa, no en la principal: registra
     * cambios sobre datos de esa empresa, y juntarlos todos obligaría a
     * cargar el id de empresa en cada renglón y a filtrar siempre por él.
     * Además, si una empresa se va, su bitácora se va con ella.
     *
     * El índice por fecha es lo que hace usable la pantalla: sin él,
     * ver "los últimos 50 movimientos" recorre la tabla entera, y esta
     * tabla solo crece.
     */
    private static function v7(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_auditoria (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                accion VARCHAR(40) NOT NULL,
                sobre VARCHAR(200) NULL,
                antes TEXT NULL,
                despues TEXT NULL,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                usuario_rol VARCHAR(40) NULL,
                ip VARCHAR(45) NULL,
                creado_en DATETIME NOT NULL,
                KEY ix_au_fecha (creado_en),
                KEY ix_au_accion (accion, creado_en),
                KEY ix_au_usuario (usuario_id, creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 8 · El catálogo de Emida y sus transacciones.
     *
     * Se crean aquí y no al vuelo porque son parte del esquema: una
     * empresa que vende recargas las necesita desde el primer día, y
     * crearlas en el primer uso significa que el primer uso es el más
     * lento y el más propenso a fallar.
     */
    private static function v8(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_emida_productos (
                producto_id VARCHAR(30) NOT NULL PRIMARY KEY,
                nombre VARCHAR(220) NOT NULL,
                categoria VARCHAR(80) NULL,
                carrier VARCHAR(80) NULL,
                comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                monto DECIMAL(12,2) NOT NULL DEFAULT 0,
                monto_min DECIMAL(12,2) NOT NULL DEFAULT 0,
                monto_max DECIMAL(12,2) NOT NULL DEFAULT 0,
                tipo VARCHAR(12) NOT NULL DEFAULT 'directa',
                activo TINYINT(1) NOT NULL DEFAULT 1,
                actualizado DATETIME NULL,
                KEY ix_ep_cat (categoria),
                KEY ix_ep_carrier (carrier),
                KEY ix_ep_activo (activo, categoria)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_emida_transacciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sales_id VARCHAR(30) NOT NULL,
                producto_id VARCHAR(30) NOT NULL,
                producto_nombre VARCHAR(220) NULL,
                cuenta VARCHAR(40) NOT NULL,
                monto DECIMAL(12,2) NOT NULL,
                comision DECIMAL(10,2) NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                folio_proveedor VARCHAR(80) NULL,
                codigo VARCHAR(10) NULL,
                h2h VARCHAR(10) NULL,
                mensaje VARCHAR(300) NULL,
                venta_id INT NULL,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                creado_en DATETIME NOT NULL,
                UNIQUE KEY ix_et_sales (sales_id),
                KEY ix_et_fecha (creado_en),
                KEY ix_et_estado (estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 9 · Ligas de pago.
     *
     * Aparte de `venta_pagos` a propósito: un renglón ahí significa
     * "entró dinero" y lo suman el corte, los reportes y las comisiones.
     * Una liga significa "le pedimos al cliente que pague", que a veces
     * no termina en nada.
     */
    private static function v9(PDO $db)
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS lf_ligas_pago (
                id INT AUTO_INCREMENT PRIMARY KEY,
                referencia VARCHAR(20) NOT NULL,
                venta_id INT NULL,
                cliente_nombre VARCHAR(200) NULL,
                monto DECIMAL(12,2) NOT NULL,
                metodo VARCHAR(16) NOT NULL DEFAULT 'todos',
                descripcion VARCHAR(80) NULL,
                liga VARCHAR(500) NULL,
                clabe VARCHAR(30) NULL,
                barras VARCHAR(80) NULL,
                estado VARCHAR(16) NOT NULL DEFAULT 'pendiente',
                vence DATE NULL,
                pagado_en DATETIME NULL,
                pago_id INT NULL,
                pruebas TINYINT(1) NOT NULL DEFAULT 0,
                usuario_id INT NULL,
                usuario_nombre VARCHAR(160) NULL,
                revisado_en DATETIME NULL,
                creado_en DATETIME NOT NULL,
                UNIQUE KEY ix_lp_ref (referencia),
                KEY ix_lp_estado (estado, vence),
                KEY ix_lp_venta (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * 10 · El especialista asignado a la venta.
     *
     * NO es quien la cobró. `usuario_id` ya guarda al cajero que tecleó;
     * esto es quién va a HACER el trabajo. Son distintos casi siempre:
     * recepción cobra, el contador trabaja.
     *
     * Se guarda el id y también el nombre. El id para poder filtrar y
     * enlazar; el nombre porque si esa persona se va de la empresa y su
     * usuario se desactiva, el reporte de hace seis meses tiene que
     * seguir diciendo quién atendió.
     */
    private static function v10(PDO $db)
    {
        foreach ([
            "ALTER TABLE ventas ADD COLUMN especialista_id INT NULL AFTER usuario_id",
            "ALTER TABLE ventas ADD COLUMN especialista_nombre VARCHAR(160) NULL AFTER especialista_id",
            "ALTER TABLE ventas ADD INDEX ix_v_especialista (especialista_id)",
        ] as $sql) {
            try { $db->exec($sql); } catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /**
     * 11 · Lo que traen los avisos de Paga de Todo.
     *
     * Hasta ahora un cobro solo guardaba qué se le pidió al proveedor.
     * Cuando el proveedor contesta —y sobre todo cuando avisa que ya
     * pagaron— trae datos que había que poder guardar:
     *
     *   transaccion   su folio de la operación. Es lo que hace que un
     *                 reintento no abone dos veces: si ya entró con ese
     *                 número, se contesta que sí y no se toca nada.
     *   autorizacion  el número que la tienda imprime en el ticket. Es
     *                 por donde pregunta el cliente cuando reclama.
     *   pagado_monto  cuánto pagó DE VERDAD. En SPEI el cliente deposita
     *                 lo que quiere, y sin esto no había forma de ver
     *                 que pagó de menos.
     *   imagen        el PNG del código de barras.
     *   formato       el PDF que el proveedor arma con las instrucciones.
     */
    private static function v11(PDO $db)
    {
        if (!self::hayTabla($db, 'lf_ligas_pago')) return;   // nace con todo
        foreach ([
            'imagen'       => "ALTER TABLE lf_ligas_pago ADD COLUMN imagen VARCHAR(500) NULL AFTER barras",
            'formato'      => "ALTER TABLE lf_ligas_pago ADD COLUMN formato VARCHAR(500) NULL AFTER imagen",
            'pagado_monto' => "ALTER TABLE lf_ligas_pago ADD COLUMN pagado_monto DECIMAL(12,2) NULL AFTER pagado_en",
            'transaccion'  => "ALTER TABLE lf_ligas_pago ADD COLUMN transaccion VARCHAR(32) NULL AFTER pagado_monto",
            'autorizacion' => "ALTER TABLE lf_ligas_pago ADD COLUMN autorizacion VARCHAR(32) NULL AFTER transaccion",
        ] as $columna => $sql) {
            if (self::hayColumna($db, 'lf_ligas_pago', $columna)) continue;
            try { $db->exec($sql); } catch (\Throwable $e) { /* ya existe */ }
        }
        if (!self::hayIndice($db, 'lf_ligas_pago', 'ix_lp_trans')) {
            try { $db->exec("ALTER TABLE lf_ligas_pago ADD INDEX ix_lp_trans (transaccion)"); }
            catch (\Throwable $e) { /* ya existe */ }
        }
    }

    /** Lo que hace cada versión, para mostrarlo en Mantenimiento. */
    const DESCRIPCIONES = [
        1 => 'Tabla de ajustes propia (lf_ajustes)',
        2 => 'Área del cliente',
        3 => 'Foto de perfil e imagen del servicio',
        4 => 'Índices de rendimiento',
        5 => 'Marca de guía de primer uso',
        6 => 'Roles nuevos en la columna usuarios.rol',
        7 => 'Bitácora de cambios (lf_auditoria)',
        8 => 'Catálogo y transacciones de Emida',
        9 => 'Ligas de pago (tarjeta, SPEI, tiendas)',
        10 => 'Especialista asignado a la venta',
        11 => 'Avisos de pago de Paga de Todo (transacción, autorización, comprobante)',
    ];
}
