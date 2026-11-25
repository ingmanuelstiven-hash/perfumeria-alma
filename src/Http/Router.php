<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Router mínimo: rutas exactas o con parámetros {nombre} (solo dígitos: \d{1,10}),
 * middleware encadenable por ruta. Un middleware es callable(Request, callable $next): Response.
 *
 * Construido a mano en lugar de adoptar un paquete (FastRoute, Slim...): son ~60
 * líneas, sin dependencias nuevas, y el conjunto de rutas del sistema es pequeño.
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:callable, mws:list<callable>, ruta:string}> */
    private array $rutas = [];

    /** @param list<callable> $mws */
    public function get(string $ruta, callable $handler, array $mws = []): void
    {
        $this->agregar('GET', $ruta, $handler, $mws);
    }

    /** @param list<callable> $mws */
    public function post(string $ruta, callable $handler, array $mws = []): void
    {
        $this->agregar('POST', $ruta, $handler, $mws);
    }

    /** @param list<callable> $mws */
    private function agregar(string $method, string $ruta, callable $handler, array $mws): void
    {
        $regex = '#^' . preg_replace('#\\\\\{([a-z]+)\\\\\}#', '(?P<$1>\d{1,10})', preg_quote($ruta, '#')) . '$#';
        $this->rutas[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'mws' => $mws, 'ruta' => $ruta];
    }

    /** @return list<array{method:string, ruta:string}> (para pruebas) */
    public function listado(): array
    {
        return array_map(static fn ($r) => ['method' => $r['method'], 'ruta' => $r['ruta']], $this->rutas);
    }

    public function despachar(Request $req): Response
    {
        $metodo = $req->method === 'HEAD' ? 'GET' : $req->method;
        $permitidos = [];
        foreach ($this->rutas as $r) {
            if (!preg_match($r['regex'], $req->path, $m)) {
                continue;
            }
            if ($r['method'] !== $metodo) {
                $permitidos[] = $r['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler = $r['handler'];
            $nucleo = static fn (Request $q): Response => $handler($q, $params);
            // Encadenar de dentro hacia fuera: el primero de la lista se ejecuta primero.
            $cadena = array_reduce(
                array_reverse($r['mws']),
                static fn (callable $next, callable $mw) => static fn (Request $q): Response => $mw($q, $next),
                $nucleo
            );
            return $cadena($req);
        }
        if ($permitidos) {
            $r = Errores::respuesta($req, 405);
            $r->headers['Allow'] = implode(', ', array_unique($permitidos));
            return $r;
        }
        return Errores::respuesta($req, 404);
    }
}
