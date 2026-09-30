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
    const ROLES = [
        'admin' => [
            'rotulo' => 'Administrador',
            'para'   => 'Dueño o gerente. Ve el dinero y configura el sistema.',
        ],
        'supervisor' => [
            'rotulo' => 'Supervisor',
            'para'   => 'Coordina la operación y ve reportes. No configura ni toca usuarios.',
        ],
        'cajero' => [
            'rotulo' => 'Cajero',
            'para'   => 'Cobra, abre y cierra su caja. No ve las comisiones de nadie.',
        ],
        'vendedor' => [
            'rotulo' => 'Vendedor',
            'para'   => 'Vende y da seguimiento a sus clientes. Ve su propia comisión.',
        ],
        'soporte' => [
            'rotulo' => 'Soporte',
            'para'   => 'Mantenimiento y diagnóstico. Ve todo, no mueve dinero.',
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
        'ver.panel'        => ['admin','supervisor','cajero','vendedor','soporte'],
        'ver.ventas'       => ['admin','supervisor','cajero','vendedor','soporte'],
        'ver.clientes'     => ['admin','supervisor','cajero','vendedor','soporte'],
        'ver.cobranza'     => ['admin','supervisor','vendedor','soporte'],
        'ver.servicios'    => ['admin','supervisor','cajero','vendedor','soporte'],
        'ver.comisiones'   => ['admin','supervisor','soporte'],
        'ver.gastos'       => ['admin','supervisor','soporte'],
        'ver.reportes'     => ['admin','supervisor','soporte'],
        'ver.corte'        => ['admin','supervisor','cajero','soporte'],
        'ver.recargas'     => ['admin','supervisor','cajero','soporte'],
        'ver.ajustes'      => ['admin','soporte'],
        'ver.usuarios'     => ['admin','soporte'],
        'ver.mantenimiento'=> ['soporte'],

        // ── Mover dinero ──
        'cobrar'           => ['admin','supervisor','cajero','vendedor'],
        'abonar'           => ['admin','supervisor','cajero','vendedor'],
        'cancelar.pago'    => ['admin'],
        'abrir.caja'       => ['admin','supervisor','cajero'],
        'cerrar.caja'      => ['admin','supervisor','cajero'],
        'vender.recarga'   => ['admin','supervisor','cajero'],

        // ── Catálogos ──
        'editar.clientes'  => ['admin','supervisor','cajero','vendedor'],
        'editar.servicios' => ['admin','supervisor'],
        'editar.gastos'    => ['admin','supervisor'],
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
        $rol = trim((string)$rol);
        if ($rol === '') return 'sin rol asignado';
        return self::ROLES[$rol]['rotulo'] ?? $rol;
    }

    /** ¿Es un rol que esta versión conoce? */
    public static function conocido($rol)
    {
        return isset(self::ROLES[trim((string)$rol)]);
    }

    public static function puede($permiso, $rol = null)
    {
        $rol = $rol ?? ($_SESSION['usuario_rol'] ?? '');
        $lista = self::MATRIZ[$permiso] ?? null;
        // Un permiso que no existe se niega. Escribirlo mal debe cerrar
        // la puerta, no abrirla.
        if ($lista === null) return false;
        return in_array($rol, $lista, true);
    }

    /** Todo lo que puede un rol, para mostrarlo al asignarlo. */
    public static function de($rol)
    {
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
