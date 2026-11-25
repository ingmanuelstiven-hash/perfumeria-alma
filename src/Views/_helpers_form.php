<?php
/**
 * Ayudantes de formulario (se incluye con require dentro de una vista: comparte $d y $v).
 * Todo valor dinámico pasa por $v->e(). Los errores por campo se enlazan con aria-describedby.
 *
 * @var array $d @var \App\View $v
 */
$val = static fn (string $k, string $def = ''): string => (string) ($d['form'][$k] ?? $def);
$errCampo = static fn (string $k): ?string => is_string($d['errores'][$k] ?? null) ? $d['errores'][$k] : null;
$ariaCampo = static fn (string $k): string => $errCampo($k) !== null ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$msgCampo = function (string $k) use ($errCampo, $v): string {
    $m = $errCampo($k);
    return $m === null ? '' : '<span class="error-campo" id="err-' . $k . '" role="alert">' . $v->e($m) . '</span>';
};
/** $attrs: atributos fijos escritos en la plantilla (nunca datos del usuario). */
$input = function (string $name, string $label, string $attrs = '', string $type = 'text', ?string $valor = null) use ($v, $val, $ariaCampo, $msgCampo): void {
    echo '<label for="f-' . $name . '">' . $v->e($label) . '</label>';
    echo '<input id="f-' . $name . '" name="' . $name . '" type="' . $type . '" value="' . $v->e($valor ?? $val($name)) . '" ' . $attrs . $ariaCampo($name) . '>';
    echo $msgCampo($name);
};
/** @param list<array{0:string,1:string}> $opciones pares [valor, etiqueta] */
$select = function (string $name, string $label, array $opciones, string $sel, string $attrs = '') use ($v, $ariaCampo, $msgCampo): void {
    echo '<label for="f-' . $name . '">' . $v->e($label) . '</label>';
    echo '<select id="f-' . $name . '" name="' . $name . '" ' . $attrs . $ariaCampo($name) . '>';
    foreach ($opciones as [$valor, $etiqueta]) {
        echo '<option value="' . $v->e($valor) . '"' . ((string) $valor === $sel ? ' selected' : '') . '>' . $v->e($etiqueta) . '</option>';
    }
    echo '</select>' . $msgCampo($name);
};
/** Resumen de errores (arriba del formulario). */
$resumenErrores = function () use ($d, $v): void {
    if (empty($d['error'])) {
        return;
    }
    echo '<div class="alerta" role="alert" id="errores"><p>' . $v->e($d['error']) . '</p>';
    $lista = array_filter((array) ($d['errores'] ?? []), 'is_string');
    if ($lista) {
        echo '<ul>';
        foreach ($lista as $campo => $msg) {
            echo '<li>' . (is_string($campo) ? '<a href="#f-' . $v->e($campo) . '">' . $v->e($msg) . '</a>' : $v->e($msg)) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
};
