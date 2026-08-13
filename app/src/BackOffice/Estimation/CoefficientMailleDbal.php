<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use Doctrine\DBAL\Connection;

/**
 * Lecture des coefficients pré-calculés dans ``data.coefficient_maille`` (schéma
 * « data », accessible en lecture seule au rôle applicatif). Une ligne par
 * commune : le rayon retenu vaut 0 en mode dégradé départemental, traduit ici en
 * rayon absent.
 */
final readonly class CoefficientMailleDbal implements FournisseurCoefficientMaille
{
    public function __construct(private Connection $connexion)
    {
    }

    public function pour(string $codeInsee): ?CoefficientMaille
    {
        $ligne = $this->connexion->fetchAssociative(
            'SELECT millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe
             FROM data.coefficient_maille
             WHERE code_insee = :code',
            ['code' => $codeInsee],
        );

        if (false === $ligne) {
            return null;
        }

        $rayon = self::entier($ligne['rayon_retenu_km']);
        $coefJson = $ligne['coef_dpe'];

        /** @var array<string, float> $coefDpe */
        $coefDpe = \is_string($coefJson) ? json_decode($coefJson, true, flags: \JSON_THROW_ON_ERROR) : [];

        return CoefficientMaille::depuis(
            millesime: self::entier($ligne['millesime']),
            rayonKm: 0 === $rayon ? null : $rayon,
            n: self::entier($ligne['n_transactions']),
            erreurMediane: self::reel($ligne['erreur_mediane']),
            coefDpe: $coefDpe,
        );
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    private static function reel(mixed $valeur): float
    {
        return is_numeric($valeur) ? (float) $valeur : 0.0;
    }
}
