<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * Candidature d'un compte au statut fondateur (#61) : à l'inscription tout compte
 * est candidat « en attente » ; l'éditeur valide après contact (les fondateurs
 * aident à roder le produit), dans la limite du plafond, ou refuse.
 */
enum StatutCandidatureFondateur: string
{
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Refusee = 'refusee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Validee => 'Validée',
            self::Refusee => 'Refusée',
        };
    }
}
