<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

use Doctrine\DBAL\Connection;

/**
 * Vue de pilotage des pages publiques côté éditeur (#268, épic #6). Lecture seule,
 * **tous tenants confondus** : c'est le propre de l'admin interne.
 *
 * La lecture passe par DBAL plutôt que par l'ORM pour ne pas dépendre du filtre de
 * cloisonnement — et pour ne pas tracer une levée de cloisonnement (D3) à chaque
 * affichage de la liste, ce que ferait {@see \App\Tenant\AccesTousTenants}. Les
 * **écritures**, elles, y passent : couper une page est un acte, pas une lecture.
 */
final readonly class SupervisionPagesPubliques
{
    public function __construct(
        private Connection $connexion,
    ) {
    }

    /**
     * @return list<PagePubliqueSupervisee>
     */
    public function pages(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT token, slug, tenant_id, active, suspendue_le, alertee_le, challenge_renforce_jusqua
             FROM page_publique
             ORDER BY suspendue_le DESC NULLS LAST, slug ASC',
        );

        $pages = [];
        foreach ($lignes as $ligne) {
            $token = $ligne['token'];
            $slug = $ligne['slug'];
            $tenantId = $ligne['tenant_id'];
            if (!\is_string($token) || !\is_string($slug) || !is_numeric($tenantId)) {
                continue;
            }

            $pages[] = new PagePubliqueSupervisee(
                token: $token,
                slug: $slug,
                tenantId: (int) $tenantId,
                activeParLePro: (bool) $ligne['active'],
                suspendueLe: $this->date($ligne['suspendue_le']),
                alerteeLe: $this->date($ligne['alertee_le']),
                challengeRenforceJusqua: $this->date($ligne['challenge_renforce_jusqua']),
            );
        }

        return $pages;
    }

    private function date(mixed $valeur): ?\DateTimeImmutable
    {
        return \is_string($valeur) ? new \DateTimeImmutable($valeur) : null;
    }
}
