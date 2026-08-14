<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use Doctrine\DBAL\Connection;

/**
 * Source unique du nombre de rapports consommés par compte (#47). Lit en DBAL,
 * hors du filtre multi-tenant : la même donnée sert la vue éditeur (#47), le
 * compteur d'essai côté artisan (#108) et l'écran d'intégration widget (#38) —
 * un seul calcul, pas trois.
 */
final readonly class CompteurRapportsConsommes
{
    public function __construct(private Connection $connexion)
    {
    }

    public function pour(int $tenantId): int
    {
        return self::entier($this->connexion->fetchOne(
            'SELECT count(*) FROM rapport WHERE tenant_id = :tenant',
            ['tenant' => $tenantId],
        ));
    }

    /**
     * Contacts capturés (leads) du compte : rapports porteurs d'un contact. Même
     * source que le compteur de rapports — le bilan d'usage (#108) en dérive.
     */
    public function contactsCaptures(int $tenantId): int
    {
        return self::entier($this->connexion->fetchOne(
            'SELECT count(*) FROM rapport WHERE tenant_id = :tenant AND contact_nom IS NOT NULL',
            ['tenant' => $tenantId],
        ));
    }

    /**
     * Nombre de rapports par tenant, les comptes sans rapport n'y figurant pas.
     *
     * @return array<int, int>
     */
    public function parTenant(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT tenant_id, count(*) AS n FROM rapport WHERE tenant_id IS NOT NULL GROUP BY tenant_id',
        );

        $parTenant = [];
        foreach ($lignes as $ligne) {
            $parTenant[self::entier($ligne['tenant_id'])] = self::entier($ligne['n']);
        }

        return $parTenant;
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }
}
