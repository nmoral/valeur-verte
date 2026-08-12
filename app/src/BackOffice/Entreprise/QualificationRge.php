<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Qualifications RGE d'une entreprise : un statut et, le cas échéant, la liste
 * des domaines de travaux certifiés. « Indéterminé » traduit une source ADEME
 * silencieuse — jamais confondu avec une absence confirmée de qualification.
 */
final readonly class QualificationRge
{
    /**
     * @param list<string> $domaines
     */
    private function __construct(
        private StatutRge $statut,
        private array $domaines,
    ) {
    }

    /**
     * @param list<string> $domaines
     */
    public static function rge(array $domaines): self
    {
        return new self(StatutRge::Rge, $domaines);
    }

    public static function nonRge(): self
    {
        return new self(StatutRge::NonRge, []);
    }

    public static function indeterminee(): self
    {
        return new self(StatutRge::Indetermine, []);
    }

    public function statut(): StatutRge
    {
        return $this->statut;
    }

    /**
     * @return list<string>
     */
    public function domaines(): array
    {
        return $this->domaines;
    }
}
