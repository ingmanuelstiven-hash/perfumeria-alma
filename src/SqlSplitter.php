<?php

declare(strict_types=1);

namespace App;

/**
 * Divisor de scripts SQL en sentencias (para scripts/migrate.php).
 *
 * Reconoce lo mismo que el cliente `mysql` en lo que importa para dividir:
 *  - Cadenas '...' y "..." (con \ de escape y comilla doblada) e identificadores
 *    `...`: un ; dentro de ellos NO termina la sentencia.
 *  - Comentarios `-- ` (el guion doble exige espacio/control/fin después), `#` y
 *    bloques de comentario. Los comentarios simples se descartan; los condicionales
 *    de MySQL/MariaDB (los que abren con barra-asterisco-admiración) y las pistas
 *    (barra-asterisco-más) se conservan porque el servidor SÍ los ejecuta.
 *  - `DELIMITER xx` (solo al inicio de una línea y con la sentencia vacía), para
 *    triggers/rutinas con BEGIN ... END; el propio DELIMITER no se envía al servidor.
 *  - Una última sentencia sin delimitador final se devuelve igualmente.
 *
 * Supone sql_mode sin NO_BACKSLASH_ESCAPES (el de la app y el de los archivos).
 * Las sentencias devueltas no incluyen el delimitador.
 */
final class SqlSplitter
{
    /**
     * @return list<array{sql: string, linea: int}> sentencia y línea (1-based) donde empieza
     * @throws \RuntimeException si hay una cadena o comentario sin cerrar o un DELIMITER inválido
     */
    public static function dividir(string $sql): array
    {
        // BOM UTF-8 al inicio (Bloc de notas de Windows).
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) {
            $sql = substr($sql, 3);
        }

        $n = strlen($sql);
        $delim = ';';
        $resultado = [];
        $buf = '';
        $significativo = false;   // ¿el buffer tiene algo más que blancos/comentarios?
        $lineaInicio = 1;
        $linea = 1;
        $inicioLinea = true;      // true si solo hubo blancos desde el último salto de línea
        $i = 0;

        $cerrar = function () use (&$buf, &$significativo, &$resultado, &$lineaInicio, &$linea): void {
            if ($significativo) {
                $resultado[] = ['sql' => trim($buf), 'linea' => $lineaInicio];
            }
            $buf = '';
            $significativo = false;
            $lineaInicio = $linea;
        };

        while ($i < $n) {
            $c = $sql[$i];

            // DELIMITER: al inicio de línea, con la sentencia vacía.
            if ($inicioLinea && !$significativo && ($c === 'D' || $c === 'd')
                && preg_match('/\G[Dd][Ee][Ll][Ii][Mm][Ii][Tt][Ee][Rr][ \t]+([^\s]+)[ \t]*(?:\r\n|\n|\r|$)/A', $sql, $m, 0, $i)) {
                $nuevo = $m[1];
                if (str_contains($nuevo, '\\') || $nuevo[0] === '\'' || $nuevo[0] === '"' || $nuevo[0] === '`') {
                    throw new \RuntimeException("Línea {$linea}: DELIMITER inválido ({$nuevo}).");
                }
                $delim = $nuevo;
                $i += strlen($m[0]);
                $linea += preg_match('/\n|\r(?!\n)/', $m[0]) ? 1 : 0;
                $buf = '';
                $lineaInicio = $linea;
                $inicioLinea = true;
                continue;
            }

            // Fin de sentencia (se comprueba antes que los comentarios por si el delimitador empieza por / o -).
            if (substr_compare($sql, $delim, $i, strlen($delim)) === 0) {
                $i += strlen($delim);
                $cerrar();
                $inicioLinea = false;
                continue;
            }

            // Salto de línea.
            if ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($sql[$i + 1] ?? '') === "\n") {
                    $buf .= "\r";
                    $i++;
                    $c = "\n";
                }
                $buf .= $c;
                $i++;
                $linea++;
                $inicioLinea = true;
                if (!$significativo) {
                    $lineaInicio = $linea;
                    $buf = '';
                }
                continue;
            }

            // Blancos.
            if ($c === ' ' || $c === "\t") {
                $buf .= $c;
                $i++;
                continue;
            }

            // Comentario de línea: "-- " o "#".
            if ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-'
                    && ($i + 2 >= $n || ctype_space($sql[$i + 2]) || ord($sql[$i + 2]) < 32))) {
                $fin = strcspn($sql, "\r\n", $i);
                $i += $fin;   // el salto de línea se procesa en la vuelta siguiente
                continue;
            }

            // Comentario de bloque.
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $cierre = strpos($sql, '*/', $i + 2);
                if ($cierre === false) {
                    throw new \RuntimeException("Línea {$linea}: comentario /* sin cerrar.");
                }
                $bloque = substr($sql, $i, $cierre + 2 - $i);
                $conservar = isset($bloque[2]) && ($bloque[2] === '!' || $bloque[2] === '+');
                if ($conservar) {
                    if (!$significativo) {
                        $lineaInicio = $linea;
                    }
                    $significativo = true;
                    $buf .= $bloque;
                } else {
                    $buf .= ' ';
                }
                $linea += substr_count($bloque, "\n");
                $i += strlen($bloque);
                $inicioLinea = false;
                continue;
            }

            // Cadenas e identificadores entre comillas.
            if ($c === '\'' || $c === '"' || $c === '`') {
                if (!$significativo) {
                    $lineaInicio = $linea;
                }
                $significativo = true;
                $inicioLinea = false;
                $lineaComilla = $linea;
                $j = $i + 1;
                while (true) {
                    if ($j >= $n) {
                        throw new \RuntimeException("Línea {$lineaComilla}: cadena o identificador sin cerrar ({$c}).");
                    }
                    $d = $sql[$j];
                    if ($d === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($d === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {   // comilla doblada
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $trozo = substr($sql, $i, $j + 1 - $i);
                $linea += substr_count($trozo, "\n");
                $buf .= $trozo;
                $i = $j + 1;
                continue;
            }

            // Carácter normal.
            if (!$significativo) {
                $lineaInicio = $linea;
            }
            $significativo = true;
            $inicioLinea = false;
            $buf .= $c;
            $i++;
        }

        $cerrar();
        return $resultado;
    }
}
