<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Petición HTTP inmutable salvo los atributos (usuario autenticado, permisos...)
 * que van añadiendo los middleware.
 *
 * IP: SOLO REMOTE_ADDR. No se confía en X-Forwarded-For (falsificable); si algún
 * día hay un proxy inverso, se añadirá una lista de proxies de confianza.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $atributos = [];

    /**
     * @param array<string,string> $headers  nombres en minúsculas
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly array $query,
        public readonly array $body,
        public readonly string $ip,
        public readonly bool $https,
        public readonly string $host,
    ) {
    }

    public static function desdeGlobales(string $basePath): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = [];
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $ct = strtolower($headers['content-type'] ?? '');
            if (str_starts_with($ct, 'application/json')) {
                // Tope de 64 KB: ningún endpoint de este sprint necesita más.
                $raw = file_get_contents('php://input', false, null, 0, 65536);
                $dec = is_string($raw) && $raw !== '' ? json_decode($raw, true, 8) : null;
                $body = is_array($dec) ? $dec : [];
            } else {
                $body = $_POST;
            }
        }

        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $cookies[$k] = $v;
            }
        }

        // Solo la variable del propio servidor web (Apache/IIS fijan HTTPS=on).
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        return new self(
            $method,
            $path,
            $headers,
            $cookies,
            $_GET,
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $https,
            strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')),
        );
    }

    public function header(string $nombre): ?string
    {
        return $this->headers[strtolower($nombre)] ?? null;
    }

    public function cookie(string $nombre): ?string
    {
        $v = $this->cookies[$nombre] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Valor de texto del cuerpo (los arrays se ignoran: evita confusión de tipos). */
    public function input(string $clave, string $def = ''): string
    {
        $v = $this->body[$clave] ?? $def;
        return is_scalar($v) ? (string) $v : $def;
    }

    public function esMetodoSeguro(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function quiereJson(): bool
    {
        $accept = strtolower($this->header('accept') ?? '');
        $ct = strtolower($this->header('content-type') ?? '');
        return str_contains($accept, 'application/json') || str_starts_with($ct, 'application/json');
    }

    public function userAgent(): ?string
    {
        $ua = $this->header('user-agent');
        return $ua === null ? null : mb_substr($ua, 0, 255, 'UTF-8');
    }

    public function ipBinaria(): string
    {
        $b = @inet_pton($this->ip);
        return $b === false ? "\0\0\0\0" : $b;
    }

    public function set(string $clave, mixed $valor): void
    {
        $this->atributos[$clave] = $valor;
    }

    public function get(string $clave, mixed $def = null): mixed
    {
        return $this->atributos[$clave] ?? $def;
    }
}
