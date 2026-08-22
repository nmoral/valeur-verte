<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

/**
 * Ce qu'un passage de rétention a fait (D10, #68) : deux comptes disjoints —
 * la suppression passe avant l'anonymisation, un rapport n'est donc jamais
 * compté dans les deux.
 */
final readonly class BilanRetention
{
    public function __construct(
        private int $anonymises,
        private int $supprimes,
    ) {
    }

    public function anonymises(): int
    {
        return $this->anonymises;
    }

    public function supprimes(): int
    {
        return $this->supprimes;
    }
}
