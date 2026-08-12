<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise\Double;

use App\BackOffice\Entreprise\IdentiteEntreprise;
use App\BackOffice\Entreprise\RechercheEntreprise;
use App\BackOffice\Entreprise\Siret;

/**
 * Double d'attente pour les tests fonctionnels : identité connue pour un SIRET
 * de référence, null sinon (repli manuel). Évite tout appel réseau réel.
 */
final class RechercheEntrepriseEnMemoire implements RechercheEntreprise
{
    public const SIRET_CONNU = '12345678901234';

    public function parSiret(Siret $siret): ?IdentiteEntreprise
    {
        if (self::SIRET_CONNU !== $siret->valeur()) {
            return null;
        }

        return IdentiteEntreprise::creer(
            'Renov Habitat',
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
        );
    }
}
