<?php

declare(strict_types=1);

namespace App\Compte;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Liste des candidatures fondateur en attente (#61), enrichie de l'email du
 * compte pour que l'éditeur puisse prendre contact avant de valider. L'email est
 * lu en DBAL, hors du filtre multi-tenant.
 */
final readonly class CandidaturesEnAttente
{
    public function __construct(
        private CompteRepository $comptes,
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<CandidatureEnAttente>
     */
    public function liste(): array
    {
        $comptes = $this->comptes->enAttenteDeValidation();
        if ([] === $comptes) {
            return [];
        }

        $emails = $this->emailsParTenant(array_map(static fn (Compte $c): int => $c->tenantId(), $comptes));

        return array_map(
            static fn (Compte $c): CandidatureEnAttente => new CandidatureEnAttente(
                $c->tenantId(),
                $emails[$c->tenantId()] ?? null,
                $c->creeLe(),
            ),
            $comptes,
        );
    }

    /**
     * @param list<int> $tenantIds
     *
     * @return array<int, string>
     */
    private function emailsParTenant(array $tenantIds): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT tenant_id, email FROM utilisateur WHERE tenant_id IN (:ids) ORDER BY id ASC',
            ['ids' => $tenantIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        // Un email par tenant (le premier rencontré) : un compte n'a qu'un
        // utilisateur au MVP, mais la borne protège l'évolution multi-utilisateurs.
        $emails = [];
        foreach ($lignes as $ligne) {
            $tenantId = $ligne['tenant_id'];
            $email = $ligne['email'];
            if (is_numeric($tenantId) && \is_string($email) && !isset($emails[(int) $tenantId])) {
                $emails[(int) $tenantId] = $email;
            }
        }

        return $emails;
    }
}
