<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Bilan d'usage d'un compte (#108) : le chiffre qui fait la vente à la place du
 * commercial — « N rapports générés, M contacts capturés ». Présenté à l'artisan
 * (bandeau, écran de bascule), jamais un simple formulaire de paiement.
 */
final readonly class BilanUsage
{
    public function __construct(
        private int $rapportsGeneres,
        private int $contactsCaptures,
    ) {
    }

    public function rapportsGeneres(): int
    {
        return $this->rapportsGeneres;
    }

    public function contactsCaptures(): int
    {
        return $this->contactsCaptures;
    }
}
