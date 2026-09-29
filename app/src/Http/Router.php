<?php
namespace LibertyFin\Http;

/** Router mínimo: ruta exacta -> [Controlador, método]. */
final class Router
{
    private $rutas = [];
    public function get($ruta, array $destino) { $this->rutas['GET ' . $ruta] = $destino; }
    public function post($ruta, array $destino) { $this->rutas['POST ' . $ruta] = $destino; }

    public function despachar($metodo, $uri)
    {
        $ruta = '/' . trim(parse_url($uri, PHP_URL_PATH), '/');
        if ($ruta === '/') $ruta = '/';
        $clave = $metodo . ' ' . $ruta;
        if (!isset($this->rutas[$clave])) return null;
        return $this->rutas[$clave];
    }
    public function rutas() { return array_keys($this->rutas); }
}
