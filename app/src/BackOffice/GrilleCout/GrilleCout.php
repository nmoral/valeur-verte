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
     * @return list<Poste>
     */
    public function postes(): array
    {
        return array_values(array_filter(
            Poste::cases(),
            fn (Poste $poste): bool => isset($this->montants[$poste->name]),
        ));
    }

    public function montant(Poste $poste): Montant
    {
        return $this->montants[$poste->name]
            ?? throw new \OutOfBoundsException(\sprintf('Le poste « %s » est absent de la grille.', $poste->name));
    }

    public function periode(): PeriodeValidite
    {
        return $this->periode;
    }
}
