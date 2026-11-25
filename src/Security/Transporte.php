<?php

declare(strict_types=1);

namespace App\Security;

use App\Config;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;

/**
 * Comprobaciones de transporte antes de cualquier otra cosa:
 *  1. Host: debe ser exactamente el de APP_URL (evita Host header injection y que
 *     las cookies/Origin se evalúen contra un nombre distinto). GET: se redirige
 *     a APP_URL (destino tomado de la configuración, no del cliente).
 *  2. HTTPS: obligatorio en producción. Sin HTTPS: GET/HEAD -> 301 a https, resto -> 400.
 *     En local sin HTTPS solo se atiende si APP_URL es de loopback.
 */
final class Transporte
{
    public static function esLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    public static function verificar(Config $c, Request $req): ?Response
    {
        if (!hash_equals($c->hostConPuerto(), $req->host)) {
            if ($req->esMetodoSeguro()) {
                return Response::redirect($c->origen() . $c->basePath . '/', 302);
            }
            return Errores::respuesta($req, 400, 'host_no_permitido');
        }
        if ($req->https) {
            return null;
        }
        if (!$c->esLocal()) {
            if ($req->esMetodoSeguro()) {
                return Response::redirect('https://' . $c->hostConPuerto() . $c->basePath . $req->path, 301);
            }
            return Errores::respuesta($req, 400, 'https_requerido');
        }
        if (!self::esLoopback($c->host)) {
            // LAN por IP sin HTTPS: no se relajan cookies (docs/SEGURIDAD_AUTH.md §1).
            return Errores::respuesta($req, 403, 'https_requerido');
        }
        return null;
    }
}
