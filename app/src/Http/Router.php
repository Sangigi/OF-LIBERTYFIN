<?php
namespace LibertyFin\Http;

/**
 * Router mínimo con parámetros.
 *   /ventas/{id}  ->  ['Controlador', 'metodo']  y el id llega como argumento.
 *
 * Los parámetros solo aceptan dígitos: así un id no puede traer texto
 * que termine en una consulta.
 */
final class Router
{
    private $rutas = [];

    public function get($ruta, array $destino)  { $this->agregar('GET',  $ruta, $destino); }
    public function post($ruta, array $destino) { $this->agregar('POST', $ruta, $destino); }

    private function agregar($metodo, $ruta, array $destino)
    {
        $nombres = [];
        $patron = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$nombres) {
            $nombres[] = $m[1];
            return '(\d+)';
        }, $ruta);
        $this->rutas[] = [
            'metodo'  => $metodo,
            'patron'  => '#^' . str_replace('/', '\/', $patron) . '$#',
            'nombres' => $nombres,
            'destino' => $destino,
            'ruta'    => $ruta,
        ];
    }

    /** @return array|null  ['destino' => [...], 'args' => [...]] */
    public function despachar($metodo, $uri)
    {
        $ruta = parse_url($uri, PHP_URL_PATH);
        $ruta = '/' . trim((string)$ruta, '/');
        foreach ($this->rutas as $r) {
            if ($r['metodo'] !== $metodo) continue;
            if (preg_match($r['patron'], $ruta, $m)) {
                array_shift($m);
                return ['destino' => $r['destino'],
                        'args'    => array_map('intval', $m),
                        'patron'  => $r['ruta']];
            }
        }
        return null;
    }
}
