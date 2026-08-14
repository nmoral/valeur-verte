<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

use App\Tenant\ConfigurationParDefaut;
use Psr\Clock\ClockInterface;

/**
 * Configuration par défaut de la grille de coûts (#34, ONB-3) : à la création du
 * compte, la grille nationale par défaut est matérialisée pour le tenant, valide
 * dès maintenant (D4). Le pro dispose d'une grille pré-remplie sans rien faire.
 */
final readonly class CoutsParDefaut implements ConfigurationParDefaut
{
    public function __construct(
        private GrilleCoutRepository $grilles,
        private ClockInterface $clock,
    ) {
    }

    public function provisionner(int $tenantId): void
    {
        $this->grilles->provisionner(
            $tenantId,
            GrilleCout::parDefautNationale(PeriodeValidite::depuis($this->clock->now())),
        );
    }
}
