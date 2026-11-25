<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Services\ErrorNegocio;
use App\View;

final class CuentaController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /cuenta/password */
    public function formulario(Request $req): Response
    {
        return $this->pagina($req, 200, null, []);
    }

    /** POST /cuenta/password: exige la actual; sube token_version y revoca todas las sesiones. */
    public function cambiar(Request $req): Response
    {
        try {
            $sesion = $this->app->usuarios->cambiarPasswordPropia(
                $req->get('usuario'),
                $req->input('actual'),
                $req->input('nueva'),
                $req->input('confirmacion'),
                $req->ipBinaria(),
                $req->userAgent(),
            );
        } catch (ErrorNegocio $e) {
            if ($req->quiereJson()) {
                return Response::json(['error' => 'no_cambiada', 'mensaje' => $e->getMessage(), 'errores' => $e->errores], $e->status);
            }
            return $this->pagina($req, $e->status, $e->getMessage(), $e->errores);
        }
        $resp = $req->quiereJson()
            ? Response::json(['ok' => true])
            : Response::redirect($this->app->config->url('/?m=password_cambiada'));
        // Sesión nueva para este dispositivo (sid nuevo => CSRF nuevo).
        $this->app->ponerCookiesSesion($req, $resp, $sesion, true);
        return $resp;
    }

    /** @param list<string> $errores */
    private function pagina(Request $req, int $status, ?string $error, array $errores): Response
    {
        $r = Response::html('', $status);
        $token = $this->app->csrfParaVista($req, $r, (string) $req->get('claims')->sid);
        $r->body = View::render('cuenta_password', [
            'usuario' => $req->get('usuario'),
            'error' => $error,
            'errores' => $errores,
        ], $token);
        return $r;
    }
}
