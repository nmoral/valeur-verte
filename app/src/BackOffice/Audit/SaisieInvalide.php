<?php

declare(strict_types=1);

namespace App\BackOffice\Audit;

/**
 * Saisie d'audit hors bornes de vraisemblance (§9.8) : protection de crédibilité,
 * pas simple validation de formulaire. Porte l'ensemble des messages, indexés par
 * champ, pour tout signaler d'un coup.
 */
final class SaisieInvalide extends \DomainException
{
    /**
     * @param array<string, string> $erreurs
     */
    public function __construct(private readonly array $erreurs)
    {
        parent::__construct(implode(' ', $erreurs));
    }

    /**
     * @return array<string, string>
     */
    public function erreurs(): array
    {
        return $this->erreurs;
    }
}
