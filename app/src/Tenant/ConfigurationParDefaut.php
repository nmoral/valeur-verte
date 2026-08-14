<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Configuration par défaut d'un tenant, appliquée à la création du compte pour
 * que le pro dispose d'une base exploitable sans rien avoir à faire (ONB-3).
 *
 * Chaque domaine configurable (bouquets, marque, coûts…) fournit son
 * implémentation ; toutes sont collectées et jouées par {@see ProvisionneurCompte}.
 * Ajouter une configuration ne touche pas au provisionneur (ouvert/fermé).
 */
#[AutoconfigureTag('app.configuration_par_defaut')]
interface ConfigurationParDefaut
{
    public function provisionner(int $tenantId): void;
}
