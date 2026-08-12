<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Assemble la fiche entreprise à partir du SIRET : l'identité (Recherche
 * d'Entreprises) est indispensable — sans elle, on bascule en saisie manuelle
 * (null) ; les qualifications RGE l'enrichissent sans jamais la bloquer.
 */
final readonly class FicheEntrepriseParSiret
{
    public function __construct(
        private RechercheEntreprise $recherche,
        private QualificationsRge $qualifications,
    ) {
    }

    public function parSiret(Siret $siret): ?FicheEntreprise
    {
        $identite = $this->recherche->parSiret($siret);
        if (null === $identite) {
            return null;
        }

        $qualification = $this->qualifications->pourSiret($siret);

        return FicheEntreprise::creer(
            $siret,
            $identite->raisonSociale(),
            $identite->adresse(),
            $identite->codeNaf(),
            $identite->effectif(),
            $qualification->statut(),
            $qualification->domaines(),
        );
    }
}
