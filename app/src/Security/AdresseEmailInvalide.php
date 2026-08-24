<?php

declare(strict_types=1);

namespace App\Security;

final class AdresseEmailInvalide extends \InvalidArgumentException
{
    public static function pour(string $saisie): self
    {
        return new self(\sprintf('« %s » n\'est pas une adresse email valide.', $saisie));
    }
}
