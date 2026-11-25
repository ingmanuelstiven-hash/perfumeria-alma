<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Services\ErrorNegocio;
use App\Support\Entrada;
use App\View;

/**
 * Base de los controladores del catálogo (Sprint 1B). Mismo patrón que AdminUsuariosController:
 * ErrorNegocio -> JSON {error, mensaje, errores} o página con el formulario y los valores conservados;
 * éxito -> JSON {ok:true,...} o redirección 303 con un código de mensaje fijo (?m=...).
 */
abstract class ControladorBase
{
    public function __construct(protected readonly App $app)
    {
    }

    /**
     * @param callable():array<string,mixed> $fn      acción; devuelve datos para el JSON
     * @param callable(array<string,mixed>):string $destino ruta (sin base) a la que redirigir en HTML
     * @param callable(ErrorNegocio):Response $fallo   vuelve a pintar el formulario (HTML)
     */
    protected function ejecutar(Request $req, callable $fn, callable $destino, callable $fallo, int $statusOk = 200): Response
    {
        try {
            $datos = $fn();
        } catch (ErrorNegocio $e) {
            if ($req->quiereJson()) {
                return Response::json(['error' => 'operacion_rechazada', 'mensaje' => $e->getMessage(), 'errores' => $e->errores], $e->status);
            }
            if ($e->status === 404 || $e->status === 403) {
                return Errores::respuesta($req, $e->status);
            }
            return $fallo($e);
        }
        if ($req->quiereJson()) {
            return Response::json(['ok' => true] + $datos, $statusOk);
        }
        return Response::redirect($this->app->config->url($destino($datos)));
    }

    /** @param array<string,mixed> $datos */
    protected function pagina(Request $req, string $plantilla, array $datos, int $status = 200): Response
    {
        $r = Response::html('', $status);
        $token = $this->app->csrfParaVista($req, $r, (string) $req->get('claims')->sid);
        $permisos = $req->get('permisos', []);
        $r->body = View::render($plantilla, $datos + [
            'usuario' => $req->get('usuario'),
            'permisos_set' => $permisos,
            'puede_gestionar' => isset($permisos['articulos.gestionar']),
            'ver_costos' => isset($permisos['inventario.ver_costos']),
            'ver_inventario' => isset($permisos['inventario.ver']),
        ], $token);
        return $r;
    }

    /** @return array<string,true> */
    protected function permisos(Request $req): array
    {
        $p = $req->get('permisos', []);
        return is_array($p) ? $p : [];
    }

    /** Valores del cuerpo (solo los campos permitidos) para devolverlos al formulario. */
    protected function valoresForm(Request $req, array $campos): array
    {
        $o = [];
        foreach ($campos as $c) {
            $o[$c] = Entrada::recortar($req->input($c), 1000);
        }
        return $o;
    }

    protected function idRuta(array $p): int
    {
        return (int) ($p['id'] ?? 0);
    }
}
