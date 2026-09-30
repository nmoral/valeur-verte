<?php

declare(strict_types=1);

namespace App\PourquoiIci;

/**
 * Une phrase du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * La nature est portée par la phrase, jamais par l'appelant : une phrase MESURÉE
 * est un fait (issu de nos coefficients), une phrase CONTEXTUELLE est une
 * hypothèse (référentiel externe, badgée « Hypothèse » au rendu — Lot 4). Cette
 * distinction est le principe cardinal de l'issue (« le chiffre est mesuré,
 * l'explication est une hypothèse ») : la confondre ruinerait la crédibilité.
 */
final readonly class Phrase
{
    private function __construct(
        public string $texte,
        private bool $hypothese,
    ) {
    }

    public static function mesure(string $texte): self
    {
        return new self($texte, false);
    }

    public static function hypothese(string $texte): self
    {
        return new self($texte, true);
    }

    public function estHypothese(): bool
    {
        return $this->hypothese;
    }
}
