<?php

declare(strict_types=1);

/**
 * Crea el primer usuario administrador (rol `admin`). Solo CLI.
 *
 * Uso:
 *   php scripts/crear_admin.php [--usuario=U] [--email=E] [--nombre="N"] [--sucursal=ID] [--forzar]
 *
 * Los datos que falten se piden por consola. La contraseña NUNCA se acepta como
 * argumento (quedaría en el historial y en la lista de procesos):
 *  - Consola interactiva en Windows: se lee con PowerShell `Read-Host -AsSecureString`
 *    (sin eco); el texto viaja a PHP por una tubería anónima, no por argumentos
 *    ni archivos. Se pide dos veces para confirmar.
 *  - Consola interactiva en Linux/macOS: `stty -echo`.
 *  - Entrada redirigida (no es una terminal): se lee la PRIMERA línea de STDIN.
 *    Pensado para automatización/pruebas: `... < archivo_protegido` o una tubería.
 *  Si no se puede ocultar el eco en una terminal, el script se niega (no lee la
 *  contraseña a la vista).
 *
 * Reglas:
 *  - Si ya existe un admin no eliminado, se niega salvo `--forzar`.
 *  - Política: mínimo 12 caracteres, distinta del usuario/email/nombre, no en la
 *    lista de contraseñas comunes, no un solo carácter repetido.
 *  - Hash: Argon2id si PHP lo soporta; si no, bcrypt cost 12 (límite 72 bytes).
 *  - Inserción con sentencias preparadas en una transacción que bloquea la fila
 *    del rol admin (FOR UPDATE) para serializar ejecuciones simultáneas, y deja
 *    constancia en `auditoria` (sin la contraseña ni el hash).
 *
 * Códigos de salida: 0 ok, 1 datos inválidos, 2 ya existe admin / duplicado,
 *                    3 error de base de datos o de entorno.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/autoload.php';

use App\Db;
use App\Security\PoliticaPassword;

const SALIDA_OK = 0;
const SALIDA_INVALIDO = 1;
const SALIDA_EXISTE = 2;
const SALIDA_ERROR = 3;

function salir(string $mensaje, int $codigo): never
{
    fwrite($codigo === SALIDA_OK ? STDOUT : STDERR, $mensaje . PHP_EOL);
    exit($codigo);
}

/** @return array{opts: array<string,string>, flags: array<string,bool>} */
function leerArgumentos(array $argv): array
{
    $permitidas = ['usuario', 'email', 'nombre', 'sucursal'];
    $opts = [];
    $flags = ['forzar' => false, 'ayuda' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--forzar') {
            $flags['forzar'] = true;
        } elseif ($arg === '--ayuda' || $arg === '-h' || $arg === '--help') {
            $flags['ayuda'] = true;
        } elseif (preg_match('/^--(password|contrasena|clave)(=|$)/i', $arg)) {
            salir('La contraseña no se acepta como argumento. Se pedirá sin eco (o por STDIN redirigido).', SALIDA_INVALIDO);
        } elseif (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) && in_array($m[1], $permitidas, true)) {
            $opts[$m[1]] = $m[2];
        } else {
            salir("Argumento no reconocido: {$arg}. Use --ayuda.", SALIDA_INVALIDO);
        }
    }
    return ['opts' => $opts, 'flags' => $flags];
}

function esTerminal(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDIN);
}

function preguntar(string $etiqueta): string
{
    if (!esTerminal()) {
        salir("Falta el dato '{$etiqueta}'. Sin terminal interactiva, páselo como argumento.", SALIDA_INVALIDO);
    }
    fwrite(STDERR, $etiqueta . ': ');
    $linea = fgets(STDIN);
    if ($linea === false) {
        salir('Entrada cancelada.', SALIDA_INVALIDO);
    }
    return trim($linea);
}

/** Lee una contraseña sin eco. Devuelve null si no es posible ocultarla. */
function leerSecretoSinEco(string $etiqueta): ?string
{
    fwrite(STDERR, $etiqueta . ': ');

    if (PHP_OS_FAMILY === 'Windows') {
        // Sin argumentos con datos: el script de PowerShell es fijo. stdin/stderr se
        // heredan de la consola (Read-Host lee del teclado sin eco); stdout es una
        // tubería anónima hacia PHP. Se libera el BSTR con ZeroFreeBSTR.
        $ps = '[Console]::OutputEncoding=[Text.UTF8Encoding]::new($false);'
            . '$s=Read-Host -AsSecureString;'
            . '$b=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);'
            . 'try{[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($b))}'
            . 'finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b)}';
        $proc = @proc_open(
            ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $ps],
            [0 => STDIN, 1 => ['pipe', 'w'], 2 => STDERR],
            $tuberias
        );
        if (!is_resource($proc)) {
            return null;
        }
        $valor = stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        $codigo = proc_close($proc);
        fwrite(STDERR, PHP_EOL);
        if ($codigo !== 0 || $valor === false) {
            return null;
        }
        return $valor;
    }

    // Unix: desactivar eco con stty y restaurarlo siempre.
    $estado = @shell_exec('stty -g 2>/dev/null');
    if (!is_string($estado) || trim($estado) === '') {
        return null;
    }
    @shell_exec('stty -echo 2>/dev/null');
    try {
        $linea = fgets(STDIN);
    } finally {
        @shell_exec('stty ' . escapeshellarg(trim($estado)) . ' 2>/dev/null');
        fwrite(STDERR, PHP_EOL);
    }
    return $linea === false ? null : rtrim($linea, "\r\n");
}

function leerPassword(): string
{
    if (!esTerminal()) {
        // Entrada redirigida: primera línea de STDIN (solo se quita el salto de línea).
        $linea = fgets(STDIN);
        if ($linea === false) {
            salir('No se recibió la contraseña por STDIN.', SALIDA_INVALIDO);
        }
        return rtrim($linea, "\r\n");
    }

    $p1 = leerSecretoSinEco('Contraseña (mín. 12 caracteres, no se mostrará)');
    if ($p1 === null) {
        salir('No se pudo leer la contraseña sin eco en esta terminal. Redirija la contraseña por STDIN '
            . '(p. ej. desde un archivo con permisos restringidos) en lugar de escribirla a la vista.', SALIDA_ERROR);
    }
    $p2 = leerSecretoSinEco('Repita la contraseña');
    if ($p2 === null || !hash_equals($p1, $p2)) {
        salir('Las contraseñas no coinciden.', SALIDA_INVALIDO);
    }
    return $p1;
}

function minusculas(string $s): string
{
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Política compartida con la app web (src/Security/PoliticaPassword.php).
 * @return list<string> errores de la política (vacío = válida)
 */
function validarPassword(#[\SensitiveParameter] string $pass, string $usuario, string $email, string $nombre, bool $esBcrypt): array
{
    return PoliticaPassword::validar($pass, $usuario, $email, $nombre, $esBcrypt);
}

// -----------------------------------------------------------------------------
// Programa principal
// -----------------------------------------------------------------------------
if (!extension_loaded('mbstring') || !extension_loaded('pdo_mysql')) {
    salir('Se requieren las extensiones mbstring y pdo_mysql.', SALIDA_ERROR);
}

['opts' => $opts, 'flags' => $flags] = leerArgumentos($argv);

if ($flags['ayuda']) {
    salir("Uso: php scripts/crear_admin.php [--usuario=U] [--email=E] [--nombre=\"N\"] [--sucursal=ID] [--forzar]\n"
        . "La contraseña se pide sin eco o se lee de la primera línea de STDIN redirigido.", SALIDA_OK);
}

$usuario = trim($opts['usuario'] ?? preguntar('Usuario (3-50: letras, números, . _ -)'));
$email   = minusculas(trim($opts['email'] ?? preguntar('Email')));
$nombre  = trim($opts['nombre'] ?? preguntar('Nombre completo'));
$sucursalArg = $opts['sucursal'] ?? null;

$errores = [];
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $usuario)) {
    $errores[] = 'Usuario inválido: 3 a 50 caracteres, solo letras sin tilde, números, punto, guion y guion bajo.';
}
if (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errores[] = 'Email inválido (máx. 150 caracteres).';
}
if (!mb_check_encoding($nombre, 'UTF-8') || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 100
    || preg_match('/[\x00-\x1F\x7F]/', $nombre)) {
    $errores[] = 'Nombre inválido: 2 a 100 caracteres, sin caracteres de control.';
}
if ($sucursalArg !== null && (!ctype_digit($sucursalArg) || (int) $sucursalArg < 1 || (int) $sucursalArg > 65535)) {
    $errores[] = 'Sucursal inválida: debe ser un id numérico.';
}
if ($errores) {
    salir(implode(PHP_EOL, $errores), SALIDA_INVALIDO);
}

// Algoritmo de hash: Argon2id preferido (docs/SEGURIDAD_AUTH.md), bcrypt cost 12 como respaldo.
if (defined('PASSWORD_ARGON2ID')) {
    $algoritmo = PASSWORD_ARGON2ID;
    $algoritmoNombre = 'Argon2id';
    $opcionesHash = []; // valores por defecto de PHP (64 MiB, 4 iteraciones, 1 hilo)
} else {
    $algoritmo = PASSWORD_BCRYPT;
    $algoritmoNombre = 'bcrypt (cost 12)';
    $opcionesHash = ['cost' => 12];
}

$password = leerPassword();
$errores = validarPassword($password, $usuario, $email, $nombre, $algoritmo === PASSWORD_BCRYPT);
if ($errores) {
    unset($password);
    salir(implode(PHP_EOL, $errores), SALIDA_INVALIDO);
}

$hash = password_hash($password, $algoritmo, $opcionesHash);
$verificado = password_verify($password, $hash);
// Sobrescribir y liberar la contraseña en claro lo antes posible.
$password = str_repeat("\0", strlen($password));
unset($password);
if (!$verificado) {
    salir('Error interno al generar el hash de la contraseña.', SALIDA_ERROR);
}

try {
    $resultado = Db::transaction(function (\PDO $pdo) use ($usuario, $email, $nombre, $sucursalArg, $hash, $flags): array {
        // 1) Bloquear la fila del rol admin: serializa ejecuciones simultáneas del
        //    script (dos procesos no pueden ver "no hay admin" a la vez).
        $st = $pdo->prepare('SELECT id FROM roles WHERE codigo = ? FOR UPDATE');
        $st->execute(['admin']);
        $rolId = $st->fetchColumn();
        if ($rolId === false) {
            throw new \DomainException('No existe el rol "admin". Cargue primero database/seeds/0001_seed_base.sql.', SALIDA_ERROR);
        }
        $rolId = (int) $rolId;

        // 2) ¿Ya hay un admin (no eliminado)?
        $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id = ? AND eliminado_en IS NULL');
        $st->execute([$rolId]);
        $admins = (int) $st->fetchColumn();
        if ($admins > 0 && !$flags['forzar']) {
            throw new \DomainException("Ya existe {$admins} administrador(es). Use --forzar para crear otro.", SALIDA_EXISTE);
        }

        // 3) Sucursal: la indicada o la primera activa.
        if ($sucursalArg !== null) {
            $st = $pdo->prepare('SELECT id FROM sucursales WHERE id = ? AND activa = 1');
            $st->execute([(int) $sucursalArg]);
        } else {
            $st = $pdo->query('SELECT id FROM sucursales WHERE activa = 1 ORDER BY id LIMIT 1');
        }
        $sucursalId = $st->fetchColumn();
        if ($sucursalId === false) {
            throw new \DomainException('No existe la sucursal indicada o no hay sucursales activas.', SALIDA_INVALIDO);
        }
        $sucursalId = (int) $sucursalId;

        // 4) Duplicados (mensaje claro; el UNIQUE de la tabla es la garantía final).
        $st = $pdo->prepare('SELECT usuario = ? AS mismo_usuario, email = ? AS mismo_email FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1');
        $st->execute([$usuario, $email, $usuario, $email]);
        if ($fila = $st->fetch()) {
            throw new \DomainException((int) $fila['mismo_usuario'] === 1
                ? 'Ya existe un usuario con ese nombre de usuario.'
                : 'Ya existe un usuario con ese email.', SALIDA_EXISTE);
        }

        // 5) Insertar. debe_cambiar_password = 0: la eligió el propio administrador.
        $st = $pdo->prepare(
            'INSERT INTO usuarios (sucursal_id, rol_id, usuario, email, nombre, password_hash,
                                   activo, debe_cambiar_password, password_cambiado_en)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW())'
        );
        $st->execute([$sucursalId, $rolId, $usuario, $email, $nombre, $hash]);
        $nuevoId = (int) $pdo->lastInsertId();

        // 6) Auditoría (solo inserción). Sin contraseña ni hash.
        $st = $pdo->prepare(
            'INSERT INTO auditoria (sucursal_id, usuario_id, accion, entidad, entidad_id, despues, user_agent)
             VALUES (?, NULL, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $sucursalId,
            'usuario.crear_admin',
            'usuarios',
            $nuevoId,
            json_encode([
                'usuario' => $usuario, 'email' => $email, 'nombre' => $nombre,
                'rol' => 'admin', 'sucursal_id' => $sucursalId, 'forzado' => $admins > 0,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'cli:scripts/crear_admin.php',
        ]);

        return ['id' => $nuevoId, 'sucursal_id' => $sucursalId, 'forzado' => $admins > 0];
    });
} catch (\DomainException $e) {
    salir($e->getMessage(), $e->getCode() ?: SALIDA_INVALIDO);
} catch (\PDOException $e) {
    // Carrera con otra inserción: el UNIQUE detecta el duplicado.
    if (($e->errorInfo[1] ?? 0) === 1062) {
        salir('Ya existe un usuario con ese nombre de usuario o email.', SALIDA_EXISTE);
    }
    error_log('[crear_admin] Error de BD: SQLSTATE ' . $e->getCode() . ' ' . ($e->errorInfo[2] ?? ''));
    salir('Error de base de datos al crear el administrador (detalle en el registro de errores).', SALIDA_ERROR);
} catch (\Throwable $e) {
    salir('Error: ' . $e->getMessage(), SALIDA_ERROR);
}

salir(sprintf(
    "Administrador creado: id=%d, usuario=%s, sucursal_id=%d, hash=%s%s.",
    $resultado['id'],
    $usuario,
    $resultado['sucursal_id'],
    $algoritmoNombre,
    $resultado['forzado'] ? ' (adicional, --forzar)' : ''
), SALIDA_OK);
