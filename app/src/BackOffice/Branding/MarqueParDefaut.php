<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

use App\Tenant\ConfigurationParDefaut;

/**
 * Configuration par défaut de la marque (ONB-4) : à la création du compte, une
 * marque par défaut est matérialisée pour le tenant — le pro peut produire un PDF
 * à ses couleurs sans rien configurer, puis l'ajuster quand il le souhaite.
 */
final readonly class MarqueParDefaut implements ConfigurationParDefaut
{
    public function __construct(
        private BrandingRepository $brandings,
    ) {
    }

    public function provisionner(int $tenantId): void
    {
        $this->brandings->provisionner($tenantId);
    }
}
