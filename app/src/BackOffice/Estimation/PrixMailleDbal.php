<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use Doctrine\DBAL\Connection;

/**
 * Médiane du prix au m² des ventes d'une commune (``data.vente``), prix de base
 * par défaut de la chaîne de calcul (§6). Null quand aucune vente n'est
 * exploitable — le pro devra alors saisir lui-même une valeur.
 */
final readonly class PrixMailleDbal implements FournisseurPrixMaille
{
    public function __construct(private Connection $connexion)
    {
    }

    public function prixM2Median(string $codeInsee): ?float
    {
        $mediane = $this->connexion->fetchOne(
            'SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY prix_m2)
             FROM data.vente
             WHERE code_insee = :code AND prix_m2 IS NOT NULL',
            ['code' => $codeInsee],
        );

        return is_numeric($mediane) ? (float) $mediane : null;
    }
}
