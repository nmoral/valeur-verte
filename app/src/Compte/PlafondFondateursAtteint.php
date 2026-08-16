<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * La formule Fondateur est plafonnée (#61) : au-delà du nombre de comptes
 * fondateurs autorisé, une nouvelle validation est refusée.
 */
final class PlafondFondateursAtteint extends \DomainException
{
    public function __construct(int $plafond)
    {
        parent::__construct(\sprintf('Plafond de %d comptes fondateurs atteint.', $plafond));
    }
}
