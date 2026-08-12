<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Rattache au tenant d'un pro la fiche entreprise dérivée de son SIRET (ONB-2).
 * Si le SIRET est inconnu ou les sources muettes, aucune fiche n'est créée —
 * l'inscription n'est jamais bloquée pour autant.
 */
final readonly class EnregistreurFicheEntreprise
{
    public function __construct(
        private FicheEntrepriseParSiret $fiches,
        private EntrepriseRepository $entreprises,
    ) {
    }

    public function rattacher(int $tenantId, Siret $siret): void
    {
        $fiche = $this->fiches->parSiret($siret);
        if (null === $fiche) {
            return;
        }

        $entreprise = Entreprise::depuisFiche($fiche);
        $entreprise->setTenantId($tenantId);
        $this->entreprises->enregistrer($entreprise);
    }
}
