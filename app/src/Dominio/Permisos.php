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
        'superadmin' => [
            'rotulo' => 'Superadministrador',
            'para'   => 'Dueño de LibertyFin. Puede todo, en todas las empresas.',
            'nivel'  => 'plataforma',
        ],
        'soporte' => [
            'rotulo' => 'Soporte',
            'para'   => 'Mantenimiento y diagnóstico. Ve todo, no mueve dinero.',
            'nivel'  => 'plataforma',
        ],
        'validador' => [
            'rotulo' => 'Validación',
            'para'   => 'Revisa la documentación de las empresas. No ve ventas ni dinero.',
            'nivel'  => 'plataforma',
        ],
        'admin' => [
            'rotulo' => 'Administrador',
            'para'   => 'Dueño o gerente del negocio. Ve el dinero y configura.',
            'nivel'  => 'empresa',
        ],
        'cajero' => [
            'rotulo' => 'Cajero',
            'para'   => 'Cobra, abre y cierra su caja. No ve las comisiones de nadie.',
            'nivel'  => 'empresa',
        ],
        'inventario' => [
            'rotulo' => 'Inventario',
            'para'   => 'Mantiene el catálogo y los proveedores. No cobra ni ve el dinero.',
            'nivel'  => 'empresa',
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
        'ver.panel'        => ['superadmin','admin','cajero','inventario','soporte'],
        'ver.ventas'       => ['superadmin','admin','cajero','soporte'],
        'ver.clientes'     => ['superadmin','admin','cajero','soporte'],
        'ver.cobranza'     => ['superadmin','admin','soporte'],
        'ver.servicios'    => ['superadmin','admin','cajero','inventario','soporte'],
        'ver.comisiones'   => ['superadmin','admin','soporte'],
        'ver.gastos'       => ['superadmin','admin','inventario','soporte'],
        'ver.reportes'     => ['superadmin','admin','soporte'],
        'ver.corte'        => ['superadmin','admin','cajero','soporte'],
        'ver.recargas'     => ['superadmin','admin','cajero','soporte'],
        'ver.facturacion'  => ['superadmin','admin','soporte'],
        'ver.ajustes'      => ['superadmin','admin','soporte'],
        'ver.usuarios'     => ['superadmin','admin','soporte'],
        'ver.mantenimiento'=> ['superadmin','soporte','validador'],
        // La bitácora la ve el administrador de la empresa, no solo la
        // plataforma: es SU historial, sobre SUS datos. Esconderla haría
        // que para saber quién canceló un pago tuviera que pedirlo a
        // soporte, y eso no es auditar, es depender.
        'ver.auditoria'    => ['superadmin','admin','soporte'],

        // ── Mover dinero ──
        'cobrar'           => ['superadmin','admin','cajero'],
        'abonar'           => ['superadmin','admin','cajero'],
        'cancelar.pago'    => ['superadmin','admin'],
        'abrir.caja'       => ['superadmin','admin','cajero'],
        'cerrar.caja'      => ['superadmin','admin','cajero'],
        'vender.recarga'   => ['superadmin','admin','cajero'],
        'timbrar'          => ['superadmin','admin'],

        // ── Catálogos ──
        'editar.clientes'  => ['superadmin','admin','cajero'],
        'editar.servicios' => ['superadmin','admin','inventario'],
        'editar.gastos'    => ['superadmin','admin','inventario'],
        'borrar.gastos'    => ['superadmin','admin'],

        // ── Comisiones ──
        'asignar.comision' => ['superadmin','admin'],
        'quitar.comision'  => ['superadmin','admin'],

        // ── Configuración ──
        'editar.ajustes'   => ['superadmin','admin'],
        'editar.usuarios'  => ['superadmin','admin'],
        'editar.empresa'   => ['superadmin','admin'],

        // ── Plataforma ──
        // Validación SOLO revisa papeles. No toca secciones, no ve
        // diagnóstico y no da de alta empresas: quien revisa documentos
        // no necesita nada de eso, y dárselo agranda sin razón lo que se
        // pierde si esa cuenta se compromete.
        // Soporte ve todas las empresas y puede destrabar una cuenta.
        // Lo que NO puede es cambiar roles: si pudiera volver admin a
        // cualquiera, comprometer una cuenta de soporte daría acceso
        // total a todos los clientes. El límite no mide confianza en la
        // persona, mide el daño si esa cuenta se pierde.
        'ver.empresas'     => ['superadmin','soporte'],
        // Validación también entra a tickets: los de documentación son
        // suyos, y mandarla a otro sistema para contestarlos sería
        // exactamente el problema que estos tickets vienen a resolver.
        'ver.tickets'      => ['superadmin','soporte','validador'],
        // La base de conocimientos la LEE cualquiera del equipo de
        // plataforma; escribirla también, a propósito: quien resuelve un
        // caso raro es quien sabe explicarlo, y si tiene que pedir permiso
        // para documentarlo, no lo documenta.
        'ver.conocimiento'    => ['superadmin','soporte','validador'],
        'editar.conocimiento' => ['superadmin','soporte','validador'],
        'clave.ajena'      => ['superadmin','soporte'],
        'bloquear.cuenta'  => ['superadmin','soporte'],
        'revisar.docs'     => ['superadmin','soporte','validador'],
        'secciones'        => ['superadmin','soporte','admin'],
        'diagnostico'      => ['superadmin','soporte'],
        'alta.empresas'    => ['superadmin','soporte'],
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

    /** ¿Es un rol de plataforma? Esos no pertenecen a una empresa. */
    public static function esPlataforma($rol)
    {
        $n = self::normalizar($rol);
        return (self::ROLES[$n]['nivel'] ?? 'empresa') === 'plataforma';
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
