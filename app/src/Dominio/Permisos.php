<?php
namespace LibertyFin\Dominio;

/**
 * Quién puede hacer qué.
 *
 * Una sola matriz. El router, el menú y los controladores leen de aquí,
 * así que no puede pasar lo del sistema anterior: que el enlace esté
 * escondido pero la URL siga funcionando si alguien la escribe.
 *
 * Los permisos se nombran por lo que la persona HACE, no por la pantalla
 * donde lo hace. "cobrar" es cobrar, exista o no una sección llamada
 * Caja. Así, mover algo de lugar no obliga a repensar los permisos.
 */
final class Permisos
{
    /**
     * Los roles que existen de verdad en LibertyFin.
     *
     * No invento ninguno: son los tres que ya usaba el sistema anterior
     * más soporte, que allá entraba por un login aparte. Aquí entra por
     * el mismo: dos puertas al mismo sistema son dos superficies que
     * proteger, dos lugares donde aplicar un cambio y una que siempre
     * se queda atrás.
     */
    const ROLES = [
        'admin' => [
            'rotulo' => 'Administrador',
            'para'   => 'Dueño o gerente. Ve el dinero y configura el sistema.',
        ],
        'cajero' => [
            'rotulo' => 'Cajero',
            'para'   => 'Cobra, abre y cierra su caja. No ve las comisiones de nadie.',
        ],
        'inventario' => [
            'rotulo' => 'Inventario',
            'para'   => 'Mantiene el catálogo y los proveedores. No cobra ni ve el dinero.',
        ],
        'soporte' => [
            'rotulo' => 'Soporte',
            'para'   => 'Mantenimiento y diagnóstico de LibertyFin. Ve todo, no mueve dinero.',
        ],
    ];

    /**
     * La matriz.
     *
     * Dos reglas de fondo:
     *
     *  · Soporte VE todo y no MUEVE nada. Quien entra a arreglar un
     *    problema necesita mirar, no cobrar. Si además pudiera cobrar,
     *    cancelar pagos o asignar comisiones, no habría forma de saber
     *    si un descuadre lo causó la empresa o quien vino a ayudar.
     *
     *  · Cajero no ve comisiones. No es desconfianza: es que el importe
     *    que cobra alguien más no es asunto suyo, y tenerlo a la vista
     *    en la pantalla donde atiende clientes es una fuga de información
     *    que nadie pidió.
     */
    const MATRIZ = [
        // ── Ver ──
        'ver.panel'        => ['admin','cajero','inventario','soporte'],
        'ver.ventas'       => ['admin','cajero','soporte'],
        'ver.clientes'     => ['admin','cajero','soporte'],
        'ver.cobranza'     => ['admin','soporte'],
        'ver.servicios'    => ['admin','cajero','inventario','soporte'],
        'ver.comisiones'   => ['admin','soporte'],
        'ver.gastos'       => ['admin','inventario','soporte'],
        'ver.reportes'     => ['admin','soporte'],
        'ver.corte'        => ['admin','cajero','soporte'],
        'ver.recargas'     => ['admin','cajero','soporte'],
        'ver.ajustes'      => ['admin','soporte'],
        'ver.usuarios'     => ['admin','soporte'],
        'ver.mantenimiento'=> ['soporte'],

        // ── Mover dinero ──
        'cobrar'           => ['admin','cajero'],
        'abonar'           => ['admin','cajero'],
        'cancelar.pago'    => ['admin'],
        'abrir.caja'       => ['admin','cajero'],
        'cerrar.caja'      => ['admin','cajero'],
        'vender.recarga'   => ['admin','cajero'],

        // ── Catálogos ──
        'editar.clientes'  => ['admin','cajero'],
        'editar.servicios' => ['admin','inventario'],
        'editar.gastos'    => ['admin','inventario'],
        'borrar.gastos'    => ['admin'],

        // ── Comisiones ──
        'asignar.comision' => ['admin'],
        'quitar.comision'  => ['admin'],

        // ── Configuración ──
        'editar.ajustes'   => ['admin'],
        'editar.usuarios'  => ['admin'],
        'editar.empresa'   => ['admin'],

        // ── Soporte ──
        'secciones'        => ['admin','soporte'],
        'diagnostico'      => ['soporte'],
    ];

    /**
     * El nombre legible de un rol, sin quedarse nunca en blanco.
     *
     * Si en la base hay un rol que esta versión no conoce —porque venía
     * del sistema anterior o porque alguien lo escribió a mano— se
     * muestra el valor crudo. Una celda vacía hace pensar que el dato
     * se perdió; ver "empleado" dice exactamente qué pasa.
     */
    public static function rotulo($rol)
    {
        $crudo = trim((string)$rol);
        if ($crudo === '') return 'sin rol asignado';
        $n = self::normalizar($crudo);
        if (isset(self::ROLES[$n])) {
            // Un rol traducido se muestra con su nombre actual, pero
            // diciendo de dónde viene: si no, nadie entiende por qué
            // "vendedor" abre lo mismo que "cajero".
            return $n === $crudo
                ? self::ROLES[$n]['rotulo']
                : self::ROLES[$n]['rotulo'] . ' (antes ' . $crudo . ')';
        }
        return $crudo;
    }

    /** ¿Es un rol que esta versión conoce? */
    public static function conocido($rol)
    {
        return isset(self::ROLES[self::normalizar($rol)]);
    }

    /**
     * Roles del sistema anterior que ya no existen, y a qué equivalen.
     *
     * Una cuenta con uno de estos seguiría funcionando en vez de quedarse
     * sin permisos sin explicación. Se traduce, no se niega.
     */
    const EQUIVALENCIAS = [
        'supervisor'  => 'admin',
        'vendedor'    => 'cajero',
        'super_admin' => 'admin',
        'empleado'    => 'cajero',
    ];

    /** Traduce un rol viejo al actual. Devuelve el mismo si ya es actual. */
    public static function normalizar($rol)
    {
        $rol = trim((string)$rol);
        if (isset(self::ROLES[$rol])) return $rol;
        return self::EQUIVALENCIAS[$rol] ?? $rol;
    }

    public static function puede($permiso, $rol = null)
    {
        $rol = self::normalizar($rol ?? ($_SESSION['usuario_rol'] ?? ''));
        $lista = self::MATRIZ[$permiso] ?? null;
        // Un permiso que no existe se niega. Escribirlo mal debe cerrar
        // la puerta, no abrirla.
        if ($lista === null) return false;
        return in_array($rol, $lista, true);
    }

    /** Todo lo que puede un rol, para mostrarlo al asignarlo. */
    public static function de($rol)
    {
        $rol = self::normalizar($rol);
        $r = [];
        foreach (self::MATRIZ as $p => $roles) {
            if (in_array($rol, $roles, true)) $r[] = $p;
        }
        return $r;
    }

    /** Un resumen legible del rol, para la pantalla de usuarios. */
    public static function resumen($rol)
    {
        $todos = self::de($rol);
        $ve    = count(array_filter($todos, function ($p) { return strpos($p, 'ver.') === 0; }));
        $hace  = count($todos) - $ve;
        return ['ve' => $ve, 'hace' => $hace, 'total' => count(self::MATRIZ)];
    }
}
