<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Suit une visite du parcours d'inscription (#111) au fil de ses requêtes. Le
 * funnel étant pré-compte et sans cookie applicatif dédié, l'identifiant de visite
 * vit dans la session HTTP : c'est lui qui relie l'écran affiché, le SIRET
 * renseigné et le compte créé d'une même visite. L'écran n'est compté qu'une fois
 * par visite — un rechargement ne gonfle pas le dénominateur du taux d'abandon.
 *
 * Point d'entrée applicatif appelé par les contrôleurs (glue) ; toute la logique
 * de collecte vit ici, pas dans les contrôleurs.
 */
final readonly class SuiviInscription
{
    private const string CLE_VISITE = 'onboarding.inscription.visite';
    private const string CLE_AFFICHEE = 'onboarding.inscription.affichee';

    public function __construct(
        private JournalParcoursInscription $journal,
        private SessionParcoursInscription $sessions,
    ) {
    }

    public function ecranAffiche(SessionInterface $session): void
    {
        $visite = $this->visite($session);
        if (true === $session->get(self::CLE_AFFICHEE)) {
            return;
        }

        $this->journal->enregistrer($visite, TypeEvenementInscription::InscriptionAffichee);
        $session->set(self::CLE_AFFICHEE, true);
    }

    public function siretRenseigne(SessionInterface $session): void
    {
        $this->journal->enregistrer($this->visite($session), TypeEvenementInscription::SiretRenseigne);
    }

    public function compteCree(SessionInterface $session): void
    {
        $this->journal->enregistrer($this->visite($session), TypeEvenementInscription::CompteCree);
    }

    private function visite(SessionInterface $session): string
    {
        $visite = $session->get(self::CLE_VISITE);
        if (\is_string($visite) && $this->sessions->estValide($visite)) {
            return $visite;
        }

        $nouvelle = $this->sessions->demarrer();
        $session->set(self::CLE_VISITE, $nouvelle);

        return $nouvelle;
    }
}
