<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Port : retrouve l'identité administrative d'un établissement à partir de son
 * SIRET. Renvoie null quand l'entreprise est inconnue, fermée, ou que la source
 * est indisponible — dans tous les cas, l'inscription bascule en saisie manuelle.
 */
interface RechercheEntreprise
{
    public function parSiret(Siret $siret): ?IdentiteEntreprise;
}
