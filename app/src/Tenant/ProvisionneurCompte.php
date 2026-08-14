<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Provisionne la configuration par défaut d'un compte à sa création (ONB-3) : il
 * joue, pour le tenant fraîchement frappé, chaque {@see ConfigurationParDefaut}
 * enregistrée. Le pro n'a rien à configurer ; sa base est déjà exploitable.
 */
final readonly class ProvisionneurCompte
{
    /**
     * @param iterable<ConfigurationParDefaut> $configurations
     */
    public function __construct(
        #[AutowireIterator('app.configuration_par_defaut')]
        private iterable $configurations,
    ) {
    }

    public function provisionner(int $tenantId): void
    {
        foreach ($this->configurations as $configuration) {
            $configuration->provisionner($tenantId);
        }
    }
}
