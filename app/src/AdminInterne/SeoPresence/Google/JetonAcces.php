<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

/**
 * Fournit un jeton d'accès OAuth2 aux API Google (#346), ou `null` si la source
 * n'est pas configurée / indisponible. Abstraction de {@see JetonCompteServiceGoogle}
 * pour que les clients (Search Console) soient testables sans échange de jeton réel.
 */
interface JetonAcces
{
    public function jeton(): ?string;
}
