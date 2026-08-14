<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

/**
 * Grille de coûts de travaux par poste, versionnée par sa période de validité.
 *
 * La grille par défaut nationale est pré-remplie sur les sept postes : un pro devant
 * une grille vide ne la remplit jamais (ONB-3, §9.2).
 */
final readonly class GrilleCout
{
    /**
     * Barème national par défaut, en euros (§9.2 — défauts nationaux au MVP).
     */
    private const array BAREME_NATIONAL_EUROS = [
        'Murs' => 120,
        'Combles' => 45,
        'PlanchersBas' => 60,
        'Menuiseries' => 720,
        'Chauffage' => 14000,
        'Ecs' => 3500,
        'Vmc' => 5200,
    ];

    /**
     * @param array<string, Montant> $montants indexé par Poste->name
     */
    private function __construct(
        private PeriodeValidite $periode,
        private array $montants,
    ) {
    }

    public static function parDefautNationale(PeriodeValidite $periode): self
    {
        $montants = [];
        foreach (self::BAREME_NATIONAL_EUROS as $poste => $euros) {
            $montants[$poste] = Montant::fromEuros($euros);
        }

        return new self($periode, $montants);
    }

    /**
     * Compose une grille à partir de montants explicites (relecture d'une grille
     * persistée). Une grille couvre toujours les sept postes (§9.2) : un poste
     * manquant est un état incohérent, refusé à la frontière.
     *
     * @param array<string, Montant> $montants indexé par Poste->name
     */
    public static function depuis(PeriodeValidite $periode, array $montants): self
    {
        foreach (Poste::cases() as $poste) {
            if (!isset($montants[$poste->name])) {
                throw new \InvalidArgumentException(\sprintf('Le poste « %s » est absent de la grille.', $poste->name));
            }
        }

        return new self($periode, $montants);
    }

    /**
     * Une grille couvre toujours les sept postes du barème (§9.2).
     *
     * @return list<Poste>
     */
    public function postes(): array
    {
        return Poste::cases();
    }

    public function montant(Poste $poste): Montant
    {
        // Garde défensive exigée par l'analyse statique : une grille couvre toujours
        // les sept postes (cf. parDefautNationale) et Poste est un enum fermé, donc
        // cette branche est inatteignable en pratique.
        return $this->montants[$poste->name]
            ?? throw new \OutOfBoundsException(\sprintf('Le poste « %s » est absent de la grille.', $poste->name));
    }

    public function periode(): PeriodeValidite
    {
        return $this->periode;
    }
}
