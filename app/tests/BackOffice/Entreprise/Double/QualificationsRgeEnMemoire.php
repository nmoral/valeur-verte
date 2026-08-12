<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise\Double;

use App\BackOffice\Entreprise\QualificationRge;
use App\BackOffice\Entreprise\QualificationsRge;
use App\BackOffice\Entreprise\Siret;

/**
 * Double d'attente pour les tests fonctionnels : qualifications RGE connues
 * pour le SIRET de référence, « non RGE » sinon. Aucun appel réseau réel.
 */
final class QualificationsRgeEnMemoire implements QualificationsRge
{
    public function pourSiret(Siret $siret): QualificationRge
    {
        if (RechercheEntrepriseEnMemoire::SIRET_CONNU !== $siret->valeur()) {
            return QualificationRge::nonRge();
        }

        return QualificationRge::rge(['Isolation des murs', 'Menuiseries']);
    }
}
