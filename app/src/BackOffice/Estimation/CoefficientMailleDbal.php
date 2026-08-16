<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use Doctrine\DBAL\Connection;

/**
 * Lecture des coefficients pré-calculés dans ``data.coefficient_maille`` (schéma
 * « data », accessible en lecture seule au rôle applicatif). Une ligne par
 * commune : le rayon retenu vaut 0 hors mode local, traduit ici en rayon absent.
 * Les crans de la cascade (#169) — ``mode``, ``palier``, ``bandes`` — situent
 * l'estimation, signalent le tier dégradé et servent les classes DPE fusionnées.
 */
final readonly class CoefficientMailleDbal implements FournisseurCoefficientMaille
{
    public function __construct(private Connection $connexion)
    {
    }

    public function pour(string $codeInsee): ?CoefficientMaille
    {
        $ligne = $this->connexion->fetchAssociative(
            'SELECT millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, mode, palier, bandes
             FROM data.coefficient_maille
             WHERE code_insee = :code',
            ['code' => $codeInsee],
        );

        if (false === $ligne) {
            return null;
        }

        $rayon = self::entier($ligne['rayon_retenu_km']);
        $rayonKm = 0 === $rayon ? null : $rayon;

        /** @var array<string, float> $coefDpe */
        $coefDpe = self::decode($ligne['coef_dpe']);
        /** @var array<string, string> $bandes */
        $bandes = self::decode($ligne['bandes']);

        return CoefficientMaille::depuis(
            millesime: self::entier($ligne['millesime']),
            rayonKm: $rayonKm,
            n: self::entier($ligne['n_transactions']),
            erreurMediane: self::reel($ligne['erreur_mediane']),
            coefDpe: $coefDpe,
            portee: PorteeEstimation::depuis(self::texteOuNull($ligne['mode']), $rayonKm),
            palier: PalierFiabilite::depuis(self::texteOuNull($ligne['palier'])),
            bandes: $bandes,
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

    private static function texteOuNull(mixed $valeur): ?string
    {
        return \is_string($valeur) ? $valeur : null;
    }

    /**
     * Décode une colonne JSONB en mapping. Une colonne absente (ligne héritée)
     * ou nulle donne un mapping vide.
     *
     * @return array<string, mixed>
     */
    private static function decode(mixed $json): array
    {
        if (!\is_string($json)) {
            return [];
        }

        /** @var array<string, mixed> $mapping */
        $mapping = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $mapping;
    }
}
