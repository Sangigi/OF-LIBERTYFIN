<?php
/**
 * Revisa la configuración de cobros de Paga de Todo.
 *
 *   php app/bin/revisar-pagos.php
 *   php app/bin/revisar-pagos.php --probar     también llama al proveedor
 *
 * POR QUÉ EXISTE
 *
 * Cuando un cobro no sale, el proveedor contesta un número. "22", "1",
 * "El ID de la escuela es obligatorio". Ninguno dice que el problema
 * está en que `url_referencia` apunta al servicio de domiciliación, ni
 * que el usuario de producción se está mandando con la contraseña de
 * Sandbox.
 *
 * Esto enseña exactamente qué se va a mandar y a dónde, antes de que
 * nadie intente cobrar.
 *
 * No escribe nada. No toca la base. Con --probar hace llamadas reales,
 * pero solo de consulta: no genera cobros ni mueve dinero.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require $raiz . '/src/autoload.php';

use LibertyFin\Servicio\Integraciones;
use LibertyFin\Servicio\LigaPago;

$probar = in_array('--probar', $argv, true);
$color  = (function_exists('posix_isatty') && @posix_isatty(STDOUT)) || getenv('FORCE_COLOR');
function c($t, $x) { global $color; if (!$color) return $t;
    $m = ['v' => '32', 'r' => '31', 'a' => '33', 'g' => '1', 'd' => '90'];
    return "\033[" . ($m[$x] ?? '0') . "m" . $t . "\033[0m"; }
function titulo($t) { echo "\n" . c($t, 'g') . "\n"; }
function bien($t)   { echo '  ' . c('ok  ', 'v') . $t . "\n"; }
function mal($t)    { echo '  ' . c('MAL ', 'r') . $t . "\n"; }
function ojo($t)    { echo '  ' . c('ojo ', 'a') . $t . "\n"; }
function nota($t)   { echo '      ' . c($t, 'd') . "\n"; }

$problemas = 0;

echo c('REVISIÓN DE COBROS · PAGA DE TODO', 'g') . "\n";

// ── El archivo ──────────────────────────────────────────────
titulo('El archivo de configuración');
$ruta = $raiz . '/config/integraciones.php';
if (!is_readable($ruta)) {
    mal('no existe ' . $ruta);
    nota('cópialo de config/integraciones.php.ejemplo');
    exit(1);
}
bien('se lee ' . $ruta);
Integraciones::cargar($raiz);
$crudo = (array)require $ruta;

// Bloques con el nombre mal escrito. El sistema los ignora enteros y en
// silencio, que es la peor forma de que algo no funcione.
$conocidos = array_keys(Integraciones::CATALOGO);
foreach (array_keys($crudo) as $bloque) {
    if (in_array($bloque, $conocidos, true)) continue;
    $cerca = '';
    foreach ($conocidos as $k) {
        if (levenshtein((string)$bloque, $k) <= 3) { $cerca = $k; break; }
    }
    $problemas++;
    mal("el bloque '" . $bloque . "' no lo lee nadie");
    nota($cerca !== ''
        ? "¿querías decir '" . $cerca . "'? tal como está, se ignora completo"
        : 'no está en el catálogo: se ignora completo');
}

if (!Integraciones::activa('spei')) {
    $e = Integraciones::estado()['spei'] ?? [];
    mal('la integración de cobros está apagada');
    if (!empty($e['faltan'])) nota('faltan: ' . implode(', ', $e['faltan']));
    else nota("falta poner 'activo' => true");
    exit(1);
}
$cfg = Integraciones::de('spei');
bien('la integración de cobros está encendida'
   . (!empty($cfg['sandbox']) ? ' ' . c('(Sandbox)', 'a') : ' ' . c('(PRODUCCIÓN)', 'r')));

// ── Credenciales ────────────────────────────────────────────
titulo('Credenciales');
$api = new LigaPago($cfg);
$r   = new ReflectionClass($api);
$mUrl = $r->getMethod('url');          $mUrl->setAccessible(true);
$mCre = $r->getMethod('credenciales'); $mCre->setAccessible(true);
$mNeg = $r->getMethod('negocio');      $mNeg->setAccessible(true);
$mRef = $r->getMethod('largoReferencia'); $mRef->setAccessible(true);
$mPag = $r->getMethod('contado');      $mPag->setAccessible(true);

list($usuario, $clave) = $mCre->invoke($api);
$tapa = function ($v) { $v = (string)$v;
    return $v === '' ? c('(vacío)', 'r')
         : (mb_strlen($v) <= 2 ? str_repeat('•', mb_strlen($v))
            : mb_substr($v, 0, 2) . str_repeat('•', max(1, mb_strlen($v) - 2))); };

echo '  usuario  ' . $tapa($usuario) . "\n";
echo '  clave    ' . $tapa($clave) . "\n";

$usandoPrueba = !empty($cfg['sandbox']) && trim((string)($cfg['usuario_prueba'] ?? '')) !== '';
nota($usandoPrueba ? 'son las de Sandbox (usuario_prueba / clave_prueba)'
                   : 'son las principales (usuario / clave)');

if (trim((string)$usuario) === '' || trim((string)$clave) === '') {
    $problemas++; mal('falta usuario o clave');
}
// La mezcla que da el error 1.
if (!empty($cfg['sandbox'])
    && trim((string)($cfg['usuario_prueba'] ?? '')) === ''
    && trim((string)($cfg['clave_prueba'] ?? '')) !== '') {
    ojo('`clave_prueba` está llena pero `usuario_prueba` vacía');
    nota('se usan las principales completas, que es lo correcto; llena las dos o ninguna');
}

$negocio = $mNeg->invoke($api);
if (trim((string)$negocio) === '') {
    $problemas++; mal('falta `negocio_id` (BusinessID): los tres servicios lo piden');
} else {
    bien('BusinessID ' . $negocio
       . (trim((string)($cfg['negocio_id'] ?? '')) === ''
          ? c('  ← sale de `escuela_id`, que es de Paga la Escuela', 'a') : ''));
}
echo '  integración  ' . ($cfg['integracion_id'] ?: c('(vacía)', 'r')) . "\n";
echo '  referencia   ' . $mRef->invoke($api) . " dígitos\n";
echo '  contado      ' . $mPag->invoke($api)
   . c('   (41 producción · 401 Sandbox)', 'd') . "\n";

// ── A dónde pega cada forma de pago ─────────────────────────
titulo('A dónde pega cada forma de pago');

$servicios = [
    ['tarjeta',            'liga',       '/Service/GenerarLigaIndi'],
    ['SPEI',               'clabe',      '/Service/GenerarClabeIndi'],
    ['efectivo en tienda', 'referencia', '/Service/GenerarReferenciaIndi'],
    ['consulta estatus',   'estado',     '/Service/ConsultarEstatusLigaIndi'],
];
$destinos = [];
foreach ($servicios as $s) {
    list($rotulo, $clave_, $ruta) = $s;
    $puesta = trim((string)($cfg['url_' . $clave_] ?? ''));
    $usa    = $mUrl->invoke($api, $clave_, $ruta);
    $destinos[$rotulo] = $usa;

    echo "\n  " . c(str_pad($rotulo, 20), 'g') . $usa . "\n";

    if ($puesta !== '') {
        $queja = LigaPago::revisarUrl($puesta, $ruta);
        if ($queja !== '') {
            $problemas++;
            mal('`url_' . $clave_ . '` ' . $queja);
            nota('la tienes en: ' . $puesta);
            nota('se ignora y se usa la de arriba; bórrala del archivo');
        } else {
            nota('la pusiste a mano en `url_' . $clave_ . '`');
        }
    }
    // Host distinto al de Paga de Todo: puede ser a propósito, pero casi
    // siempre es un dedazo. Un host mal escrito no da un error del
    // proveedor: da "no se pudo contactar".
    $h = parse_url($usa, PHP_URL_HOST) ?: '';
    if ($h !== '' && stripos($h, 'pagadetodo.mx') === false) {
        ojo('el host es ' . $h . ', no pagadetodo.mx');
        if (levenshtein(strtolower($h), 'pagadetodo.mx') <= 3) {
            $problemas++;
            nota('se parece demasiado: revisa si es un dedazo');
        }
    }
}

// ── Los avisos ──────────────────────────────────────────────
titulo('Los avisos del proveedor (SPEI y tienda dependen de esto)');
$secreto = trim((string)($cfg['secreto_webhook'] ?? ''));
if ($secreto === '') {
    $problemas++;
    mal('`secreto_webhook` está vacío');
    nota('SPEI y efectivo en tienda NO se van a aplicar solos: los avisos se rechazan');
    nota('genera uno:  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"');
} elseif (strlen($secreto) < 16) {
    $problemas++;
    mal('`secreto_webhook` tiene ' . strlen($secreto) . ' caracteres: muy corto');
    nota('es lo único que protege esas direcciones; que tenga 32 o más');
} else {
    bien('`secreto_webhook` puesto (' . strlen($secreto) . ' caracteres)');
    $dominio = 'https://TU-DOMINIO';
    echo "\n  Da de alta esto en el Sandbox, pestaña EndPoint:\n\n";
    foreach ([
        'Comercios · Consultar referencia' => 'consulta-referencia',
        'Comercios · Pagar referencia'     => 'pago-referencia',
        'Comercios · Cancelar pago'        => 'cancela-pago',
        'SPEI · Consultar clabe'           => 'consulta-clabe',
        'SPEI · Pagar clabe'               => 'pago-clabe',
        'SPEI · Cancelar pago'             => 'cancela-pago',
        'Pago en línea · Pagar liga'       => 'pago-liga',
    ] as $rotulo => $r_) {
        echo '    ' . str_pad($rotulo, 34) . $dominio . '/pagadetodo/' . $r_
           . '?k=' . c(substr($secreto, 0, 4) . '…', 'd') . "\n";
    }
    nota('(el secreto va completo, aquí se recorta nada más para no imprimirlo)');
}

// ── Llamadas de verdad ──────────────────────────────────────
if ($probar) {
    titulo('Llamando al proveedor');
    foreach ($destinos as $rotulo => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            // A propósito SIN credenciales: se quiere saber si la
            // dirección existe y contesta, no generar un cobro.
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $problemas++;
            mal(str_pad($rotulo, 20) . $err);
            if (stripos($err, 'resolve') !== false) {
                nota('ese dominio no existe: es un dedazo en la dirección');
            }
            continue;
        }
        $j = json_decode((string)$resp, true);
        if (is_array($j)) {
            // Contestó JSON: la dirección existe y es un servicio del
            // proveedor. Sin credenciales va a rechazar, y está bien.
            $cod = $j['Error'] ?? $j['code'] ?? $j['codigo'] ?? '';
            bien(str_pad($rotulo, 20) . 'contesta (HTTP ' . $http . ')');
            nota('rechazó con ' . ($cod !== '' ? 'código ' . $cod : 'un mensaje')
               . ' porque se mandó vacío a propósito: eso es lo esperado');
        } else {
            $problemas++;
            mal(str_pad($rotulo, 20) . 'HTTP ' . $http . ', no contestó JSON');
            nota(mb_substr(trim(strip_tags((string)$resp)), 0, 120));
        }
    }
} else {
    nota("\n  (corre con --probar para llamar al proveedor y confirmar que las direcciones existen)");
}

echo "\n" . str_repeat('─', 60) . "\n";
if ($problemas === 0) {
    echo '  ' . c('Todo en orden.', 'v') . "\n";
} else {
    echo '  ' . c($problemas . ' cosa(s) que revisar.', 'r') . "\n";
}
exit($problemas === 0 ? 0 : 1);
