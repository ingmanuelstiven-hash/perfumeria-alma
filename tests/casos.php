<?php

declare(strict_types=1);

/**
 * Casos de prueba (incluido por tests/run.php, que prepara BD, usuarios y servidor).
 * Variables disponibles: $PASS, $JWT_HEX, $CSRF_HEX, $ENV.
 */

use App\App;
use App\Config;
use App\Db;
use App\Http\Request;
use App\Http\Response;
use App\Middleware\Mw;
use App\Security\Cookies;
use App\Security\TokenAcceso;
use App\Security\Transporte;
use App\Services\Permisos;

$secretosVistos = [];   // tokens y contraseñas que no deben aparecer en auditoría

echo "== Cabeceras, errores y transporte ==\n";

caso('Cabeceras de seguridad en GET /login', function () {
    $r = (new Nav())->pedir('GET', '/login', null, [], false);
    $h = $r['headers'];
    afirmar($r['status'] === 200, 'status ' . $r['status']);
    $csp = $h['content-security-policy'][0] ?? '';
    foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'none'", "form-action 'self'"] as $d) {
        afirmar(str_contains($csp, $d), "CSP sin $d");
    }
    afirmar(!str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'unsafe-eval'), 'CSP con unsafe-*');
    afirmar(($h['x-content-type-options'][0] ?? '') === 'nosniff', 'sin nosniff');
    afirmar(($h['x-frame-options'][0] ?? '') === 'DENY', 'sin X-Frame-Options DENY');
    afirmar(($h['referrer-policy'][0] ?? '') === 'strict-origin-when-cross-origin', 'sin Referrer-Policy');
    afirmar(isset($h['permissions-policy']), 'sin Permissions-Policy');
    afirmar(($h['cache-control'][0] ?? '') === 'no-store', 'sin Cache-Control no-store');
    afirmar(!isset($h['x-powered-by']), 'expone X-Powered-By');
    afirmar(!isset($h['strict-transport-security']), 'HSTS sobre http');
    afirmar(!preg_match('/<script(?![^>]*\bsrc=)/i', $r['body']) && !preg_match('/\son[a-z]+=/i', $r['body']), 'script u on* en línea en la página');
});

caso('404/405 genéricos sin reflejar la ruta', function () {
    $n = new Nav();
    $r = $n->pedir('GET', '/%3Cscript%3Ealert(1)%3C/script%3E', null, [], false);
    afirmar($r['status'] === 404, 'status ' . $r['status']);
    afirmar(!str_contains($r['body'], '<script>alert') && !str_contains($r['body'], 'alert(1)'), 'refleja la ruta');
    $r = $n->pedir('GET', '/no-existe');
    afirmar($r['status'] === 404 && ($r['json']['error'] ?? '') === 'no_encontrado', 'JSON 404');
    $r = $n->pedir('DELETE', '/login');
    afirmar($r['status'] === 405, '405 esperado, dio ' . $r['status']);
});

caso('Estáticos servidos; código fuente y .php ajenos no accesibles', function () {
    $n = new Nav();
    $css = $n->pedir('GET', '/assets/css/app.css', null, [], false);
    afirmar($css['status'] === 200 && str_contains($css['headers']['content-type'][0] ?? '', 'text/css'), 'CSS: ' . $css['status']);
    afirmar($n->pedir('GET', '/assets/js/renovar.js', null, [], false)['status'] === 200, 'JS');
    foreach (['/../src/Config.php', '/%2e%2e/src/Config.php', '/../.env.example', '/index.php/../../composer.json'] as $ruta) {
        $r = $n->pedir('GET', $ruta, null, [], false);
        afirmar(!str_contains($r['body'], 'namespace App') && !str_contains($r['body'], 'JWT_SECRET=') && !str_contains($r['body'], 'firebase/php-jwt'),
            "$ruta expone contenido ({$r['status']})");
    }
});

caso('Host distinto de APP_URL: GET redirige, POST 400', function () {
    $n = new Nav();
    $r = $n->pedir('GET', '/login', null, ['Host' => 'evil.test'], false);
    afirmar($r['status'] === 302 && ($r['headers']['location'][0] ?? '') === BASE . '/', 'GET: ' . $r['status']);
    $r = $n->pedir('POST', '/login', ['usuario' => 'a', 'password' => 'b'], ['Host' => 'evil.test']);
    afirmar($r['status'] === 400, 'POST: ' . $r['status']);
});

echo "== Login ==\n";

caso('Login sin CSRF previo a la sesión => 403 y queda registrado', function () use ($PASS) {
    $n = new Nav();
    $r = $n->pedir('POST', '/login', ['usuario' => 'admin', 'password' => $PASS['admin']]);
    afirmar($r['status'] === 403 && ($r['json']['error'] ?? '') === 'csrf_invalido', 'status ' . $r['status']);
    afirmar(!$n->valor('at'), 'emitió sesión');
    $c = (int) db()->query("SELECT COUNT(*) FROM login_intentos WHERE motivo = 'csrf'")->fetchColumn();
    afirmar($c >= 1, 'sin registro en login_intentos');
});

caso('Login correcto (JSON): cookies con flags correctos', function () use ($PASS, &$secretosVistos) {
    $n = new Nav();
    $r = login($n, 'admin', $PASS['admin']);
    afirmar($r['status'] === 200 && ($r['json']['ok'] ?? false) === true, 'status ' . $r['status'] . ' ' . $r['body']);
    afirmar(($r['json']['usuario']['rol'] ?? '') === 'admin', 'rol');
    $porNombre = [];
    foreach ($r['cookies'] as $c) {
        $porNombre[explode('=', $c, 2)[0]] = $c;
    }
    foreach (['at' => 'Path=/', 'rt' => 'Path=/auth/refresh', 'csrf' => 'Path=/'] as $nombre => $path) {
        $c = $porNombre[$nombre] ?? '';
        afirmar($c !== '', "falta cookie $nombre");
        afirmar(str_contains($c, '; HttpOnly'), "$nombre sin HttpOnly");
        afirmar(str_contains($c, '; SameSite=Strict'), "$nombre sin SameSite=Strict");
        afirmar(str_contains($c, "; $path;") || str_ends_with($c, "; $path"), "$nombre sin $path");
        afirmar(stripos($c, 'domain=') === false, "$nombre con Domain");
        afirmar(stripos($c, '; secure') === false, "$nombre con Secure en local/http (no debería)");
    }
    $claims = claimsDe($n->valor('at'));
    foreach (['iss', 'aud', 'sub', 'sid', 'rol', 'tv', 'jti', 'iat', 'nbf', 'exp'] as $k) {
        afirmar(array_key_exists($k, $claims), "claim $k ausente");
    }
    afirmar($claims['exp'] - $claims['iat'] === 600, 'vida del access distinta de 600 s');
    afirmar(!isset($claims['permisos']), 'permisos en el JWT');
    $secretosVistos[] = $n->valor('rt');
    $secretosVistos[] = $n->valor('at');
    return 'claims: ' . implode(',', array_keys($claims));
});

caso('Login por formulario HTML: 303 a / y panel con el nombre', function () use ($PASS) {
    $n = new Nav();
    $g = $n->pedir('GET', '/login', null, [], false);
    preg_match('/name="_csrf" value="([^"]+)"/', $g['body'], $m);
    $r = $n->pedir('POST', '/login', ['usuario' => 'empleado', 'password' => $PASS['empleado'], '_csrf' => $m[1]], [], false, true);
    afirmar($r['status'] === 303 && ($r['headers']['location'][0] ?? '') === '/', 'status ' . $r['status']);
    $p = $n->pedir('GET', '/', null, [], false);
    afirmar($p['status'] === 200 && str_contains($p['body'], 'Empleada Pruebas'), 'panel ' . $p['status']);
    afirmar(($p['headers']['cache-control'][0] ?? '') === 'no-store', 'panel sin no-store');
    $sin = (new Nav())->pedir('GET', '/', null, [], false);
    afirmar($sin['status'] === 303 && ($sin['headers']['location'][0] ?? '') === '/login', 'sin sesión no redirige a /login');
});

caso('Fallo de login: misma respuesta y tiempo similar (inexistente vs clave mala)', function () {
    limpiarLimites();
    $tInex = [];
    $tMala = [];
    $cuerpos = [];
    for ($i = 0; $i < 4; $i++) {
        $n = new Nav();
        $n->pedir('GET', '/login', null, [], false);
        $t = microtime(true);
        $a = $n->post('/login', ['usuario' => 'noexiste' . bin2hex(random_bytes(3)), 'password' => 'Clave-Cualquiera-123']);
        $tInex[] = microtime(true) - $t;
        $t = microtime(true);
        $b = $n->post('/login', ['usuario' => 'contador', 'password' => 'Clave-Erronea-' . $i]);
        $tMala[] = microtime(true) - $t;
        afirmar($a['status'] === 401 && $b['status'] === 401, "status {$a['status']}/{$b['status']}");
        $cuerpos[] = $a['body'];
        $cuerpos[] = $b['body'];
    }
    afirmar(count(array_unique($cuerpos)) === 1, 'cuerpos distintos: ' . implode(' | ', array_unique($cuerpos)));
    sort($tInex);
    sort($tMala);
    $mi = ($tInex[1] + $tInex[2]) / 2 * 1000;
    $mm = ($tMala[1] + $tMala[2]) / 2 * 1000;
    afirmar($mi > 40 && $mm > 40, sprintf('no parece calcular hash (%.0f / %.0f ms)', $mi, $mm));
    afirmar(max($mi, $mm) / min($mi, $mm) < 1.6, sprintf('tiempos muy distintos: inexistente %.0f ms, clave mala %.0f ms', $mi, $mm));
    limpiarLimites();
    return sprintf('mediana inexistente %.0f ms / clave mala %.0f ms', $mi, $mm);
});

caso('SQL injection básica en login => 401 genérico, sin sesión', function () {
    limpiarLimites();
    $base = null;
    foreach (["' OR '1'='1' -- ", "admin'#", "admin' -- ", "\" OR \"\"=\"", "admin' OR 1=1 LIMIT 1 -- "] as $u) {
        $n = new Nav();
        $n->pedir('GET', '/login', null, [], false);
        $r = $n->post('/login', ['usuario' => $u, 'password' => "' OR '1'='1"]);
        afirmar($r['status'] === 401, "status {$r['status']} con $u");
        $base ??= $r['body'];
        afirmar($r['body'] === $base, 'cuerpo distinto');
        afirmar($n->valor('at') === null, 'emitió access token');
    }
    limpiarLimites();
});

caso('XSS reflejado en la página de login (valor del usuario escapado)', function () {
    limpiarLimites();
    $n = new Nav();
    $g = $n->pedir('GET', '/login', null, [], false);
    preg_match('/name="_csrf" value="([^"]+)"/', $g['body'], $m);
    $x = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
    $r = $n->pedir('POST', '/login', ['usuario' => $x, 'password' => 'x', '_csrf' => $m[1]], [], false, true);
    afirmar($r['status'] === 401, 'status ' . $r['status']);
    afirmar(!str_contains($r['body'], '<script>alert(1)') && !str_contains($r['body'], '<img src=x'), 'no escapó');
    afirmar(str_contains($r['body'], '&quot;&gt;&lt;script&gt;'), 'no aparece escapado');
    limpiarLimites();
});

caso('Rehash transparente (bcrypt -> Argon2id) al iniciar sesión', function () use ($PASS) {
    db()->prepare('UPDATE usuarios SET password_hash = ? WHERE usuario = ?')
        ->execute([password_hash($PASS['contador'], PASSWORD_BCRYPT, ['cost' => 10]), 'contador']);
    navLogueado('contador', $PASS['contador']);
    $h = (string) db()->query("SELECT password_hash FROM usuarios WHERE usuario = 'contador'")->fetchColumn();
    afirmar(str_starts_with($h, '$argon2id$'), 'no se rehasheó: ' . substr($h, 0, 10));
});

echo "== JWT ==\n";

caso('JWT manipulado / alg=none / otra clave / HS512 / kid ajeno / expirado => rechazados', function () use ($PASS) {
    $n = navLogueado('empleado', $PASS['empleado']);
    $at = $n->valor('at');
    $c = claimsDe($at);
    $probar = function (string $jwt, string $que, string $error = 'no_autenticado') use ($n) {
        $m = $n->clonar();
        $m->jar['at']['valor'] = $jwt;
        $r = $m->pedir('GET', '/me');
        afirmar($r['status'] === 401, "$que: status {$r['status']}");
        afirmar(($r['json']['error'] ?? '') === $error, "$que: error " . ($r['json']['error'] ?? '?'));
    };
    // 1. Payload cambiado (rol=admin) conservando la firma original.
    [$h, , $s] = explode('.', $at);
    $probar($h . '.' . b64u(json_encode(['rol' => 'admin'] + $c)) . '.' . $s, 'payload manipulado');
    // 2. alg=none sin firma.
    $probar(b64u(json_encode(['typ' => 'JWT', 'alg' => 'none', 'kid' => TokenAcceso::kid(hex2bin($GLOBALS['JWT_HEX']))]))
        . '.' . b64u(json_encode(['rol' => 'admin'] + $c)) . '.', 'alg=none');
    // 3. Firmado con otra clave.
    $probar(forjarJwt(['rol' => 'admin'] + $c, random_bytes(32)), 'otra clave');
    // 4. HS512 con la clave buena (confusión de algoritmo).
    // (a mano: php-jwt 7 se niega a firmar HS512 con una clave de 256 bits)
    $cab = b64u(json_encode(['typ' => 'JWT', 'alg' => 'HS512', 'kid' => TokenAcceso::kid(hex2bin($GLOBALS['JWT_HEX']))]));
    $cue = b64u(json_encode($c));
    $probar("$cab.$cue." . b64u(hash_hmac('sha512', "$cab.$cue", hex2bin($GLOBALS['JWT_HEX']), true)), 'HS512');
    // 5. kid desconocido.
    $probar(forjarJwt($c, null, 'HS256', 'ffffffffffffffff'), 'kid ajeno');
    // 6. Expirado (firma válida) => sesion_expirada.
    $probar(forjarJwt(['iat' => time() - 1000, 'nbf' => time() - 1000, 'exp' => time() - 300] + $c), 'expirado', 'sesion_expirada');
    // 7. Emisor distinto con firma válida.
    $probar(forjarJwt(['iss' => 'https://otro.test'] + $c), 'iss ajeno');
    // 8. Token válido pero con tv antiguo (firma válida): revocación global.
    $probar(forjarJwt(['tv' => $c['tv'] + 5] + $c), 'tv distinto');
    // Control: el original sigue funcionando.
    afirmar($n->pedir('GET', '/me')['status'] === 200, 'el token legítimo dejó de funcionar');
});

caso('Access expirado en navegación HTML => página de renovación (POST /auth/refresh)', function () use ($PASS) {
    $n = navLogueado('empleado', $PASS['empleado']);
    $c = claimsDe($n->valor('at'));
    $n->jar['at']['valor'] = forjarJwt(['iat' => time() - 900, 'nbf' => time() - 900, 'exp' => time() - 120] + $c);
    $r = $n->pedir('GET', '/', null, [], false);
    afirmar($r['status'] === 401 && str_contains($r['body'], 'id="form-renovar"'), 'status ' . $r['status']);
    afirmar(str_contains($r['body'], 'data-auto="1"') && str_contains($r['body'], '/assets/js/renovar.js'), 'sin autoenvío');
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    $f = $n->pedir('POST', '/auth/refresh', ['_csrf' => $m[1], 'siguiente' => '/'], [], false, true);
    afirmar($f['status'] === 303 && ($f['headers']['location'][0] ?? '') === '/', 'refresh por formulario: ' . $f['status']);
    afirmar($n->pedir('GET', '/', null, [], false)['status'] === 200, 'tras renovar no entra');
    // Redirección abierta: siguiente=//evil.test se ignora.
    $n2 = navLogueado('empleado', $PASS['empleado']);
    $f = $n2->pedir('POST', '/auth/refresh', ['_csrf' => $n2->csrf(), 'siguiente' => '//evil.test/x'], [], false, true);
    afirmar(($f['headers']['location'][0] ?? '') === '/', 'redirección abierta: ' . ($f['headers']['location'][0] ?? ''));
});

echo "== Refresh ==\n";

$ctxRefresh = [];
caso('Refresh rotativo: token nuevo, eslabón anterior marcado y enlazado', function () use ($PASS, &$ctxRefresh, &$secretosVistos) {
    $n = navLogueado('empleado', $PASS['empleado']);
    $rt1 = $n->valor('rt');
    $at1 = $n->valor('at');
    $r = $n->post('/auth/refresh');
    afirmar($r['status'] === 200 && ($r['json']['ok'] ?? false), 'status ' . $r['status'] . ' ' . $r['body']);
    $rt2 = $n->valor('rt');
    afirmar($rt2 !== null && $rt2 !== $rt1, 'no rotó el refresh');
    afirmar($n->valor('at') !== $at1, 'no emitió access nuevo');
    afirmar(claimsDe($n->valor('at'))['sid'] === claimsDe($at1)['sid'], 'cambió el sid (familia)');
    $f1 = familiaDeRt($rt1);
    $f2 = familiaDeRt($rt2);
    afirmar($f1['usado_en'] !== null && (int) $f1['reemplazado_por'] === (int) $f2['id'], 'eslabón anterior no enlazado');
    afirmar($f1['familia_id'] === $f2['familia_id'] && $f1['sesion_expira_en'] === $f2['sesion_expira_en'], 'familia/tope absoluto distintos');
    afirmar(strlen($f2['token_hash']) === 32 && $f2['token_hash'] !== $rt2, 'token sin hashear');
    afirmar($n->pedir('GET', '/me')['status'] === 200, 'access nuevo no sirve');
    $ctxRefresh = ['nav' => $n, 'rt1' => $rt1, 'rt2' => $rt2];
    $secretosVistos[] = $rt1;
    $secretosVistos[] = $rt2;
});

caso('Carrera de pestañas: reuso dentro de la gracia => 409 sin revocar', function () use (&$ctxRefresh) {
    $viejo = $ctxRefresh['nav']->clonar();
    $viejo->jar['rt']['valor'] = $ctxRefresh['rt1'];
    $r = $viejo->post('/auth/refresh');
    afirmar($r['status'] === 409 && ($r['json']['error'] ?? '') === 'refresh_en_curso', 'status ' . $r['status'] . ' ' . $r['body']);
    afirmar(familiaDeRt($ctxRefresh['rt2'])['revocado_en'] === null, 'revocó la familia en la gracia');
    afirmar($ctxRefresh['nav']->pedir('GET', '/me')['status'] === 200, 'la sesión legítima cayó');
});

caso('Reutilización fuera de la gracia => revoca toda la familia y se audita', function () use (&$ctxRefresh) {
    $n = $ctxRefresh['nav'];
    db()->prepare('UPDATE refresh_tokens SET usado_en = NOW() - INTERVAL 60 SECOND WHERE token_hash = ?')
        ->execute([hash('sha256', $ctxRefresh['rt1'], true)]);
    $atacante = $n->clonar();
    $atacante->jar['rt']['valor'] = $ctxRefresh['rt1'];
    $r = $atacante->post('/auth/refresh');
    afirmar($r['status'] === 401 && ($r['json']['error'] ?? '') === 'sesion_invalida', 'status ' . $r['status']);
    $fam = familiaDeRt($ctxRefresh['rt1'])['familia_id'];
    $st = db()->prepare('SELECT COUNT(*) total, SUM(revocado_en IS NOT NULL AND motivo_revocacion = \'reutilizacion\') rev FROM refresh_tokens WHERE familia_id = ?');
    $st->execute([$fam]);
    $x = $st->fetch();
    afirmar((int) $x['total'] >= 2 && (int) $x['rev'] >= 1 && (int) db()->query(
        'SELECT COUNT(*) FROM refresh_tokens WHERE revocado_en IS NULL AND familia_id = ' . db()->quote($fam))->fetchColumn() === 0,
        'familia no revocada por completo');
    afirmar($n->pedir('GET', '/me')['status'] === 401, 'el access de la familia sigue vivo');
    afirmar($n->post('/auth/refresh')['status'] === 401, 'el refresh vigente de la familia sigue vivo');
    $a = (int) db()->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'sesion.refresh_reutilizado'")->fetchColumn();
    afirmar($a >= 1, 'sin auditoría de reutilización');
});

caso('Carrera real (4 procesos simultáneos, FOR UPDATE): 1 rota, 3 en gracia, 1 sucesor', function () use ($PASS) {
    $n = navLogueado('empleado', $PASS['empleado']);
    $rt = $n->valor('rt');
    $inicio = (int) floor(microtime(true) * 1000) + 1500;
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $procs[] = proc_open([PHP, 'tests/aux_refresh_concurrente.php', $rt, (string) $inicio],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t, $GLOBALS['RAIZ'], entornoHijo());
        $tub[] = $t;
    }
    $estados = [];
    foreach ($procs as $i => $p) {
        $estados[] = trim((string) stream_get_contents($tub[$i][1])) ?: ('error:' . stream_get_contents($tub[$i][2]));
        proc_close($p);
    }
    sort($estados);
    afirmar($estados === ['gracia', 'gracia', 'gracia', 'ok'], 'estados: ' . implode(',', $estados));
    $fam = familiaDeRt($rt)['familia_id'];
    $st = db()->prepare('SELECT COUNT(*) FROM refresh_tokens WHERE familia_id = ?');
    $st->execute([$fam]);
    afirmar((int) $st->fetchColumn() === 2, 'se crearon varios sucesores');
    afirmar(familiaDeRt($rt)['revocado_en'] === null, 'revocó en la carrera');
    return implode(',', $estados);
});

caso('Refresh: sin CSRF, CSRF de otra sesión u otro Origin => 403 sin rotar', function () use ($PASS) {
    $a = navLogueado('empleado', $PASS['empleado']);
    $b = navLogueado('empleado', $PASS['empleado']);
    $rt = $a->valor('rt');
    afirmar($a->pedir('POST', '/auth/refresh', [])['status'] === 403, 'sin CSRF');
    $r = $a->pedir('POST', '/auth/refresh', [], ['X-CSRF-Token' => $b->csrf()]);
    afirmar($r['status'] === 403, 'token de otra sesión (sin cookie igual): ' . $r['status']);
    $m = $a->clonar();
    $m->jar['csrf']['valor'] = $b->csrf();   // doble envío coherente pero firmado para otro sid
    afirmar($m->post('/auth/refresh')['status'] === 403, 'cookie+cabecera de otra sesión');
    afirmar($a->post('/auth/refresh', [], ['Origin' => 'https://evil.test'])['status'] === 403, 'Origin ajeno');
    afirmar(familiaDeRt($rt)['usado_en'] === null, 'rotó pese al 403');
});

caso('Refresh caducado por inactividad y por tope absoluto => 401', function () use ($PASS) {
    $n = navLogueado('empleado', $PASS['empleado']);
    db()->prepare('UPDATE refresh_tokens SET creado_en = NOW() - INTERVAL 3 HOUR, expira_en = NOW() - INTERVAL 1 HOUR WHERE token_hash = ?')
        ->execute([hash('sha256', $n->valor('rt'), true)]);
    afirmar($n->post('/auth/refresh')['status'] === 401, 'inactividad');
    $m = navLogueado('empleado', $PASS['empleado']);
    db()->prepare('UPDATE refresh_tokens SET creado_en = NOW() - INTERVAL 13 HOUR, expira_en = NOW() - INTERVAL 61 MINUTE,
                   sesion_expira_en = NOW() - INTERVAL 1 HOUR WHERE token_hash = ?')
        ->execute([hash('sha256', $m->valor('rt'), true)]);
    afirmar($m->post('/auth/refresh')['status'] === 401, 'tope absoluto');
    afirmar($m->pedir('GET', '/me')['status'] === 401, 'access de sesión caducada sigue vivo');
});

caso('Logout: borra cookies, revoca familia y jti; tokens viejos inservibles', function () use ($PASS) {
    $n = navLogueado('contador', $PASS['contador']);
    $copia = $n->clonar();
    afirmar($n->pedir('POST', '/logout', [])['status'] === 403, 'logout sin CSRF aceptado');
    $r = $n->post('/logout');
    afirmar($r['status'] === 200, 'status ' . $r['status']);
    $borradas = array_filter($r['cookies'], fn ($c) => str_contains($c, 'Max-Age=0'));
    afirmar(count($borradas) === 3, 'no borró las 3 cookies');
    $jti = claimsDe($copia->valor('at'))['jti'];
    $st = db()->prepare('SELECT motivo FROM jwt_revocados WHERE jti = ?');
    $st->execute([hex2bin($jti)]);
    afirmar($st->fetchColumn() === 'logout', 'jti no revocado');
    afirmar(familiaDeRt($copia->valor('rt'))['motivo_revocacion'] === 'logout', 'familia no revocada');
    afirmar($copia->pedir('GET', '/me')['status'] === 401, 'access viejo sigue vivo');
    afirmar($copia->post('/auth/refresh')['status'] === 401, 'refresh viejo sigue vivo');
});

echo "== CSRF y origen ==\n";

caso('CSRF en POST /cuenta/password: faltante, inválido, de otra sesión, otro origen', function () use ($PASS) {
    $a = navLogueado('empleado', $PASS['empleado']);
    $b = navLogueado('contador', $PASS['contador']);
    $datos = ['actual' => $PASS['empleado'], 'nueva' => 'x', 'confirmacion' => 'x'];
    $esperar403 = function (array $r, string $que, string $error = 'csrf_invalido') {
        afirmar($r['status'] === 403 && ($r['json']['error'] ?? '') === $error, "$que: {$r['status']} " . ($r['json']['error'] ?? ''));
    };
    $esperar403($a->pedir('POST', '/cuenta/password', $datos), 'sin token');
    $esperar403($a->pedir('POST', '/cuenta/password', $datos, ['X-CSRF-Token' => 'abc.def']), 'token basura');
    $t = explode('.', $a->csrf());
    $esperar403($a->pedir('POST', '/cuenta/password', $datos, ['X-CSRF-Token' => $t[0] . '.' . b64u(random_bytes(32))]), 'HMAC falso');
    $m = $a->clonar();
    $m->jar['csrf']['valor'] = $b->csrf();
    $esperar403($m->post('/cuenta/password', $datos), 'token de otra sesión (doble envío coherente)');
    $esperar403($a->post('/cuenta/password', $datos, ['Origin' => 'https://evil.test']), 'Origin ajeno', 'origen_invalido');
    $esperar403($a->post('/cuenta/password', $datos, ['Origin' => 'null']), 'Origin null', 'origen_invalido');
    $esperar403($a->post('/cuenta/password', $datos, ['Origin' => null]), 'sin Origin/Sec-Fetch-Site/Referer', 'origen_invalido');
    $esperar403($a->post('/cuenta/password', $datos, ['Origin' => null, 'Sec-Fetch-Site' => 'cross-site']), 'Sec-Fetch-Site cross-site', 'origen_invalido');
    $esperar403($a->post('/cuenta/password', $datos, ['Origin' => null, 'Referer' => 'https://evil.test/x']), 'Referer ajeno', 'origen_invalido');
    // Con Sec-Fetch-Site same-origin y CSRF válido sí pasa la capa de origen (falla la política: 422).
    $r = $a->post('/cuenta/password', $datos, ['Origin' => null, 'Sec-Fetch-Site' => 'same-origin']);
    afirmar($r['status'] === 422, 'Sec-Fetch-Site same-origin legítimo: ' . $r['status']);
});

caso('Todas las rutas POST rechazan peticiones sin token CSRF', function () use ($PASS) {
    $n = navLogueado('admin', $PASS['admin']);
    $rutas = (new App(Config::cargar()))->router()->listado();
    $probadas = 0;
    foreach ($rutas as $r) {
        if ($r['method'] !== 'POST') {
            continue;
        }
        $ruta = str_replace('{id}', (string) idUsuario('empleado2'), $r['ruta']);
        $res = $n->pedir('POST', $ruta, ['ip' => '127.0.0.1', 'rol' => 'admin', 'activo' => '0']);
        afirmar($res['status'] === 403, "$ruta aceptó sin CSRF ({$res['status']})");
        $probadas++;
    }
    afirmar($n->pedir('GET', '/me')['status'] === 200, 'la sesión del admin se perdió');
    return "$probadas rutas POST";
});

echo "== Autorización ==\n";

caso('Matriz de permisos por rol (seed: 58 permisos, 89 asignaciones)', function () use ($PASS) {
    afirmar((int) db()->query('SELECT COUNT(*) FROM permisos')->fetchColumn() === 58, 'no hay 58 permisos');
    afirmar((int) db()->query('SELECT COUNT(*) FROM rol_permisos')->fetchColumn() === 89, 'no hay 89 asignaciones');
    $perm = [];
    foreach (['admin', 'empleado', 'contador'] as $u) {
        $r = navLogueado($u, $PASS[$u])->pedir('GET', '/me');
        afirmar($r['status'] === 200, "/me $u");
        $perm[$u] = $r['json']['permisos'];
    }
    afirmar(count($perm['admin']) === 58 && count($perm['empleado']) === 19 && count($perm['contador']) === 12,
        sprintf('conteos %d/%d/%d', count($perm['admin']), count($perm['empleado']), count($perm['contador'])));
    afirmar(!in_array('inventario.ver_costos', $perm['empleado'], true), 'EMPLEADO ve costos');
    foreach ($perm['empleado'] as $p) {
        afirmar(!preg_match('/^(usuarios|roles|sesiones|config|respaldos|auditoria|compras|cxp|gastos|cierres|reportes)\./', $p)
            && !in_array($p, ['ventas.anular', 'ventas.ver_todas', 'inventario.ajustar', 'precios.gestionar', 'reportes.financieros'], true),
            "empleado con permiso indebido $p");
    }
    afirmar(in_array('inventario.ver_costos', $perm['contador'], true), 'contador sin costos');
    foreach ($perm['contador'] as $p) {
        afirmar((bool) preg_match('/\.(ver|ver_todas|ver_costos|financieros)$/', $p), "contador con permiso de escritura $p");
    }
    return 'admin 58 / empleado 19 / contador 12';
});

caso('requierePermiso en proceso: costos y escritura según rol (lee la BD, no el JWT)', function () {
    $mw = fn (string $p) => Mw::permiso($p);
    $siguiente = fn (Request $q) => new Response(200);
    $req = function (string $usuario): Request {
        $st = db()->prepare('SELECT id, rol_id, sucursal_id, debe_cambiar_password FROM usuarios WHERE usuario = ?');
        $st->execute([$usuario]);
        $u = $st->fetch();
        $q = new Request('GET', '/x', ['accept' => 'application/json'], [], [], [], '127.0.0.1', false, '127.0.0.1:' . PUERTO);
        $q->set('usuario', $u);
        $q->set('permisos', Permisos::efectivos($u));
        return $q;
    };
    Permisos::limpiarCache();
    $casos = [
        ['empleado', 'inventario.ver_costos', 403], ['contador', 'inventario.ver_costos', 200], ['admin', 'inventario.ver_costos', 200],
        ['empleado', 'ventas.crear', 200], ['contador', 'ventas.crear', 403], ['contador', 'compras.gestionar', 403],
        ['contador', 'reportes.financieros', 200], ['empleado', 'usuarios.gestionar', 403], ['contador', 'auditoria.ver', 200],
        ['empleado', 'auditoria.ver', 403],
    ];
    foreach ($casos as [$u, $p, $esperado]) {
        $s = $mw($p)($req($u), $siguiente)->status;
        afirmar($s === $esperado, "$u / $p => $s (esperado $esperado)");
    }
    // Quitar un permiso al rol en BD rige en la siguiente petición (no hay permisos en el token).
    db()->exec("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = 3 AND p.codigo = 'auditoria.ver'");
    Permisos::limpiarCache();
    $s = $mw('auditoria.ver')($req('contador'), $siguiente)->status;
    db()->exec("INSERT INTO rol_permisos (rol_id, permiso_id) SELECT 3, id FROM permisos WHERE codigo = 'auditoria.ver'");
    Permisos::limpiarCache();
    afirmar($s === 403, 'permiso retirado en BD sigue vigente');
    return count($casos) . ' combinaciones';
});

caso('Escalada horizontal: /admin/usuarios solo para admin', function () use ($PASS) {
    $e = navLogueado('empleado', $PASS['empleado']);
    $c = navLogueado('contador', $PASS['contador']);
    $idE = idUsuario('empleado');
    afirmar($e->pedir('GET', '/admin/usuarios')['status'] === 403, 'empleado lista usuarios');
    afirmar($e->pedir('GET', '/admin/usuarios', null, [], false)['status'] === 403, 'empleado lista usuarios (HTML)');
    afirmar($e->post('/admin/usuarios', ['usuario' => 'intruso', 'nombre' => 'Intruso', 'rol' => 'admin', 'password' => 'Intruso-Clave-2026'])['status'] === 403, 'empleado crea usuario');
    afirmar($e->post("/admin/usuarios/$idE/rol", ['rol' => 'admin'])['status'] === 403, 'empleado se asciende');
    afirmar($c->pedir('GET', '/admin/usuarios')['status'] === 403, 'contador lista usuarios');
    afirmar($c->post("/admin/usuarios/$idE/activo", ['activo' => '0'])['status'] === 403, 'contador desactiva');
    afirmar((int) db()->query("SELECT COUNT(*) FROM usuarios WHERE usuario = 'intruso'")->fetchColumn() === 0, 'se creó el intruso');
    afirmar((int) db()->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'acceso.denegado'")->fetchColumn() >= 5, 'denegaciones sin auditar');
    $me = $e->pedir('GET', '/me')['json'];
    afirmar($me['usuario']['rol'] === 'empleado', 'el rol cambió');
});

echo "== Gestión de usuarios y revocación ==\n";

$ctxNuevo = [];
caso('Admin crea usuario: validación, duplicado, XSS escapado, clave temporal obligatoria', function () use ($PASS, &$ctxNuevo, &$secretosVistos) {
    $a = navLogueado('admin', $PASS['admin']);
    $temp = 'Temporal-' . bin2hex(random_bytes(5));
    $secretosVistos[] = $temp;
    $r = $a->post('/admin/usuarios', ['usuario' => 'jefa', 'nombre' => 'Débil', 'rol' => 'admin', 'password' => 'corta']);
    afirmar($r['status'] === 422 && count($r['json']['errores'] ?? []) >= 1, 'política no aplicada: ' . $r['status']);
    $r = $a->post('/admin/usuarios', ['usuario' => "x' OR 1=1 --", 'nombre' => 'X', 'rol' => 'empleado', 'password' => $temp]);
    afirmar($r['status'] === 422, 'usuario inválido aceptado');
    $r = $a->post('/admin/usuarios', ['usuario' => 'jefa', 'nombre' => 'Ana <img src=x onerror=alert(1)>', 'email' => 'jefa@prueba.test', 'rol' => 'admin', 'password' => $temp]);
    afirmar($r['status'] === 201 && isset($r['json']['id']), 'alta: ' . $r['status'] . ' ' . $r['body']);
    $r = $a->post('/admin/usuarios', ['usuario' => 'JEFA', 'nombre' => 'Otra', 'rol' => 'empleado', 'password' => $temp]);
    afirmar($r['status'] === 409, 'duplicado (mayúsculas) aceptado: ' . $r['status']);
    $h = $a->pedir('GET', '/admin/usuarios', null, [], false);
    afirmar($h['status'] === 200 && str_contains($h['body'], 'Ana &lt;img src=x onerror=alert(1)&gt;') && !str_contains($h['body'], '<img src=x'), 'XSS en listado');
    $j = navLogueado('jefa', $temp);
    $me = $j->pedir('GET', '/me')['json'];
    afirmar($me['debe_cambiar_password'] === true && $me['permisos'] === [], 'clave temporal con permisos');
    afirmar($j->pedir('GET', '/admin/usuarios')['status'] === 403, 'con clave temporal entra a admin');
    $p = $j->pedir('GET', '/', null, [], false);
    afirmar($p['status'] === 303 && ($p['headers']['location'][0] ?? '') === '/cuenta/password', 'no fuerza el cambio');
    $ctxNuevo = ['temp' => $temp, 'nav' => $j];
});

caso('Cambio de contraseña propia: exige la actual, política, token_version y revoca otras sesiones', function () use (&$ctxNuevo, &$secretosVistos) {
    $j = $ctxNuevo['nav'];
    $otra = navLogueado('jefa', $ctxNuevo['temp']);
    $nueva = 'Nueva-Clave-' . bin2hex(random_bytes(5));
    $secretosVistos[] = $nueva;
    $tv0 = (int) db()->query("SELECT token_version FROM usuarios WHERE usuario = 'jefa'")->fetchColumn();
    $r = $j->post('/cuenta/password', ['actual' => 'no-es-la-actual', 'nueva' => $nueva, 'confirmacion' => $nueva]);
    afirmar($r['status'] === 422, 'actual errónea aceptada: ' . $r['status']);
    $r = $j->post('/cuenta/password', ['actual' => $ctxNuevo['temp'], 'nueva' => 'jefa-jefa-jefa-1', 'confirmacion' => 'jefa-jefa-jefa-1']);
    afirmar($r['status'] === 422, 'política no aplicada (contiene usuario)');
    $r = $j->post('/cuenta/password', ['actual' => $ctxNuevo['temp'], 'nueva' => $nueva, 'confirmacion' => $nueva . 'x']);
    afirmar($r['status'] === 422, 'confirmación distinta aceptada');
    $r = $j->post('/cuenta/password', ['actual' => $ctxNuevo['temp'], 'nueva' => $nueva, 'confirmacion' => $nueva]);
    afirmar($r['status'] === 200, 'cambio: ' . $r['status'] . ' ' . $r['body']);
    $tv1 = (int) db()->query("SELECT token_version FROM usuarios WHERE usuario = 'jefa'")->fetchColumn();
    afirmar($tv1 === $tv0 + 1, 'token_version no subió');
    afirmar($otra->pedir('GET', '/me')['status'] === 401, 'la otra sesión sigue viva');
    afirmar($otra->post('/auth/refresh')['status'] === 401, 'el refresh de la otra sesión sigue vivo');
    $me = $j->pedir('GET', '/me');
    afirmar($me['status'] === 200 && count($me['json']['permisos']) === 58 && $me['json']['debe_cambiar_password'] === false, 'esta sesión no continúa');
    limpiarLimites();
    afirmar(login(new Nav(), 'jefa', $ctxNuevo['temp'])['status'] === 401, 'la clave vieja sigue sirviendo');
    navLogueado('jefa', $nueva);
});

caso('Cambio de rol por admin => sube token_version, cierra sesiones, se audita', function () use ($PASS) {
    $a = navLogueado('admin', $PASS['admin']);
    $e2 = navLogueado('empleado2', $PASS['empleado']);
    $id = idUsuario('empleado2');
    $r = $a->post("/admin/usuarios/$id/rol", ['rol' => 'contador']);
    afirmar($r['status'] === 200, 'status ' . $r['status'] . ' ' . $r['body']);
    afirmar($e2->pedir('GET', '/me')['status'] === 401, 'el JWT vivo sigue sirviendo');
    afirmar($e2->post('/auth/refresh')['status'] === 401, 'el refresh sigue sirviendo');
    $me = navLogueado('empleado2', $PASS['empleado'])->pedir('GET', '/me')['json'];
    afirmar($me['usuario']['rol'] === 'contador' && in_array('inventario.ver_costos', $me['permisos'], true), 'rol no aplicado');
    $st = db()->prepare("SELECT antes, despues FROM auditoria WHERE accion = 'usuario.cambiar_rol' AND entidad_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $au = $st->fetch();
    afirmar($au && str_contains($au['antes'], 'empleado') && str_contains($au['despues'], 'contador'), 'auditoría antes/después');
    afirmar($a->post("/admin/usuarios/$id/rol", ['rol' => 'superusuario'])['status'] === 422, 'rol inexistente aceptado');
});

caso('Desactivar usuario => sesión cerrada en la siguiente petición; login con respuesta genérica', function () use ($PASS) {
    limpiarLimites();
    $a = navLogueado('admin', $PASS['admin']);
    $c = navLogueado('contador', $PASS['contador']);
    $id = idUsuario('contador');
    afirmar($a->post("/admin/usuarios/$id/activo", ['activo' => '0'])['status'] === 200, 'desactivar');
    afirmar($c->pedir('GET', '/me')['status'] === 401, 'sigue dentro');
    $r = login(new Nav(), 'contador', $PASS['contador']);
    afirmar($r['status'] === 401 && ($r['json']['error'] ?? '') === 'credenciales_invalidas', 'login de inactivo: ' . $r['status']);
    afirmar($a->post("/admin/usuarios/$id/activo", ['activo' => '1'])['status'] === 200, 'reactivar');
    navLogueado('contador', $PASS['contador']);
    limpiarLimites();
});

caso('Protección del último admin y de auto-cambios', function () use ($PASS) {
    $a = navLogueado('admin', $PASS['admin']);
    $yo = idUsuario('admin');
    afirmar($a->post("/admin/usuarios/$yo/activo", ['activo' => '0'])['status'] === 422, 'se desactivó a sí mismo');
    afirmar($a->post("/admin/usuarios/$yo/rol", ['rol' => 'empleado'])['status'] === 422, 'se quitó el rol');
    // Con "jefa" como otro admin, degradarla está permitido; luego "admin" queda como único.
    $j = idUsuario('jefa');
    afirmar($a->post("/admin/usuarios/$j/rol", ['rol' => 'empleado'])['status'] === 200, 'degradar a jefa');
    $n = (int) db()->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE r.codigo = 'admin' AND u.activo = 1")->fetchColumn();
    afirmar($n === 1, "admins activos: $n");
    afirmar($a->pedir('GET', '/me')['status'] === 200, 'la sesión del admin cayó');
});

echo "== Bloqueos y rate limit ==\n";

caso('Bloqueo por usuario tras 5 fallos (existente e inexistente igual); desbloqueo por admin y por tiempo', function () use ($PASS) {
    limpiarLimites();
    $n = new Nav();
    $n->pedir('GET', '/login', null, [], false);
    for ($i = 1; $i <= 5; $i++) {
        $r = $n->post('/login', ['usuario' => 'empleado', 'password' => "mala-$i"]);
        afirmar($r['status'] === 401, "fallo $i => {$r['status']}");
    }
    $r = $n->post('/login', ['usuario' => 'empleado', 'password' => $PASS['empleado']]);
    afirmar($r['status'] === 429 && isset($r['headers']['retry-after']), 'clave correcta tras 5 fallos: ' . $r['status']);
    $cuerpoExistente = $r['body'];
    afirmar((string) db()->query("SELECT bloqueado_hasta > NOW() FROM usuarios WHERE usuario = 'empleado'")->fetchColumn() === '1', 'usuarios.bloqueado_hasta no fijado');
    afirmar((int) db()->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'usuario.bloqueo'")->fetchColumn() >= 1, 'bloqueo sin auditar');
    afirmar((int) db()->query("SELECT COUNT(*) FROM login_intentos WHERE usuario_texto = 'empleado' AND motivo = 'bloqueado'")->fetchColumn() >= 1, 'sin login_intentos bloqueado');
    // Inexistente: mismo comportamiento (no revela si existe).
    for ($i = 1; $i <= 5; $i++) {
        $n->post('/login', ['usuario' => 'fantasma', 'password' => "mala-$i"]);
    }
    $r = $n->post('/login', ['usuario' => 'fantasma', 'password' => 'otra']);
    afirmar($r['status'] === 429 && $r['body'] === $cuerpoExistente, 'el inexistente se comporta distinto');
    // Desbloqueo por admin.
    $a = navLogueado('admin', $PASS['admin']);
    afirmar($a->post('/admin/usuarios/' . idUsuario('empleado') . '/desbloquear')['status'] === 200, 'desbloquear');
    afirmar(login(new Nav(), 'empleado', $PASS['empleado'])['status'] === 200, 'no entra tras desbloqueo');
    // Desbloqueo por tiempo: se vuelve a bloquear y se "adelanta el reloj" en BD.
    for ($i = 1; $i <= 5; $i++) {
        $n->post('/login', ['usuario' => 'empleado', 'password' => "mala-$i"]);
    }
    afirmar(login(new Nav(), 'empleado', $PASS['empleado'])['status'] === 429, 'no se rebloqueó');
    db()->exec('UPDATE limites_tasa SET ventana_inicio = NOW() - INTERVAL 20 MINUTE, bloqueado_hasta = NOW() - INTERVAL 1 MINUTE');
    afirmar(login(new Nav(), 'empleado', $PASS['empleado'])['status'] === 200, 'no se desbloquea con el tiempo');
    limpiarLimites();
});

caso('Bloqueo por IP tras 20 fallos con usuarios distintos; desbloqueo de IP por admin', function () use ($PASS) {
    limpiarLimites();
    $a = navLogueado('admin', $PASS['admin']);   // sesión previa del admin para desbloquear
    $n = new Nav();
    $n->pedir('GET', '/login', null, [], false);
    for ($i = 1; $i <= 20; $i++) {
        $r = $n->post('/login', ['usuario' => "rociador$i", 'password' => 'x']);
        afirmar($r['status'] === 401, "fallo $i => {$r['status']}");
    }
    $r = login(new Nav(), 'contador', $PASS['contador']);
    afirmar($r['status'] === 429, 'IP no bloqueada: ' . $r['status']);
    afirmar((int) db()->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'seguridad.bloqueo_ip'")->fetchColumn() >= 1, 'sin auditoría de bloqueo de IP');
    $d = $a->post('/admin/bloqueos/ip', ['ip' => '127.0.0.1']);
    afirmar($d['status'] === 200, 'desbloquear IP: ' . $d['status']);
    afirmar(login(new Nav(), 'contador', $PASS['contador'])['status'] === 200, 'sigue bloqueada');
    limpiarLimites();
});

caso('Ráfaga concurrente al login no evalúa más de 5 contraseñas por usuario', function () {
    limpiarLimites();
    $n = new Nav();
    $n->pedir('GET', '/login', null, [], false);
    $desde = (int) db()->query('SELECT IFNULL(MAX(id), 0) FROM login_intentos')->fetchColumn();
    $mh = curl_multi_init();
    $hs = [];
    for ($i = 0; $i < 10; $i++) {
        $ch = curl_init(BASE . '/login');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['usuario' => 'contador', 'password' => "rafaga-$i"]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Origin: ' . BASE,
                'X-CSRF-Token: ' . $n->csrf(), 'Cookie: csrf=' . rawurlencode($n->csrf())]]);
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do {
        curl_multi_exec($mh, $activos);
        curl_multi_select($mh, 0.1);
    } while ($activos > 0);
    $cods = array_map(fn ($ch) => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs);
    $evaluadas = (int) db()->query("SELECT COUNT(*) FROM login_intentos WHERE usuario_texto = 'contador' AND motivo = 'password' AND id > $desde")->fetchColumn();
    afirmar($evaluadas <= 5, "se evaluaron $evaluadas contraseñas");
    afirmar(count(array_filter($cods, fn ($c) => $c === 429)) >= 5, 'códigos: ' . implode(',', $cods));
    limpiarLimites();
    return 'códigos ' . implode(',', $cods);
});

caso('Rate limit en /auth/refresh por IP (60/min)', function () {
    limpiarLimites();
    $n = new Nav();
    $ultimo = 0;
    for ($i = 1; $i <= 61; $i++) {
        $ultimo = $n->pedir('POST', '/auth/refresh', [])['status'];
        if ($i <= 60) {
            afirmar($ultimo === 401, "petición $i => $ultimo");
        }
    }
    afirmar($ultimo === 429, 'la 61 no se limitó: ' . $ultimo);
    limpiarLimites();
});

echo "== Auditoría, BD y configuración ==\n";

caso('Auditoría completa y sin secretos en claro', function () use ($PASS, &$secretosVistos) {
    $acciones = db()->query('SELECT DISTINCT accion FROM auditoria')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['usuario.crear_admin', 'usuario.login', 'usuario.login_fallido', 'sesion.logout', 'usuario.cambiar_rol',
                 'usuario.cambiar_password', 'usuario.bloqueo', 'seguridad.bloqueo_ip', 'sesion.refresh_reutilizado',
                 'usuario.crear', 'usuario.desactivar', 'usuario.desbloquear', 'seguridad.desbloquear_ip', 'acceso.denegado'] as $a) {
        afirmar(in_array($a, $acciones, true), "falta la acción $a");
    }
    $todo = implode("\n", array_map(fn ($f) => implode('|', $f),
        db()->query('SELECT IFNULL(antes,\'\'), IFNULL(despues,\'\'), IFNULL(user_agent,\'\') FROM auditoria')->fetchAll(PDO::FETCH_NUM)));
    foreach (array_merge(array_values($PASS), array_filter($secretosVistos)) as $s) {
        afirmar(!str_contains($todo, $s), 'secreto en auditoría');
    }
    afirmar(!preg_match('/\$argon2|\$2y\$|password_hash/i', $todo), 'hash en auditoría');
    $log = implode('', array_map('file_get_contents', glob($GLOBALS['RAIZ'] . '/logs/app-*.log') ?: []));
    foreach (array_merge(array_values($PASS), array_filter($secretosVistos)) as $s) {
        afirmar(!str_contains($log, $s), 'secreto en logs/');
    }
    return count($acciones) . ' tipos de acción';
});

caso('Conexión de la app: sql_mode estricto, time_zone -05:00 y zona PHP America/Bogota', function () {
    App::arrancarEntorno();
    restore_error_handler();
    $m = (string) Db::pdo()->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    afirmar(str_contains($m, 'STRICT_ALL_TABLES') && str_contains($m, 'NO_ZERO_DATE'), $m);
    afirmar(Db::pdo()->query('SELECT @@SESSION.time_zone')->fetchColumn() === '-05:00', 'time_zone');
    afirmar(date_default_timezone_get() === 'America/Bogota', date_default_timezone_get());
});

caso('Cookies en modo seguro: __Host-at / __Secure-rt con Secure, Path y sin Domain', function () {
    $prev = ['APP_ENV' => getenv('APP_ENV'), 'APP_URL' => getenv('APP_URL')];
    putenv('APP_ENV=production');
    putenv('APP_URL=https://tienda.prueba.test/sub');
    try {
        $c = Config::desdeEntorno();
        $req = new Request('GET', '/', [], [], [], [], '1.2.3.4', true, 'tienda.prueba.test');
        $ck = Cookies::paraPeticion($c, $req);
        afirmar($ck->seguro, 'no es modo seguro');
        $r = new Response();
        $ck->ponerAcceso($r, 'jwt', 600);
        $ck->ponerRefresh($r, 'rt', 600);
        $ck->ponerCsrf($r, 'c', 600);
        afirmar(str_starts_with($r->cookies[0], '__Host-at=') && str_contains($r->cookies[0], '; Path=/;')
            && str_contains($r->cookies[0], '; Secure; HttpOnly; SameSite=Strict') && stripos($r->cookies[0], 'domain') === false, $r->cookies[0]);
        afirmar(str_starts_with($r->cookies[1], '__Secure-rt=') && str_contains($r->cookies[1], 'Path=/sub/auth/refresh') && str_contains($r->cookies[1], 'Secure'), $r->cookies[1]);
        afirmar(str_starts_with($r->cookies[2], '__Host-csrf=') && str_contains($r->cookies[2], 'Secure'), $r->cookies[2]);
        // En modo seguro se ignoran las cookies sin prefijo.
        afirmar($ck->nombreAcceso() === '__Host-at', 'nombre');
        // Producción sin HTTPS: no se atiende.
        $http = new Request('POST', '/login', [], [], [], [], '1.2.3.4', false, 'tienda.prueba.test');
        afirmar(Transporte::verificar($c, $http)?->status === 400, 'POST http en producción');
        // Local por IP de LAN sin HTTPS: rechazado (no se relajan cookies fuera de loopback).
        putenv('APP_ENV=local');
        putenv('APP_URL=http://192.168.1.50');
        $lan = Config::desdeEntorno();
        $q = new Request('GET', '/', [], [], [], [], '192.168.1.60', false, '192.168.1.50');
        afirmar(Transporte::verificar($lan, $q)?->status === 403, 'LAN http aceptada');
        afirmar(Cookies::paraPeticion($lan, $q)->seguro, 'LAN sin prefijo');
    } finally {
        foreach ($prev as $k => $v) {
            putenv("$k=$v");
        }
        Config::reiniciar();
    }
});

caso('La app no arranca con configuración insegura (prod sin HTTPS, secretos ausentes/cortos/iguales)', function () use ($CSRF_HEX) {
    $p = 8097;
    $variantes = [
        'producción con APP_URL http' => ['APP_ENV' => 'production', 'APP_URL' => 'http://127.0.0.1:' . $p],
        'sin CSRF_SECRET' => ['CSRF_SECRET' => null, 'APP_URL' => 'http://127.0.0.1:' . $p],
        'JWT_SECRET corto' => ['JWT_SECRET' => bin2hex(random_bytes(16)), 'APP_URL' => 'http://127.0.0.1:' . $p],
        'JWT_SECRET no hex' => ['JWT_SECRET' => str_repeat('frase-larga-', 8), 'APP_URL' => 'http://127.0.0.1:' . $p],
        'secretos iguales' => ['JWT_SECRET' => $CSRF_HEX, 'APP_URL' => 'http://127.0.0.1:' . $p],
        'APP_ENV desconocido' => ['APP_ENV' => 'dev', 'APP_URL' => 'http://127.0.0.1:' . $p],
    ];
    foreach ($variantes as $que => $env) {
        iniciarServidor($p, $env);
        try {
            $r = (new Nav('http://127.0.0.1:' . $p))->pedir('GET', '/login');
            afirmar($r['status'] === 500 && ($r['json']['error'] ?? '') === 'error_interno', "$que: {$r['status']}");
            afirmar(!str_contains($r['body'], 'SECRET') && !str_contains($r['body'], 'APP_'), "$que: filtra detalle");
            afirmar(isset($r['headers']['content-security-policy']), "$que: sin cabeceras");
        } finally {
            detenerServidor($p);
        }
    }
    // Producción bien configurada servida por http: GET -> 301 a https, POST -> 400.
    iniciarServidor($p, ['APP_ENV' => 'production', 'APP_URL' => 'https://127.0.0.1:' . $p]);
    try {
        $n = new Nav('http://127.0.0.1:' . $p);
        $r = $n->pedir('GET', '/login', null, [], false);
        afirmar($r['status'] === 301 && ($r['headers']['location'][0] ?? '') === 'https://127.0.0.1:' . $p . '/login', 'GET http: ' . $r['status']);
        afirmar($r['cookies'] === [], 'puso cookies sobre http');
        $r = $n->pedir('POST', '/login', ['usuario' => 'a', 'password' => 'b'], ['Origin' => 'https://127.0.0.1:' . $p]);
        afirmar($r['status'] === 400, 'POST http: ' . $r['status']);
    } finally {
        detenerServidor($p);
    }
    return count($variantes) . ' configuraciones inválidas + prod por http';
});
