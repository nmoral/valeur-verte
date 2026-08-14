<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

use App\Tenant\ConfigurationParDefaut;

/**
 * Configuration par défaut des bouquets (#35, ONB-3) : à la création du compte,
 * les trois bouquets types sont matérialisés pour le tenant. Le pro dispose ainsi
 * d'une vraie configuration éditable — dont « Rénovation d'ampleur standard »,
 * actif par défaut — sans rien avoir à faire.
 */
final readonly class BouquetsParDefaut implements ConfigurationParDefaut
{
    public function __construct(
        private BouquetRepository $bouquets,
    ) {
    }

    public function provisionner(int $tenantId): void
    {
        $this->bouquets->provisionner($tenantId, ...Bouquet::typesParDefaut());
    }
}
