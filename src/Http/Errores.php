<?php

declare(strict_types=1);

namespace App\Http;

use App\View;

/** Respuestas de error genéricas: nunca reflejan la ruta ni detalles internos. */
final class Errores
{
    private const MENSAJES = [
        400 => 'Solicitud inválida.',
        401 => 'Debe iniciar sesión.',
        403 => 'No tiene permiso para realizar esta acción.',
        404 => 'Página no encontrada.',
        405 => 'Método no permitido.',
        409 => 'Conflicto: vuelva a intentarlo.',
        413 => 'Solicitud demasiado grande.',
        422 => 'Datos inválidos.',
        429 => 'Demasiados intentos. Espere unos minutos y vuelva a intentarlo.',
        500 => 'Error interno. Si persiste, contacte al administrador.',
    ];

    private const CODIGOS = [
        400 => 'solicitud_invalida', 401 => 'no_autenticado', 403 => 'prohibido', 404 => 'no_encontrado',
        405 => 'metodo_no_permitido', 409 => 'conflicto', 413 => 'demasiado_grande', 422 => 'datos_invalidos',
        429 => 'demasiados_intentos', 500 => 'error_interno',
    ];

    public static function mensaje(int $status): string
    {
        return self::MENSAJES[$status] ?? self::MENSAJES[500];
    }

    public static function respuesta(?Request $req, int $status, ?string $codigo = null): Response
    {
        $codigo ??= self::CODIGOS[$status] ?? 'error';
        if ($req === null || $req->quiereJson()) {
            return Response::json(['error' => $codigo, 'mensaje' => self::mensaje($status)], $status);
        }
        try {
            $html = View::render('error', ['status' => $status, 'mensaje' => self::mensaje($status)]);
        } catch (\Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . self::mensaje($status) . '</p>';
        }
        return Response::html($html, $status);
    }
}
