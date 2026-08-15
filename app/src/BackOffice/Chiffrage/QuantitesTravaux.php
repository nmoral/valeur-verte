<?php

declare(strict_types=1);

namespace App\BackOffice\Chiffrage;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\GrilleCout\Unite;

/**
 * Quantités par poste déduites de la saisie d'audit (#26).
 *
 * La grille de coûts (#34) exprime ses montants en €/m², €/unité ou en forfait
 * (cf. {@see Poste::unite()}) : convertir la grille en euros suppose une quantité.
 * Rien n'est demandé de plus au pro — le formulaire d'audit reste court (ONB-3),
 * les quantités sont dérivées de ce qu'il saisit déjà.
 */
final readonly class QuantitesTravaux
{
    /**
     * Un logement compte environ trois ouvrants pour deux pièces. Le ratio est
     * exprimé en entiers pour rester exact à l'arrondi supérieur près.
     */
    private const int OUVRANTS_POUR_DEUX_PIECES = 3;

    /**
     * Le nombre de pièces est facultatif à l'audit : à défaut, on l'estime à
     * raison d'une pièce pour 20 m² avant d'appliquer le ratio d'ouvrants.
     */
    private const int SURFACE_PAR_PIECE_M2 = 20;

    private function __construct(
        private int $surfaceBatie,
        private int $ouvrants,
    ) {
    }

    public static function depuis(SaisieAudit $saisie): self
    {
        $pieces = $saisie->nombrePieces()
            ?? intdiv($saisie->surfaceBatie(), self::SURFACE_PAR_PIECE_M2);

        return new self($saisie->surfaceBatie(), self::ouvrantsPour(max(1, $pieces)));
    }

    public function pour(Poste $poste): int
    {
        return match ($poste->unite()) {
            Unite::EuroParM2 => $this->surfaceBatie,
            Unite::EuroParUnite => $this->ouvrants,
            Unite::EuroForfait => 1,
        };
    }

    /**
     * Arrondi supérieur de `pièces × 1,5`, en arithmétique entière.
     */
    private static function ouvrantsPour(int $pieces): int
    {
        return intdiv($pieces * self::OUVRANTS_POUR_DEUX_PIECES + 1, 2);
    }
}
