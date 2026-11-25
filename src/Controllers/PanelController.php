<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\View;

final class PanelController
{
    /** Mensajes fijos por código: nunca se refleja texto del cliente. */
    private const MENSAJES = [
        'password_cambiada' => 'Contraseña cambiada. Las demás sesiones se cerraron.',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** GET / (panel protegido: muestra quién eres). */
    public function inicio(Request $req): Response
    {
        $u = $req->get('usuario');
        if ((int) $u['debe_cambiar_password'] === 1) {
            return Response::redirect($this->app->config->url('/cuenta/password'));
        }
        $r = Response::html('');
        $token = $this->app->csrfParaVista($req, $r, (string) $req->get('claims')->sid);
        $r->body = View::render('panel', [
            'usuario' => $u,
            'permisos' => array_keys($req->get('permisos', [])),
            'mensaje' => self::MENSAJES[(string) ($req->query['m'] ?? '')] ?? null,
        ], $token);
        return $r;
    }

    /** GET /me: usuario + permisos efectivos (JSON). */
    public function me(Request $req): Response
    {
        $u = $req->get('usuario');
        $claims = $req->get('claims');
        return Response::json([
            'usuario' => [
                'id' => (int) $u['id'],
                'usuario' => $u['usuario'],
                'nombre' => $u['nombre'],
                'email' => $u['email'],
                'rol' => $u['rol'],
                'sucursal_id' => (int) $u['sucursal_id'],
                'sucursal' => $u['sucursal'],
            ],
            'debe_cambiar_password' => (int) $u['debe_cambiar_password'] === 1,
            'permisos' => array_keys($req->get('permisos', [])),
            'sesion' => ['access_exp' => (int) $claims->exp],
        ]);
    }
}
