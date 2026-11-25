<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Respuesta HTTP. Las cookies se serializan a mano (cabecera Set-Cookie) para
 * controlar el formato exacto y poder probarlo sin enviar nada.
 */
final class Response
{
    /** @var array<string,string> */
    public array $headers = [];

    /** @var list<string> cabeceras Set-Cookie ya serializadas */
    public array $cookies = [];

    public function __construct(public int $status = 200, public string $body = '')
    {
    }

    public static function html(string $html, int $status = 200): self
    {
        $r = new self($status, $html);
        $r->headers['Content-Type'] = 'text/html; charset=UTF-8';
        return $r;
    }

    public static function json(array $datos, int $status = 200): self
    {
        $r = new self($status, (string) json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));
        $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $r;
    }

    /** Solo rutas internas ya construidas por la app (nunca una URL del cliente). */
    public static function redirect(string $destino, int $status = 303): self
    {
        $r = new self($status);
        $r->headers['Location'] = $destino;
        return $r;
    }

    /**
     * @param array{max_age:int, path:string, secure:bool, http_only:bool, same_site:string} $o
     */
    public function cookie(string $nombre, string $valor, array $o): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $nombre)) {
            throw new \InvalidArgumentException('Nombre de cookie inválido');
        }
        $partes = [$nombre . '=' . rawurlencode($valor)];
        $maxAge = max(0, $o['max_age']);
        $partes[] = 'Max-Age=' . $maxAge;
        $partes[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $maxAge === 0 ? 1 : time() + $maxAge);
        $partes[] = 'Path=' . $o['path'];
        if ($o['secure']) {
            $partes[] = 'Secure';
        }
        if ($o['http_only']) {
            $partes[] = 'HttpOnly';
        }
        $partes[] = 'SameSite=' . $o['same_site'];
        $this->cookies[] = implode('; ', $partes);
    }

    public function enviar(bool $soloCabeceras = false): void
    {
        http_response_code($this->status);
        header_remove('X-Powered-By');
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . str_replace(["\r", "\n"], '', $v), true);
        }
        foreach ($this->cookies as $c) {
            header('Set-Cookie: ' . $c, false);
        }
        if (!$soloCabeceras) {
            echo $this->body;
        }
    }
}
