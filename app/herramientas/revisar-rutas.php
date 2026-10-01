<?php
/**
 * Comprueba que cada ruta declare quién puede entrar.
 *
 * Una ruta sin permiso en la tabla del portero NO queda cerrada: queda
 * ABIERTA a cualquiera que haya iniciado sesión. Es el fallo que no se
 * nota, porque la pantalla funciona perfecto.
 */
$i = file_get_contents("public/index.php");
preg_match_all('/\x27(\/[^\x27]*)\x27\s*=>/', $i, $p);
preg_match_all('/\$r->(?:get|post)\(\x27([^\x27]+)\x27/', $i, $r);

// Estas no llevan permiso a propósito:
//   · las públicas, que se usan sin sesión
//   · la raíz, que bifurca según el nivel del rol
//   · la cuenta propia y la guía, que cualquiera con sesión puede usar
//     sobre SÍ MISMO: pedir permiso ahí dejaría a un cajero sin poder
//     cambiar su contraseña.
$aproposito = [
    '/login', '/salir', '/registro', '/ayuda-acceso', '/',
    '/cuenta', '/cuenta/clave', '/cuenta/foto', '/cuenta/fiscales',
    '/cuenta/comercio', '/cuenta/documento', '/guia', '/guia/vista',
];

$sin = array_values(array_diff(array_unique($r[1]), $p[1], $aproposito));
sort($sin);

if ($sin) {
    echo "  RUTAS SIN PERMISO DECLARADO (quedan abiertas a cualquiera con sesión):\n";
    foreach ($sin as $x) echo "    " . $x . "\n";
    echo "\n  " . count($sin) . " por declarar en la tabla de permisos de public/index.php\n";
} else {
    printf("  rutas: las %d llevan permiso, o están en la lista de excepciones\n",
        count(array_unique($r[1])));
}
