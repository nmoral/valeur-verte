<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Levée quand une chaîne ne peut pas former un SIRET (14 chiffres). La saisie
 * du formulaire reste tolérante (repli manuel) : on ne construit un Siret que
 * lorsqu'on en a réellement besoin pour interroger les API.
 */
final class SiretInvalide extends \InvalidArgumentException
{
    public static function pour(string $valeur): self
    {
        return new self(\sprintf('« %s » n\'est pas un SIRET valide (14 chiffres attendus).', $valeur));
    }
}
