<?php

declare(strict_types=1);

namespace App\BackOffice\Estimation;

use App\BackOffice\Rapport\Dpe;

/**
 * Levée quand une étiquette DPE demandée n'a pas de coefficient publié dans la
 * maille (effectif de classe sous le seuil, #115). On ne chiffre pas une classe
 * que le modèle ne couvre pas : l'estimation est indisponible plutôt qu'inventée.
 */
final class ClasseNonPubliee extends \DomainException
{
    public function __construct(Dpe $classe)
    {
        parent::__construct(\sprintf('La classe DPE %s n\'est pas publiée dans cette maille.', $classe->value));
    }
}
