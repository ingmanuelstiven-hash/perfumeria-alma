<?php

declare(strict_types=1);

/**
 * Pruebas del Sprint 1A (autenticación y autorización). Solo CLI, sin PHPUnit:
 * un runner propio de ~1 archivo basta para pruebas HTTP de caja negra y evita
 * añadir dependencias de desarrollo.
 *
 *   C:\xampp\php\php.exe tests\run.php            (crea y BORRA la BD perfu_s1a_test)
 *   C:\xampp\php\php.exe tests\run.php --conservar (no borra la BD al final)
 *
 * Variables opcionales: TEST_DB_USER (root), TEST_DB_PASS (vacía), TEST_DB_HOST (127.0.0.1).
 * NUNCA toca sistema_perfu: la BD de pruebas tiene nombre fijo y se comprueba.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const BD = 'perfu_s1a_test';
const PUERTO = 8099;
const BASE = 'http://127.0.0.1:' . PUERTO;
const PHP = PHP_BINARY;

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/src/autoload.php';

use App\Security\TokenAcceso;
use Firebase\JWT\JWT;

if (BD === 'sistema_perfu' || !preg_match('/^perfu_[a-z0-9_]+_test$/', BD)) {
    fwrite(STDERR, "Nombre de BD de pruebas no permitido.\n");
    exit(2);
}
$conservar = in_array('--conservar', $argv, true);

// ----------------------------------------------------------------- entorno
$JWT_HEX = bin2hex(random_bytes(32));
$CSRF_HEX = bin2hex(random_bytes(32));
$ENV = [
    'APP_ENV' => 'local',
    'APP_URL' => BASE,
    'DB_HOST' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => BD,
    'DB_USER' => getenv('TEST_DB_USER') ?: 'root',
    'DB_PASS' => getenv('TEST_DB_PASS') ?: '',
    'DB_ADMIN_USER' => getenv('TEST_DB_USER') ?: 'root',
    'DB_ADMIN_PASS' => getenv('TEST_DB_PASS') ?: '',
    'JWT_SECRET' => $JWT_HEX,
    'CSRF_SECRET' => $CSRF_HEX,
];
foreach ($ENV as $k => $v) {
    putenv("$k=$v");
}
date_default_timezone_set('America/Bogota');

/** Contraseñas de prueba (solo existen en la BD temporal). */
$PASS = [
    'admin' => 'Adm1n-Prueba-' . bin2hex(random_bytes(4)),
    'empleado' => 'Empl3ado-Prueba-' . bin2hex(random_bytes(4)),
    'contador' => 'C0ntador-Prueba-' . bin2hex(random_bytes(4)),
];

function entornoHijo(array $extra = []): array
{
    global $ENV;
    $e = getenv();
    foreach (array_merge($ENV, $extra) as $k => $v) {
        if ($v === null) {
            unset($e[$k]);
        } else {
            $e[$k] = $v;
        }
    }
    return $e;
}

function ejecutar(array $cmd, ?string $stdin = null, array $extraEnv = []): array
{
    global $RAIZ;
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t, $RAIZ, entornoHijo($extraEnv));
    if (!is_resource($p)) {
        throw new RuntimeException('No se pudo ejecutar ' . implode(' ', $cmd));
    }
    if ($stdin !== null) {
        fwrite($t[0], $stdin);
    }
    fclose($t[0]);
    $out = stream_get_contents($t[1]);
    $err = stream_get_contents($t[2]);
    fclose($t[1]);
    fclose($t[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

function pdoRaiz(?string $bd): PDO
{
    global $ENV;
    $dsn = 'mysql:host=' . $ENV['DB_HOST'] . ';port=3306;charset=utf8mb4' . ($bd ? ';dbname=' . $bd : '');
    $pdo = new PDO($dsn, $ENV['DB_USER'], $ENV['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET time_zone = '-05:00'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= pdoRaiz(BD);
}

/** @var array<int, resource> */
$SERVIDORES = [];

function iniciarServidor(int $puerto, array $extraEnv = []): void
{
    global $RAIZ, $SERVIDORES;
    $log = $RAIZ . '/logs/test-servidor-' . $puerto . '.log';
    @mkdir(dirname($log), 0750, true);
    $p = proc_open(
        [PHP, '-S', '127.0.0.1:' . $puerto, '-t', 'public', 'public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $t,
        $RAIZ,
        entornoHijo($extraEnv)
    );
    if (!is_resource($p)) {
        throw new RuntimeException('No se pudo iniciar php -S');
    }
    $SERVIDORES[$puerto] = $p;
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100_000);
    }
    throw new RuntimeException("El servidor de pruebas no respondió en el puerto $puerto");
}

function detenerServidor(int $puerto): void
{
    global $SERVIDORES;
    if (isset($SERVIDORES[$puerto])) {
        proc_terminate($SERVIDORES[$puerto]);
        proc_close($SERVIDORES[$puerto]);
        unset($SERVIDORES[$puerto]);
    }
}

// ------------------------------------------------------------ cliente HTTP

/** Cliente con tarro de cookies que respeta Path y Max-Age (como un navegador). */
final class Nav
{
    /** @var array<string, array{valor:string, path:string, attrs:string}> */
    public array $jar = [];
    public array $ultimas = [];

    public function __construct(public string $base = BASE)
    {
    }

    public function clonar(): self
    {
        $n = new self($this->base);
        $n->jar = $this->jar;
        return $n;
    }

    /**
     * @param array|string|null $body array => JSON (o form si $form)
     * @return array{status:int, headers:array<string,list<string>>, body:string, json:?array, cookies:list<string>}
     */
    public function pedir(string $metodo, string $ruta, array|string|null $body = null, array $headers = [], bool $json = true, bool $form = false): array
    {
        $ch = curl_init($this->base . $ruta);
        $hdrs = [];
        $defecto = ['Accept' => $json ? 'application/json' : 'text/html'];
        if ($metodo !== 'GET') {
            $defecto['Origin'] = $this->base;
        }
        foreach (array_merge($defecto, $headers) as $k => $v) {
            if ($v !== null) {
                $hdrs[] = "$k: $v";
            }
        }
        $cookie = $this->cookieHeader($ruta);
        if ($cookie !== '') {
            $hdrs[] = 'Cookie: ' . $cookie;
        }
        if ($body !== null) {
            if (is_array($body) && $form) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            } elseif (is_array($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                $hdrs[] = 'Content-Type: application/json';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $resp = ['headers' => [], 'cookies' => []];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($c, string $linea) use (&$resp): int {
                $p = strpos($linea, ':');
                if ($p !== false) {
                    $n = strtolower(trim(substr($linea, 0, $p)));
                    $v = trim(substr($linea, $p + 1));
                    $resp['headers'][$n][] = $v;
                    if ($n === 'set-cookie') {
                        $resp['cookies'][] = $v;
                    }
                }
                return strlen($linea);
            },
        ]);
        $cuerpo = curl_exec($ch);
        if ($cuerpo === false) {
            throw new RuntimeException('curl: ' . curl_error($ch));
        }
        $resp['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $resp['body'] = (string) $cuerpo;
        $resp['json'] = json_decode((string) $cuerpo, true);
        foreach ($resp['cookies'] as $sc) {
            $this->guardarCookie($sc);
        }
        return $this->ultimas = $resp;
    }

    private function guardarCookie(string $sc): void
    {
        $partes = array_map('trim', explode(';', $sc));
        [$nombre, $valor] = array_pad(explode('=', array_shift($partes), 2), 2, '');
        $path = '/';
        $maxAge = null;
        foreach ($partes as $a) {
            if (stripos($a, 'path=') === 0) {
                $path = substr($a, 5);
            } elseif (stripos($a, 'max-age=') === 0) {
                $maxAge = (int) substr($a, 8);
            }
        }
        if ($maxAge !== null && $maxAge <= 0) {
            unset($this->jar[$nombre]);
            return;
        }
        $this->jar[$nombre] = ['valor' => rawurldecode($valor), 'path' => $path, 'attrs' => $sc];
    }

    private function cookieHeader(string $ruta): string
    {
        $ruta = (string) parse_url($ruta, PHP_URL_PATH);
        $out = [];
        foreach ($this->jar as $n => $c) {
            if ($c['path'] === '/' || $ruta === $c['path'] || str_starts_with($ruta, rtrim($c['path'], '/') . '/')) {
                $out[] = $n . '=' . rawurlencode($c['valor']);
            }
        }
        return implode('; ', $out);
    }

    public function valor(string $cookie): ?string
    {
        return $this->jar[$cookie]['valor'] ?? null;
    }

    public function csrf(): string
    {
        return (string) $this->valor('csrf');
    }

    public function post(string $ruta, array $body = [], array $headers = []): array
    {
        return $this->pedir('POST', $ruta, $body, array_merge(['X-CSRF-Token' => $this->csrf()], $headers));
    }
}

function login(Nav $n, string $usuario, string $pass): array
{
    $g = $n->pedir('GET', '/login', null, [], false);
    preg_match('/name="_csrf" value="([^"]+)"/', $g['body'], $m);
    return $n->pedir('POST', '/login', ['usuario' => $usuario, 'password' => $pass], ['X-CSRF-Token' => $m[1] ?? '']);
}

function navLogueado(string $usuario, string $pass): Nav
{
    $n = new Nav();
    $r = login($n, $usuario, $pass);
    afirmar($r['status'] === 200, "login de $usuario debería dar 200 (dio {$r['status']}: {$r['body']})");
    return $n;
}

function limpiarLimites(): void
{
    db()->exec('DELETE FROM limites_tasa');
}

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function forjarJwt(array $claims, ?string $clave = null, string $alg = 'HS256', ?string $kid = null): string
{
    global $JWT_HEX;
    $k = $clave ?? hex2bin($JWT_HEX);
    return JWT::encode($claims, $k, $alg, $kid ?? TokenAcceso::kid(hex2bin($JWT_HEX)));
}

function claimsDe(string $jwt): array
{
    return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
}

function idUsuario(string $u): int
{
    $st = db()->prepare('SELECT id FROM usuarios WHERE usuario = ?');
    $st->execute([$u]);
    return (int) $st->fetchColumn();
}

function familiaDeRt(string $rt): ?array
{
    $st = db()->prepare('SELECT * FROM refresh_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $rt, true)]);
    return $st->fetch() ?: null;
}

// ------------------------------------------------------------ mini-framework
final class Fallo extends RuntimeException
{
}

function afirmar(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new Fallo($msg);
    }
}

$RESULTADOS = [];
function caso(string $nombre, callable $fn): void
{
    global $RESULTADOS;
    $t = microtime(true);
    try {
        $nota = $fn();
        $RESULTADOS[] = [true, $nombre, is_string($nota) ? $nota : ''];
        printf("  OK    %s%s\n", $nombre, is_string($nota) ? "  [$nota]" : '');
    } catch (Throwable $e) {
        $RESULTADOS[] = [false, $nombre, $e->getMessage()];
        printf("  FALLO %s\n        -> %s%s\n", $nombre, $e->getMessage(),
            $e instanceof Fallo ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

// ================================================================= PREPARACIÓN
echo "Preparando BD temporal " . BD . " ...\n";
$raizPdo = pdoRaiz(null);
$raizPdo->exec('DROP DATABASE IF EXISTS `' . BD . '`');
$raizPdo->exec('CREATE DATABASE `' . BD . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    [$c, $o, $e] = ejecutar([PHP, 'scripts/migrate.php', 'aplicar', '--seeds']);
    if ($c !== 0) {
        throw new RuntimeException("migrate.php falló ($c): $o $e");
    }
    [$c, $o, $e] = ejecutar([PHP, 'scripts/crear_admin.php', '--usuario=admin', '--email=admin@prueba.test', '--nombre=Admin Pruebas'], $PASS['admin'] . "\n");
    if ($c !== 0) {
        throw new RuntimeException("crear_admin.php falló ($c): $o $e");
    }
    $ins = db()->prepare(
        'INSERT INTO usuarios (sucursal_id, rol_id, usuario, email, nombre, password_hash, activo, debe_cambiar_password)
         VALUES (1, (SELECT id FROM roles WHERE codigo = ?), ?, ?, ?, ?, 1, 0)'
    );
    $ins->execute(['empleado', 'empleado', 'empleado@prueba.test', 'Empleada Pruebas', password_hash($PASS['empleado'], PASSWORD_ARGON2ID)]);
    $ins->execute(['contador', 'contador', 'contador@prueba.test', 'Contador Pruebas', password_hash($PASS['contador'], PASSWORD_ARGON2ID)]);
    $ins->execute(['empleado', 'empleado2', null, 'Empleado Dos', password_hash($PASS['empleado'], PASSWORD_ARGON2ID)]);

    iniciarServidor(PUERTO);
    echo "Servidor de pruebas en " . BASE . "\n\n";

    require __DIR__ . '/casos.php';
    require __DIR__ . '/casos_1b.php';
} catch (Throwable $e) {
    $RESULTADOS[] = [false, 'preparación/ejecución', $e->getMessage()];
    echo "ERROR: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
} finally {
    foreach (array_keys($SERVIDORES) as $p) {
        detenerServidor($p);
    }
    App\Db::cerrar();
    if (!$conservar) {
        pdoRaiz(null)->exec('DROP DATABASE IF EXISTS `' . BD . '`');
        echo "\nBD temporal " . BD . " eliminada.\n";
    }
}

$ok = count(array_filter($RESULTADOS, fn ($r) => $r[0]));
$total = count($RESULTADOS);
echo "\nResultado: $ok / $total casos OK\n";
exit($ok === $total ? 0 : 1);
