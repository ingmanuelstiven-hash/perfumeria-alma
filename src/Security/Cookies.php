<?php

declare(strict_types=1);

namespace App\Security;

use App\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * Nombres y atributos de las cookies de sesión.
 *
 * Modo SEGURO (producción, o local servido por HTTPS):
 *   __Host-at    access JWT        HttpOnly; Secure; SameSite=Strict; Path=/
 *   __Secure-rt  refresh opaco     HttpOnly; Secure; SameSite=Strict; Path=<base>/auth/refresh
 *   __Host-csrf  token CSRF        HttpOnly; Secure; SameSite=Strict; Path=/
 *
 * Modo LOCAL INSEGURO: solo si APP_ENV=local, la petición NO es HTTPS y el host
 * de APP_URL es de loopback (localhost, 127.0.0.1, [::1]). Mismos atributos pero
 * sin Secure y sin prefijo (at, rt, csrf), porque algunos navegadores no fijan
 * cookies Secure/__Host- sobre http://localhost. Fuera de esas condiciones la
 * petición se rechaza (ver Transporte); nunca se relajan cookies en producción.
 *
 * En modo seguro SOLO se leen los nombres con prefijo: una cookie sin prefijo
 * plantada por un subdominio o por HTTP no se acepta nunca.
 */
final class Cookies
{
    public function __construct(private readonly Config $config, public readonly bool $seguro)
    {
    }

    public static function paraPeticion(Config $config, Request $req): self
    {
        $localInseguro = $config->esLocal() && !$req->https && Transporte::esLoopback($config->host);
        return new self($config, !$localInseguro);
    }

    public function nombreAcceso(): string
    {
        return $this->seguro ? '__Host-at' : 'at';
    }

    public function nombreRefresh(): string
    {
        return $this->seguro ? '__Secure-rt' : 'rt';
    }

    public function nombreCsrf(): string
    {
        return $this->seguro ? '__Host-csrf' : 'csrf';
    }

    public function rutaRefresh(): string
    {
        return $this->config->basePath . '/auth/refresh';
    }

    public function ponerAcceso(Response $r, string $jwt, int $maxAge): void
    {
        $r->cookie($this->nombreAcceso(), $jwt, $this->opciones('/', $maxAge));
    }

    public function ponerRefresh(Response $r, string $token, int $maxAge): void
    {
        $r->cookie($this->nombreRefresh(), $token, $this->opciones($this->rutaRefresh(), $maxAge));
    }

    public function ponerCsrf(Response $r, string $token, int $maxAge): void
    {
        $r->cookie($this->nombreCsrf(), $token, $this->opciones('/', $maxAge));
    }

    public function borrarTodas(Response $r): void
    {
        $r->cookie($this->nombreAcceso(), '', $this->opciones('/', 0));
        $r->cookie($this->nombreRefresh(), '', $this->opciones($this->rutaRefresh(), 0));
        $r->cookie($this->nombreCsrf(), '', $this->opciones('/', 0));
    }

    /** @return array{max_age:int, path:string, secure:bool, http_only:bool, same_site:string} */
    private function opciones(string $path, int $maxAge): array
    {
        return [
            'max_age' => $maxAge,
            'path' => $path,
            'secure' => $this->seguro,
            'http_only' => true,
            'same_site' => 'Strict',
        ];
    }
}
