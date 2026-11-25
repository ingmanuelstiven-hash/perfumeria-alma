<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AdminUsuariosController;
use App\Controllers\ArticulosController;
use App\Controllers\AuthController;
use App\Controllers\CatalogosController;
use App\Controllers\CuentaController;
use App\Controllers\PanelController;
use App\Controllers\RecipientesController;
use App\Http\Errores;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\Mw;
use App\Security\Cookies;
use App\Security\Csrf;
use App\Security\TokenAcceso;
use App\Security\Transporte;
use App\Services\Auditoria;
use App\Services\AuthService;
use App\Services\UsuarioService;

/**
 * Kernel: arranque, cabeceras de seguridad, transporte, origen, rutas y errores.
 *
 * Orden por petición:
 *   1. Config::cargar() (si falla: 500 genérico y no se atiende nada).
 *   2. Transporte: Host == APP_URL y HTTPS (salvo local en loopback).
 *   3. Métodos no seguros: Origin / Sec-Fetch-Site / Referer del mismo origen.
 *   4. Router -> middleware de la ruta (auth -> csrf -> permiso) -> controlador.
 *   5. Cabeceras de seguridad en TODA respuesta (incluidos errores).
 */
final class App
{
    public readonly TokenAcceso $tokens;
    public readonly Csrf $csrf;
    public readonly AuthService $auth;
    public readonly UsuarioService $usuarios;
    private ?Router $router = null;

    public function __construct(public readonly Config $config)
    {
        $this->tokens = new TokenAcceso($config);
        $this->csrf = new Csrf($config);
        $this->auth = new AuthService($config, $this->tokens);
        $this->usuarios = new UsuarioService($this->auth);
    }

    public static function arrancarEntorno(): void
    {
        // php.ini de XAMPP trae Europe/Berlin: la app trabaja en hora de Colombia.
        date_default_timezone_set('America/Bogota');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        set_error_handler(static function (int $nivel, string $msg, string $archivo, int $linea): bool {
            if (!(error_reporting() & $nivel)) {
                return false;
            }
            throw new \ErrorException($msg, 0, $nivel, $archivo, $linea);
        });
    }

    /** Punto de entrada web (public/index.php). */
    public static function ejecutar(): void
    {
        self::arrancarEntorno();
        $rid = random_bytes(16);
        Log::$requestId = bin2hex($rid);
        try {
            $config = Config::cargar();
        } catch (\Throwable $e) {
            Log::error('La aplicación no arranca: configuración inválida: ' . $e->getMessage());
            $r = Errores::respuesta(null, 500);
            self::cabecerasSeguridad($r, false);
            $r->enviar();
            return;
        }
        $req = Request::desdeGlobales($config->basePath);
        $app = new self($config);
        $resp = $app->manejar($req, $rid);
        $resp->enviar($req->method === 'HEAD');
    }

    public function manejar(Request $req, ?string $rid = null): Response
    {
        $rid ??= random_bytes(16);
        Auditoria::$ip = $req->ipBinaria();
        Auditoria::$userAgent = $req->userAgent();
        Auditoria::$requestId = $rid;
        try {
            $resp = Transporte::verificar($this->config, $req);
            if ($resp === null) {
                $largo = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($largo > 1_048_576) {
                    $resp = Errores::respuesta($req, 413);
                } elseif (!$req->esMetodoSeguro() && !Csrf::origenValido($this->config, $req)) {
                    Log::aviso('Origen rechazado', ['ruta' => $req->path, 'origin' => mb_substr((string) $req->header('origin'), 0, 100)]);
                    $resp = Errores::respuesta($req, 403, 'origen_invalido');
                } else {
                    $resp = $this->router()->despachar($req);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Excepción no controlada: ' . get_class($e) . ': ' . $e->getMessage(), [
                'archivo' => basename($e->getFile()) . ':' . $e->getLine(), 'ruta' => $req->path,
            ]);
            if (Db::pdoActiva()?->inTransaction()) {
                Db::pdo()->rollBack();
            }
            $resp = Errores::respuesta($req, 500);
        }
        $resp->headers['X-Request-Id'] = bin2hex($rid);
        self::cabecerasSeguridad($resp, $req->https);
        return $resp;
    }

    public static function cabecerasSeguridad(Response $r, bool $https): void
    {
        // upgrade-insecure-requests solo con HTTPS: sobre http://127.0.0.1 haría que el
        // navegador pidiera la CSS por https y la página quedaría sin estilos.
        $r->headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self'; "
            . "img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'" . ($https ? '; upgrade-insecure-requests' : '');
        $r->headers['X-Frame-Options'] = 'DENY';
        $r->headers['X-Content-Type-Options'] = 'nosniff';
        $r->headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        $r->headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), payment=()';
        $r->headers['Cross-Origin-Opener-Policy'] = 'same-origin';
        $r->headers['Cross-Origin-Resource-Policy'] = 'same-origin';
        // Nada de lo que genera PHP se cachea (todas las páginas dinámicas son privadas).
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['Pragma'] = 'no-cache';
        if ($https) {
            $r->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
    }

    public function cookies(Request $req): Cookies
    {
        return Cookies::paraPeticion($this->config, $req);
    }

    /**
     * Token CSRF para incrustar en una página: reutiliza el de la cookie si es válido
     * para el sid (no rompe otras pestañas); si no, genera uno y lo pone en la cookie.
     */
    public function csrfParaVista(Request $req, Response $resp, string $sid, int $maxAge = 7200): string
    {
        $ck = $this->cookies($req);
        $actual = $req->cookie($ck->nombreCsrf());
        if ($actual !== null && $this->csrf->esValidoPara($actual, $sid)) {
            return $actual;
        }
        $nuevo = $this->csrf->generar($sid);
        $ck->ponerCsrf($resp, $nuevo, $maxAge);
        return $nuevo;
    }

    /** Pone las tres cookies de una sesión recién creada o renovada. */
    public function ponerCookiesSesion(Request $req, Response $resp, array $sesion, bool $nuevoCsrf): void
    {
        $ck = $this->cookies($req);
        // La cookie del access vive lo que la sesión (12 h) aunque el JWT dure 10 min:
        // así el servidor distingue "expirado" (se puede renovar / cerrar) de "sin sesión".
        $vida = max(1, $sesion['sesion_expira'] - time());
        $ck->ponerAcceso($resp, $sesion['access'], $vida);
        $ck->ponerRefresh($resp, $sesion['refresh'], $vida);
        if ($nuevoCsrf) {
            $ck->ponerCsrf($resp, $this->csrf->generar($sesion['sid']), $vida);
        }
    }

    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $r = new Router();
        $auth = new AuthController($this);
        $panel = new PanelController($this);
        $cuenta = new CuentaController($this);
        $admin = new AdminUsuariosController($this);
        $articulos = new ArticulosController($this);
        $catalogos = new CatalogosController($this);
        $recipientes = new RecipientesController($this);

        $autenticado = Mw::autenticar($this);
        $csrf = Mw::csrf($this);
        $permiso = static fn (string $p) => Mw::permiso($p);

        // Públicas (el CSRF de login/refresh/logout se valida dentro: su sid no sale del access token).
        $r->get('/login', [$auth, 'formulario']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/auth/refresh', [$auth, 'refrescar']);
        $r->post('/logout', [$auth, 'logout']);

        // Autenticadas.
        $r->get('/', [$panel, 'inicio'], [$autenticado]);
        $r->get('/me', [$panel, 'me'], [$autenticado]);
        $r->get('/cuenta/password', [$cuenta, 'formulario'], [$autenticado]);
        $r->post('/cuenta/password', [$cuenta, 'cambiar'], [$autenticado, $csrf]);

        // Administración de usuarios.
        $r->get('/admin/usuarios', [$admin, 'listar'], [$autenticado, $permiso('usuarios.ver')]);
        $r->post('/admin/usuarios', [$admin, 'crear'], [$autenticado, $csrf, $permiso('usuarios.gestionar')]);
        $r->post('/admin/usuarios/{id}/rol', [$admin, 'rol'], [$autenticado, $csrf, $permiso('usuarios.gestionar')]);
        $r->post('/admin/usuarios/{id}/activo', [$admin, 'activo'], [$autenticado, $csrf, $permiso('usuarios.gestionar')]);
        $r->post('/admin/usuarios/{id}/desbloquear', [$admin, 'desbloquear'], [$autenticado, $csrf, $permiso('usuarios.gestionar')]);
        $r->post('/admin/bloqueos/ip', [$admin, 'desbloquearIp'], [$autenticado, $csrf, $permiso('usuarios.gestionar')]);

        // Sprint 1B: catálogo interno. Lectura: articulos.ver / inventario.ver; escritura: articulos.gestionar + CSRF.
        // Rutas fijas (nuevo) antes que las de {id}: el router solo admite dígitos en {id}, no hay choque.
        $ver = $permiso('articulos.ver');
        $gestionar = $permiso('articulos.gestionar');
        $r->get('/inventario/articulos', [$articulos, 'listar'], [$autenticado, $ver]);
        $r->get('/inventario/articulos/nuevo', [$articulos, 'nuevo'], [$autenticado, $gestionar]);
        $r->post('/inventario/articulos', [$articulos, 'crear'], [$autenticado, $csrf, $gestionar]);
        $r->get('/inventario/articulos/{id}', [$articulos, 'ver'], [$autenticado, $ver]);
        $r->get('/inventario/articulos/{id}/editar', [$articulos, 'editarForm'], [$autenticado, $gestionar]);
        $r->post('/inventario/articulos/{id}', [$articulos, 'editar'], [$autenticado, $csrf, $gestionar]);
        $r->post('/inventario/articulos/{id}/activo', [$articulos, 'activo'], [$autenticado, $csrf, $gestionar]);

        $verInv = $permiso('inventario.ver');
        $r->get('/inventario/recipientes', [$recipientes, 'listar'], [$autenticado, $verInv]);
        $r->get('/inventario/recipientes/nuevo', [$recipientes, 'nuevo'], [$autenticado, $gestionar]);
        $r->post('/inventario/recipientes', [$recipientes, 'crear'], [$autenticado, $csrf, $gestionar]);
        $r->get('/inventario/recipientes/{id}/editar', [$recipientes, 'editarForm'], [$autenticado, $gestionar]);
        $r->post('/inventario/recipientes/{id}', [$recipientes, 'editar'], [$autenticado, $csrf, $gestionar]);
        $r->post('/inventario/recipientes/{id}/activo', [$recipientes, 'activo'], [$autenticado, $csrf, $gestionar]);

        $r->get('/admin/marcas', [$catalogos, 'marcas'], [$autenticado, $ver]);
        $r->post('/admin/marcas', [$catalogos, 'crearMarca'], [$autenticado, $csrf, $gestionar]);
        $r->post('/admin/marcas/{id}', [$catalogos, 'renombrarMarca'], [$autenticado, $csrf, $gestionar]);
        $r->post('/admin/marcas/{id}/activo', [$catalogos, 'activarMarca'], [$autenticado, $csrf, $gestionar]);
        $r->get('/admin/categorias', [$catalogos, 'categorias'], [$autenticado, $ver]);
        $r->post('/admin/categorias', [$catalogos, 'crearCategoria'], [$autenticado, $csrf, $gestionar]);
        $r->post('/admin/categorias/{id}', [$catalogos, 'editarCategoria'], [$autenticado, $csrf, $gestionar]);
        $r->post('/admin/categorias/{id}/activo', [$catalogos, 'activarCategoria'], [$autenticado, $csrf, $gestionar]);

        return $this->router = $r;
    }
}
