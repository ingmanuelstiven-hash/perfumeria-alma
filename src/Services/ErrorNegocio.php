<?php

declare(strict_types=1);

namespace App\Services;

/** Error esperado con mensaje apto para el usuario y estado HTTP. */
final class ErrorNegocio extends \RuntimeException
{
    /** @param list<string> $errores */
    public function __construct(string $mensaje, public readonly int $status = 422, public readonly array $errores = [])
    {
        parent::__construct($mensaje);
    }
}
