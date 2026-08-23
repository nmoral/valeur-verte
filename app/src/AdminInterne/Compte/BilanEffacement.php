<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

/**
 * Bilan d'un balayage d'effacement (#308) : combien de comptes ont été effacés
 * définitivement lors du passage du finaliseur de grâce. Rendu à la commande CLI
 * pour journalisation.
 */
final readonly class BilanEffacement
{
    public function __construct(private int $comptesEffaces)
    {
    }

    public function comptesEffaces(): int
    {
        return $this->comptesEffaces;
    }
}
