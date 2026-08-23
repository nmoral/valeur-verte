<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Adresse email syntaxiquement inexploitable (#310). Distincte de
 * {@see EmailDejaUtilise} : l'une dit « cette adresse n'existe pas comme
 * adresse », l'autre « elle existe déjà comme compte » — deux messages
 * différents à rendre au pro, donc deux exceptions.
 */
final class EmailInvalide extends \RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct(\sprintf('L\'adresse « %s » n\'est pas une adresse email valide.', $email));
    }
}
