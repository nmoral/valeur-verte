<?php

declare(strict_types=1);

namespace App\PourquoiIci;

use Doctrine\DBAL\Connection;

/**
 * Lecture des marqueurs territoriaux dans `data.marqueurs` (#341, Lot 4).
 *
 * L'app est en lecture seule sur le schéma `data` (décision D6) : SELECT direct,
 * agrégation des lignes en une map `nom => valeur` portée par {@see Marqueurs}.
 *
 * Pour une commune, on lit ses marqueurs communaux ET ceux de son département ;
 * l'ordre `code_insee NULLS FIRST` place le département d'abord, si bien qu'un
 * marqueur **communal de même nom écrase le départemental** (variation locale,
 * anti-duplicate #341).
 */
final readonly class MarqueursDbal implements FournisseurMarqueurs
{
    public function __construct(private Connection $connexion)
    {
    }

    public function pourCommune(string $codeInsee, string $codeDepartement): Marqueurs
    {
        return $this->agreger(
            'SELECT nom, valeur FROM data.marqueurs
             WHERE code_insee = :insee OR code_departement = :dept
             ORDER BY code_insee NULLS FIRST',
            ['insee' => $codeInsee, 'dept' => $codeDepartement],
        );
    }

    public function pourDepartement(string $codeDepartement): Marqueurs
    {
        return $this->agreger(
            'SELECT nom, valeur FROM data.marqueurs
             WHERE code_departement = :dept AND code_insee IS NULL',
            ['dept' => $codeDepartement],
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function agreger(string $sql, array $params): Marqueurs
    {
        $valeurs = [];
        foreach ($this->connexion->fetchAllAssociative($sql, $params) as $ligne) {
            $nom = $ligne['nom'];
            $valeur = $ligne['valeur'];
            if (\is_string($nom) && \is_string($valeur)) {
                $valeurs[$nom] = $valeur;
            }
        }

        return Marqueurs::depuis($valeurs);
    }
}
