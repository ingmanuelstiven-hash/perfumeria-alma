<?php

declare(strict_types=1);

/**
 * Runner mínimo de migraciones SQL. Solo CLI.
 *
 * Uso:
 *   php scripts/migrate.php [estado|aplicar|adoptar] [--seeds] [--dry-run] [--dir=RUTA] [--confirmo]
 *
 *   estado   Lista migraciones aplicadas/pendientes y detecta cambios en archivos ya aplicados.
 *   aplicar  (por defecto) Aplica en orden las pendientes de DIR/migrations/NNNN_*.sql.
 *            --seeds   además ejecuta DIR/seeds/NNNN_*.sql (después de las migraciones).
 *            --dry-run no ejecuta nada: muestra qué haría (y valida que cada archivo se divide bien).
 *   adoptar  Para una BD cuyo esquema se aplicó A MANO (con el cliente mysql) y cuya tabla
 *            `migraciones` está vacía: registra los archivos como aplicados SIN ejecutarlos.
 *            Exige --confirmo. Úselo una sola vez, tras comprobar que el esquema es el esperado.
 *   --dir    Carpeta que contiene migrations/ y seeds/ (por defecto database/ del proyecto).
 *
 * Conexión (variables de entorno o .env): DB_HOST, DB_PORT, DB_NAME y el usuario
 * DB_ADMIN_USER/DB_ADMIN_PASS (si faltan, DB_USER/DB_PASS). El usuario necesita DDL:
 * el de la app (perf_app) NO debe tenerlo; use uno de mantenimiento. La BD debe existir
 * (el script no la crea).
 *
 * DECISIONES
 *  - Se ejecuta con PDO y un divisor de sentencias propio (src/SqlSplitter.php) en lugar de
 *    llamar a mysql.exe: (1) permite decir QUÉ sentencia y línea falló, (2) no depende de
 *    un binario ni de pasar credenciales a un proceso hijo, (3) usa la misma configuración
 *    de sesión que la app. El divisor entiende cadenas, identificadores, comentarios,
 *    comentarios condicionales /*! y bloques DELIMITER.
 *  - SHA-256 del contenido EXACTO del archivo (hash_file) en `migraciones.checksum_sha256`.
 *    Si un archivo ya aplicado cambia, el script se niega a continuar: las migraciones
 *    aplicadas son inmutables (los cambios van en un archivo nuevo).
 *  - Sin atomicidad: DDL hace commit implícito en MariaDB. Si una sentencia falla, se
 *    detiene, NO se registra el archivo y se informa cuál falló (número, línea y texto).
 *    Las sentencias anteriores del mismo archivo ya quedaron aplicadas: hay que revisar a
 *    mano antes de reintentar.
 *  - Los seeds NO se registran en `migraciones`: están escritos para repetirse
 *    (ON DUPLICATE KEY UPDATE / INSERT IGNORE) y la tabla está pensada para el esquema.
 *    Se ejecutan completos cada vez que se pasa --seeds. OJO: algunos seeds restablecen
 *    columnas (p. ej. nombre de la sucursal 1, descripciones) a su valor de archivo; úselos
 *    al instalar/actualizar, no como rutina diaria.
 *  - Un candado (GET_LOCK) impide dos ejecuciones simultáneas.
 *
 * Códigos de salida: 0 ok, 1 argumentos inválidos, 3 error de BD/entorno (incluye BD
 * inexistente), 4 integridad (SHA cambiado, archivo faltante, orden o historial
 * inconsistente), 5 una migración o seed falló.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/autoload.php';

use App\CliDb;
use App\SqlSplitter;

const SALIDA_OK = 0;
const SALIDA_ARGS = 1;
const SALIDA_ERROR = 3;
const SALIDA_INTEGRIDAD = 4;
const SALIDA_FALLO_SQL = 5;

function salir(string $mensaje, int $codigo): never
{
    fwrite($codigo === SALIDA_OK ? STDOUT : STDERR, $mensaje . PHP_EOL);
    exit($codigo);
}

function info(string $mensaje): void
{
    fwrite(STDOUT, $mensaje . PHP_EOL);
}

const AYUDA = <<<'TXT'
Uso: php scripts/migrate.php [estado|aplicar|adoptar] [--seeds] [--dry-run] [--dir=RUTA] [--confirmo]

  estado    Aplicadas/pendientes; error si cambió el SHA-256 de una ya aplicada.
  aplicar   (defecto) Aplica las pendientes en orden. --seeds ejecuta también los seeds
            (repetibles, no se registran). --dry-run solo muestra qué haría.
  adoptar   Registra como aplicadas, sin ejecutarlas, las migraciones de una BD cuyo
            esquema se cargó a mano. Requiere --confirmo.
  --dir     Carpeta con migrations/ y seeds/ (por defecto database/).

Variables: DB_HOST, DB_PORT, DB_NAME, DB_ADMIN_USER/DB_ADMIN_PASS (o DB_USER/DB_PASS).
La base debe existir. Sin atomicidad: DDL hace commit implícito en MariaDB.
Códigos de salida: 0 ok, 1 argumentos, 3 BD/entorno, 4 integridad, 5 fallo SQL.
TXT;

/** @return array{cmd: string, seeds: bool, dry: bool, dir: string, confirmo: bool} */
function leerArgumentos(array $argv): array
{
    $r = ['cmd' => 'aplicar', 'seeds' => false, 'dry' => false, 'dir' => dirname(__DIR__) . '/database', 'confirmo' => false];
    $cmdVisto = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--ayuda' || $arg === '-h' || $arg === '--help') {
            salir(AYUDA, SALIDA_OK);
        } elseif ($arg === '--seeds') {
            $r['seeds'] = true;
        } elseif ($arg === '--dry-run') {
            $r['dry'] = true;
        } elseif ($arg === '--confirmo') {
            $r['confirmo'] = true;
        } elseif (str_starts_with($arg, '--dir=')) {
            $r['dir'] = rtrim(substr($arg, 6), '\\/');
        } elseif (preg_match('/^--(pass|password|contrasena|clave)(=|$)/i', $arg)) {
            salir('Las credenciales no se aceptan como argumento: use variables de entorno o .env.', SALIDA_ARGS);
        } elseif (in_array($arg, ['estado', 'aplicar', 'adoptar'], true) && !$cmdVisto) {
            $r['cmd'] = $arg;
            $cmdVisto = true;
        } else {
            salir("Argumento no reconocido: {$arg}. Use --ayuda.", SALIDA_ARGS);
        }
    }
    if ($r['cmd'] === 'adoptar' && ($r['seeds'] || $r['dry'])) {
        salir('adoptar no admite --seeds ni --dry-run.', SALIDA_ARGS);
    }
    if ($r['cmd'] === 'estado' && ($r['seeds'] || $r['dry'])) {
        salir('estado no admite --seeds ni --dry-run.', SALIDA_ARGS);
    }
    return $r;
}

/**
 * @return list<array{nombre: string, numero: int, ruta: string}> ordenados por número
 */
function listarArchivos(string $carpeta, bool $obligatoria): array
{
    if (!is_dir($carpeta)) {
        if ($obligatoria) {
            salir("No existe la carpeta {$carpeta}.", SALIDA_ARGS);
        }
        return [];
    }
    $lista = [];
    foreach (scandir($carpeta) ?: [] as $f) {
        if (preg_match('/^(\d{4})_[A-Za-z0-9_.\-]+\.sql$/', $f, $m) && is_file($carpeta . '/' . $f)) {
            $lista[] = ['nombre' => $f, 'numero' => (int) $m[1], 'ruta' => $carpeta . '/' . $f];
        }
    }
    usort($lista, fn(array $a, array $b): int => [$a['numero'], $a['nombre']] <=> [$b['numero'], $b['nombre']]);
    $vistos = [];
    foreach ($lista as $a) {
        if (isset($vistos[$a['numero']])) {
            salir("Número de migración repetido {$a['numero']}: {$vistos[$a['numero']]} y {$a['nombre']}.", SALIDA_INTEGRIDAD);
        }
        $vistos[$a['numero']] = $a['nombre'];
    }
    return $lista;
}

function sha256Archivo(string $ruta): string
{
    $h = hash_file('sha256', $ruta);
    if ($h === false) {
        salir("No se pudo leer {$ruta}.", SALIDA_ERROR);
    }
    return $h;
}

function resumen(string $sql): string
{
    $s = trim((string) preg_replace('/\s+/', ' ', $sql));
    return mb_strlen($s, 'UTF-8') > 160 ? mb_substr($s, 0, 160, 'UTF-8') . '...' : $s;
}

/**
 * Ejecuta un archivo sentencia a sentencia. Devuelve el número de sentencias ejecutadas;
 * ante un fallo lanza RuntimeException con el detalle (número, línea, texto, error).
 */
function ejecutarArchivo(PDO $pdo, array $archivo): int
{
    $contenido = file_get_contents($archivo['ruta']);
    if ($contenido === false) {
        throw new RuntimeException("No se pudo leer {$archivo['nombre']}.");
    }
    try {
        $sentencias = SqlSplitter::dividir($contenido);
    } catch (RuntimeException $e) {
        throw new RuntimeException("{$archivo['nombre']}: error al dividir el SQL: " . $e->getMessage());
    }
    $total = count($sentencias);
    foreach ($sentencias as $k => $s) {
        try {
            $pdo->exec($s['sql']);
        } catch (PDOException $e) {
            // Una sentencia fallida dentro de START TRANSACTION (seeds) no debe dejar la transacción abierta.
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $codigo = $e->errorInfo[1] ?? 0;
            $msg = $e->errorInfo[2] ?? $e->getMessage();
            throw new RuntimeException(sprintf(
                "%s: falló la sentencia %d de %d (línea %d del archivo).\n  SQL: %s\n  Error MariaDB %s: %s",
                $archivo['nombre'], $k + 1, $total, $s['linea'], resumen($s['sql']), $codigo, $msg
            ));
        }
    }
    // Un seed o migración que deje una transacción abierta es un error del archivo.
    if ($pdo->inTransaction()) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw new RuntimeException("{$archivo['nombre']}: terminó con una transacción abierta (falta COMMIT); se hizo ROLLBACK.");
    }
    return $total;
}

// -----------------------------------------------------------------------------
// Programa principal
// -----------------------------------------------------------------------------
if (!extension_loaded('pdo_mysql') || !extension_loaded('mbstring')) {
    salir('Se requieren las extensiones pdo_mysql y mbstring.', SALIDA_ERROR);
}

$a = leerArgumentos($argv);
$dirMig = $a['dir'] . '/migrations';
$dirSeed = $a['dir'] . '/seeds';
$archivos = listarArchivos($dirMig, true);
$seeds = $a['seeds'] ? listarArchivos($dirSeed, true) : [];

try {
    $cred = CliDb::credenciales('admin');
    $sinBd = CliDb::pdo($cred, null);
    if (!CliDb::existeBd($sinBd, $cred['name'])) {
        salir("La base de datos '{$cred['name']}' no existe. Créela vacía (utf8mb4 / utf8mb4_unicode_ci) y vuelva a ejecutar; este script no la crea.", SALIDA_ERROR);
    }
    unset($sinBd);
    $pdo = CliDb::pdo($cred, $cred['name']);
} catch (RuntimeException $e) {
    salir('Error: ' . $e->getMessage(), SALIDA_ERROR);
}
$bd = $cred['name'];
$secreto = $cred['pass'];
unset($cred);

try {
    // Candado: una sola ejecución a la vez sobre esta BD.
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute(['perfumeria_migrate:' . $bd]);
    if ((int) $st->fetchColumn() !== 1) {
        salir('Hay otra ejecución de migrate.php en curso sobre esta base.', SALIDA_ERROR);
    }

    // Estado de la BD.
    $st = $pdo->prepare("SELECT table_name FROM information_schema.TABLES WHERE table_schema = ? AND table_type = 'BASE TABLE'");
    $st->execute([$bd]);
    $tablas = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    $tieneMig = in_array('migraciones', $tablas, true);
    $otras = count($tablas) - ($tieneMig ? 1 : 0);

    /** @var array<string,array{checksum_sha256: string, aplicada_en: string}> $aplicadas */
    $aplicadas = [];
    if ($tieneMig) {
        foreach ($pdo->query('SELECT archivo, checksum_sha256, aplicada_en FROM migraciones ORDER BY id')->fetchAll() as $f) {
            $aplicadas[$f['archivo']] = $f;
        }
    }

    // Esquema sin historial: aplicado a mano. aplicar/estado lo avisan; adoptar lo resuelve.
    $sinHistorial = $otras > 0 && (!$tieneMig || $aplicadas === []);
    if ($sinHistorial && $a['cmd'] !== 'adoptar') {
        $detalle = $tieneMig
            ? "tiene {$otras} tablas (sin contar `migraciones`) y la tabla `migraciones` está vacía (esquema aplicado a mano)"
            : "tiene {$otras} tablas y no existe la tabla `migraciones`";
        salir("La base '{$bd}' {$detalle}. Aplicar de nuevo fallaría o duplicaría. "
            . "Si el esquema es el de las migraciones, ejecute: php scripts/migrate.php adoptar --confirmo", SALIDA_INTEGRIDAD);
    }

    // Integridad: SHA de las aplicadas, archivos que faltan, orden.
    $errores = [];
    $porNombre = [];
    foreach ($archivos as $f) {
        $porNombre[$f['nombre']] = $f;
    }
    $maxAplicado = 0;
    foreach ($aplicadas as $nombre => $fila) {
        if (!isset($porNombre[$nombre])) {
            $errores[] = "Aplicada pero SIN archivo en {$dirMig}: {$nombre}";
            continue;
        }
        $maxAplicado = max($maxAplicado, $porNombre[$nombre]['numero']);
        $actual = sha256Archivo($porNombre[$nombre]['ruta']);
        if (!hash_equals(strtolower($fila['checksum_sha256']), $actual)) {
            $errores[] = "SHA-256 CAMBIADO en {$nombre}: registrado " . substr($fila['checksum_sha256'], 0, 12)
                . '..., actual ' . substr($actual, 0, 12) . '... (una migración aplicada no se edita: cree una nueva).';
        }
    }
    $pendientes = [];
    foreach ($archivos as $f) {
        if (!isset($aplicadas[$f['nombre']])) {
            $pendientes[] = $f;
            if ($f['numero'] < $maxAplicado && !$sinHistorial) {
                $errores[] = "Pendiente fuera de orden: {$f['nombre']} es anterior a una migración ya aplicada.";
            }
        }
    }

    // ---- estado -------------------------------------------------------------
    if ($a['cmd'] === 'estado') {
        info("Base: {$bd}   Carpeta: {$a['dir']}");
        foreach ($archivos as $f) {
            if (isset($aplicadas[$f['nombre']])) {
                $ok = hash_equals(strtolower($aplicadas[$f['nombre']]['checksum_sha256']), sha256Archivo($f['ruta']));
                info(sprintf('  [%s] %s  (%s)', $ok ? 'aplicada' : 'CAMBIADA', $f['nombre'], $aplicadas[$f['nombre']]['aplicada_en']));
            } else {
                info("  [pendiente] {$f['nombre']}");
            }
        }
        if ($sinHistorial) {
            info("  (la base tiene {$otras} tablas pero ningún registro en migraciones: use 'adoptar --confirmo' si el esquema ya está aplicado)");
        }
        info(sprintf('Aplicadas: %d, pendientes: %d.', count($aplicadas), count($pendientes)));
        if ($errores) {
            salir('PROBLEMAS DE INTEGRIDAD:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $errores), SALIDA_INTEGRIDAD);
        }
        exit(SALIDA_OK);
    }

    if ($errores) {
        salir('No se aplica nada. PROBLEMAS DE INTEGRIDAD:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $errores), SALIDA_INTEGRIDAD);
    }

    // ---- adoptar ------------------------------------------------------------
    if ($a['cmd'] === 'adoptar') {
        if (!$tieneMig) {
            salir("La base '{$bd}' no tiene la tabla `migraciones`; no se puede adoptar (aplique 0001_schema.sql).", SALIDA_INTEGRIDAD);
        }
        if ($aplicadas !== []) {
            salir('La tabla `migraciones` ya tiene registros: nada que adoptar. Use "estado".', SALIDA_INTEGRIDAD);
        }
        if (!$a['confirmo']) {
            salir("adoptar registraría " . count($archivos) . " archivo(s) como aplicados SIN ejecutarlos. Repita con --confirmo si el esquema de '{$bd}' ya corresponde a ellos.", SALIDA_ARGS);
        }
        $ins = $pdo->prepare('INSERT INTO migraciones (archivo, checksum_sha256) VALUES (?, ?)');
        foreach ($archivos as $f) {
            $ins->execute([$f['nombre'], sha256Archivo($f['ruta'])]);
            info("  adoptada: {$f['nombre']}");
        }
        info('Listo. Las migraciones quedaron registradas (no se ejecutó SQL de esquema).');
        exit(SALIDA_OK);
    }

    // ---- aplicar ------------------------------------------------------------
    $prefijo = $a['dry'] ? '[dry-run] ' : '';
    if (!$pendientes) {
        info('No hay migraciones pendientes.');
    }
    foreach ($pendientes as $f) {
        if ($a['dry']) {
            try {
                $n = count(SqlSplitter::dividir((string) file_get_contents($f['ruta'])));
            } catch (RuntimeException $e) {
                salir("{$f['nombre']}: error al dividir el SQL: " . $e->getMessage(), SALIDA_FALLO_SQL);
            }
            info("{$prefijo}aplicaría {$f['nombre']} ({$n} sentencias)");
            continue;
        }
        info("Aplicando {$f['nombre']} ...");
        $inicio = microtime(true);
        try {
            $n = ejecutarArchivo($pdo, $f);
            $pdo->prepare('INSERT INTO migraciones (archivo, checksum_sha256) VALUES (?, ?)')
                ->execute([$f['nombre'], sha256Archivo($f['ruta'])]);
        } catch (RuntimeException | PDOException $e) {
            $m = $e instanceof PDOException ? 'No se pudo registrar la migración: ' . ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage();
            salir('FALLÓ LA MIGRACIÓN, se detiene (no se registró).' . PHP_EOL . $m . PHP_EOL
                . 'Las sentencias anteriores de este archivo YA se aplicaron (DDL hace commit implícito: no hay rollback). '
                . 'Revise el estado de la BD y corrija a mano antes de reintentar.', SALIDA_FALLO_SQL);
        }
        info(sprintf('  ok: %d sentencias, %.2f s, registrada con SHA-256.', $n, microtime(true) - $inicio));
    }

    foreach ($seeds as $f) {
        if ($a['dry']) {
            try {
                $n = count(SqlSplitter::dividir((string) file_get_contents($f['ruta'])));
            } catch (RuntimeException $e) {
                salir("{$f['nombre']}: error al dividir el SQL: " . $e->getMessage(), SALIDA_FALLO_SQL);
            }
            info("{$prefijo}ejecutaría seed {$f['nombre']} ({$n} sentencias)");
            continue;
        }
        info("Seed {$f['nombre']} ...");
        try {
            $n = ejecutarArchivo($pdo, $f);
        } catch (RuntimeException $e) {
            salir('FALLÓ EL SEED, se detiene.' . PHP_EOL . $e->getMessage() . PHP_EOL
                . 'La transacción abierta por el seed (si la había) se canceló con ROLLBACK.', SALIDA_FALLO_SQL);
        }
        info("  ok: {$n} sentencias (los seeds no se registran).");
    }
    info($a['dry'] ? 'Dry-run terminado: no se cambió nada.' : 'Terminado.');
    exit(SALIDA_OK);
} catch (PDOException $e) {
    // Sin traza: contiene argumentos del constructor de PDO.
    salir('Error de base de datos: ' . str_replace($secreto !== '' ? $secreto : "\0", '***', (string) ($e->errorInfo[2] ?? $e->getMessage())), SALIDA_ERROR);
} catch (Throwable $e) {
    salir('Error: ' . $e->getMessage(), SALIDA_ERROR);
}
