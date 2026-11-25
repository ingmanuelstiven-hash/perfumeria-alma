<?php

declare(strict_types=1);

namespace App\Security;

/** Firma válida pero exp vencido. Lleva los claims (útiles para logout y para renovar). */
final class TokenExpirado extends \RuntimeException
{
    public function __construct(public readonly object $claims)
    {
        parent::__construct('Token expirado');
    }
}
