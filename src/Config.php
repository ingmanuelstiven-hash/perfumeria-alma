<?php

declare(strict_types=1);

namespace App;

/**
 * Configuración de la aplicación web, validada al arrancar (fail closed).
 *
 * Si algo falta o es débil, Config::cargar() lanza ConfigInvalida y el front
 * controller responde 500 genérico SIN atender la petición (la app "no arranca").
 *
 * Reglas:
 *  - APP_ENV: local | production (se acepta "produccion"). Cualquier otro valor: error.
 *  - APP_URL: URL absoluta sin query ni fragmento. En producción debe ser https.
 *    Su ruta (p. ej. /perfumeria/public) es la base de todas las rutas y cookies.
 *  - JWT_SECRET y CSRF_SECRET: hexadecimal de al menos 64 caracteres (>= 32 bytes
 *    de entropía si se generaron con bin2hex(random_bytes(32))). Deben ser distintos.
 *    Se exige hex porque es la única forma de comprobar la longitud real en bytes
 *    (una frase larga puede tener poca entropía y no hay manera de medirla).
 *  - JWT_SECRET_ANTERIOR (opcional): clave previa, solo se usa para VERIFICAR
 *    durante una rotación (cabecera kid). Debe quitarse pasados 15 min.
 */
final class Config
{
    public const ENV_LOCAL = 'local';
    public const ENV_PROD = 'production';

    /** Mínimo de caracteres hex = 32 bytes. */
    private const MIN_HEX = 64;

    private static ?self $instancia = null;

    private function __construct(
        public readonly string $env,
        public readonly string $scheme,
        public readonly string $host,
        public readonly ?int $port,
        /** Ruta base sin barra final ('' si la app está en la raíz). */
        public readonly string $basePath,
        #[\SensitiveParameter] public readonly string $jwtKey,
        #[\SensitiveParameter] public readonly ?string $jwtKeyAnterior,
        #[\SensitiveParameter] public readonly string $csrfKey,
        public readonly int $loginMaxIntentosIp,
        public readonly int $refreshMaxPorMinuto,
    ) {
    }

    public static function cargar(): self
    {
        return self::$instancia ??= self::desdeEntorno();
    }

    /** Solo para pruebas en proceso. */
    public static function reiniciar(): void
    {
        self::$instancia = null;
    }

    public static function desdeEntorno(): self
    {
        $env = strtolower(trim((string) Env::get('APP_ENV', '')));
        if ($env === 'produccion') {
            $env = self::ENV_PROD;
        }
        if (!in_array($env, [self::ENV_LOCAL, self::ENV_PROD], true)) {
            throw new ConfigInvalida('APP_ENV debe ser "local" o "production".');
        }

        $url = trim((string) Env::get('APP_URL', ''));
        $p = parse_url($url);
        if ($url === '' || $p === false || !isset($p['scheme'], $p['host'])
            || isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            throw new ConfigInvalida('APP_URL inválida: use una URL absoluta como https://tienda.ejemplo.com o http://127.0.0.1:8099');
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigInvalida('APP_URL debe usar http o https.');
        }
        if ($env === self::ENV_PROD && $scheme !== 'https') {
            throw new ConfigInvalida('En producción APP_URL debe ser https (las cookies __Host- exigen HTTPS).');
        }
        $basePath = rtrim($p['path'] ?? '', '/');
        if ($basePath !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $basePath)) {
            throw new ConfigInvalida('La ruta de APP_URL contiene caracteres no permitidos.');
        }

        $jwt = self::secreto('JWT_SECRET');
        $csrf = self::secreto('CSRF_SECRET');
        if (hash_equals($jwt, $csrf)) {
            throw new ConfigInvalida('JWT_SECRET y CSRF_SECRET deben ser distintos.');
        }
        $anterior = null;
        $antTxt = Env::get('JWT_SECRET_ANTERIOR', '');
        if ($antTxt !== null && $antTxt !== '') {
            $anterior = self::secreto('JWT_SECRET_ANTERIOR');
        }

        return new self(
            $env,
            $scheme,
            strtolower($p['host']),
            isset($p['port']) ? (int) $p['port'] : null,
            $basePath,
            $jwt,
            $anterior,
            $csrf,
            self::entero('LOGIN_MAX_INTENTOS_IP', 20, 5, 1000),
            self::entero('REFRESH_MAX_POR_MINUTO', 60, 5, 10000),
        );
    }

    private static function secreto(string $nombre): string
    {
        $v = trim((string) Env::get($nombre, ''));
        if ($v === '') {
            throw new ConfigInvalida("Falta {$nombre}. Genérelo con: php -r \"echo bin2hex(random_bytes(32));\"");
        }
        if (strlen($v) < self::MIN_HEX || strlen($v) % 2 !== 0 || !ctype_xdigit($v)) {
            throw new ConfigInvalida("{$nombre} debe ser hexadecimal de al menos " . self::MIN_HEX
                . ' caracteres (32 bytes). Genérelo con: php -r "echo bin2hex(random_bytes(32));"');
        }
        $bin = hex2bin($v);
        if ($bin === false || count(array_unique(str_split($bin))) < 16) {
            throw new ConfigInvalida("{$nombre} tiene muy poca variedad de bytes: no parece aleatorio.");
        }
        return $bin;
    }

    private static function entero(string $nombre, int $def, int $min, int $max): int
    {
        $v = Env::get($nombre);
        if ($v === null || $v === '') {
            return $def;
        }
        if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
            throw new ConfigInvalida("{$nombre} debe ser un entero entre {$min} y {$max}.");
        }
        return (int) $v;
    }

    public function esLocal(): bool
    {
        return $this->env === self::ENV_LOCAL;
    }

    /** "https://host[:puerto]" tal como lo envía el navegador en Origin. */
    public function origen(): string
    {
        $defecto = $this->scheme === 'https' ? 443 : 80;
        $puerto = ($this->port !== null && $this->port !== $defecto) ? ':' . $this->port : '';
        return $this->scheme . '://' . $this->host . $puerto;
    }

    /** Valor esperado de la cabecera Host. */
    public function hostConPuerto(): string
    {
        $defecto = $this->scheme === 'https' ? 443 : 80;
        return $this->host . (($this->port !== null && $this->port !== $defecto) ? ':' . $this->port : '');
    }

    /** Emisor/audiencia del JWT. */
    public function emisor(): string
    {
        return $this->origen() . $this->basePath;
    }

    public function url(string $ruta): string
    {
        return $this->basePath . $ruta;
    }
}
