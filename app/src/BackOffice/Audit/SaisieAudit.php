<?php

declare(strict_types=1);

namespace App\BackOffice\Audit;

use App\BackOffice\Rapport\Dpe;

/**
 * Saisie du pro en mode audit (§4) : ce qu'il a constaté sur place. Value object
 * immuable qui ne se construit que si les bornes de vraisemblance (§9.8) sont
 * respectées — sinon SaisieInvalide porte toutes les erreurs à la fois.
 */
final readonly class SaisieAudit
{
    private const SURFACE_BATIE_MIN = 20;
    private const SURFACE_BATIE_MAX = 400;
    private const SURFACE_TERRAIN_MIN = 0;
    private const SURFACE_TERRAIN_MAX = 10000;
    private const RATIO_PIECE_MIN = 9.0;
    private const RATIO_PIECE_MAX = 60.0;

    private function __construct(
        private string $adresse,
        private int $surfaceBatie,
        private int $surfaceTerrain,
        private Dpe $dpeAvant,
        private Dpe $dpeApres,
        private ?int $nombrePieces,
    ) {
    }

    public static function depuis(
        string $adresse,
        int $surfaceBatie,
        int $surfaceTerrain,
        Dpe $dpeAvant,
        Dpe $dpeApres,
        ?int $nombrePieces = null,
    ): self {
        $adresse = trim($adresse);
        $erreurs = [];

        if ('' === $adresse) {
            $erreurs['adresse'] = 'L\'adresse est obligatoire.';
        }
        if ($surfaceBatie < self::SURFACE_BATIE_MIN || $surfaceBatie > self::SURFACE_BATIE_MAX) {
            $erreurs['surfaceBatie'] = \sprintf('La surface bâtie doit être comprise entre %d et %d m².', self::SURFACE_BATIE_MIN, self::SURFACE_BATIE_MAX);
        }
        if ($surfaceTerrain < self::SURFACE_TERRAIN_MIN || $surfaceTerrain > self::SURFACE_TERRAIN_MAX) {
            $erreurs['surfaceTerrain'] = \sprintf('La surface du terrain doit être comprise entre %d et %d m².', self::SURFACE_TERRAIN_MIN, self::SURFACE_TERRAIN_MAX);
        }
        if (!$dpeApres->estMeilleurQue($dpeAvant)) {
            $erreurs['dpeApres'] = 'Le DPE cible doit être meilleur que le DPE actuel.';
        }

        if ([] !== $erreurs) {
            throw new SaisieInvalide($erreurs);
        }

        return new self($adresse, $surfaceBatie, $surfaceTerrain, $dpeAvant, $dpeApres, $nombrePieces);
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function surfaceBatie(): int
    {
        return $this->surfaceBatie;
    }

    public function surfaceTerrain(): int
    {
        return $this->surfaceTerrain;
    }

    public function dpeAvant(): Dpe
    {
        return $this->dpeAvant;
    }

    public function dpeApres(): Dpe
    {
        return $this->dpeApres;
    }

    public function nombrePieces(): ?int
    {
        return $this->nombrePieces;
    }

    /**
     * Signaux non bloquants de vraisemblance (§9.8) : ils alertent sans empêcher
     * la génération. Un ratio surface/pièces hors plage usuelle est suspect.
     *
     * @return list<string>
     */
    public function avertissements(): array
    {
        $avertissements = [];

        if (null !== $this->nombrePieces && $this->nombrePieces > 0) {
            $ratio = $this->surfaceBatie / $this->nombrePieces;
            if ($ratio < self::RATIO_PIECE_MIN || $ratio > self::RATIO_PIECE_MAX) {
                $avertissements[] = \sprintf(
                    'Le rapport surface/pièces (%d m²/pièce) semble inhabituel — vérifiez la saisie.',
                    (int) round($ratio),
                );
            }
        }

        return $avertissements;
    }
}
