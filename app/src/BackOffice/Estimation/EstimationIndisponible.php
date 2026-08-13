<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

/**
 * Levée quand aucune estimation ne peut être produite : commune hors couverture
 * (pas de maille) ou prix de base introuvable sans saisie du pro. On ne présente
 * pas de chiffre par défaut plutôt qu'un chiffre faux.
 */
final class EstimationIndisponible extends \DomainException
{
    public static function communeHorsCouverture(string $codeInsee): self
    {
        return new self(\sprintf('Aucune maille couverte pour la commune %s.', $codeInsee));
    }

    public static function prixDeBaseInconnu(string $codeInsee): self
    {
        return new self(\sprintf('Prix de marché indisponible pour la commune %s ; saisie du pro requise.', $codeInsee));
    }
}
