<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

use Doctrine\DBAL\Connection;

/**
 * Supervision des coefficients (#45) : lit ``data.coefficient_maille`` (schéma
 * « data », lecture seule pour « app ») et diagnostique chaque maille selon les
 * seuils courants, pour faire remonter les aberrations avant affichage client.
 */
final readonly class SupervisionCoefficients
{
    public function __construct(
        private Connection $connexion,
        private SeuilsSupervisionRepository $seuils,
    ) {
    }

    /**
     * @return list<MailleSupervisee>
     */
    public function mailles(): array
    {
        $seuils = $this->seuils->courants();

        $lignes = $this->connexion->fetchAllAssociative(
            'SELECT code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, alerte
             FROM data.coefficient_maille
             ORDER BY code_insee',
        );

        $mailles = [];
        foreach ($lignes as $ligne) {
            $rayon = self::entier($ligne['rayon_retenu_km']);
            $erreur = self::reel($ligne['erreur_mediane']);
            $gainEc = self::gainEcLog($ligne['coef_dpe']);
            $alerte = \is_string($ligne['alerte']) && '' !== trim($ligne['alerte']) ? $ligne['alerte'] : null;

            $mailles[] = new MailleSupervisee(
                codeInsee: \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '',
                millesime: self::entier($ligne['millesime']),
                n: self::entier($ligne['n_transactions']),
                rayonKm: 0 === $rayon ? null : $rayon,
                erreurMediane: $erreur,
                gainEcLog: $gainEc,
                diagnostic: DiagnosticMaille::pour($erreur, $gainEc, $alerte, $seuils),
            );
        }

        return $mailles;
    }

    private static function gainEcLog(mixed $coefJson): ?float
    {
        if (!\is_string($coefJson)) {
            return null;
        }

        /** @var array<string, mixed> $coef */
        $coef = json_decode($coefJson, true, flags: \JSON_THROW_ON_ERROR);
        if (!isset($coef['C'], $coef['E']) || !is_numeric($coef['C']) || !is_numeric($coef['E'])) {
            return null;
        }

        return (float) $coef['C'] - (float) $coef['E'];
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
