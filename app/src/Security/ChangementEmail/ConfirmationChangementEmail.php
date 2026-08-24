<?php

declare(strict_types=1);

namespace App\Security\ChangementEmail;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;

/**
 * Confirmation du changement d'adresse (#310). C'est le seul endroit où l'adresse
 * du compte bascule : le clic sur le lien reçu à la nouvelle adresse prouve que
 * la boîte est relevée, donc que le compte reste joignable — pour se connecter
 * comme pour récupérer un mot de passe oublié (#194).
 *
 * L'unicité de l'adresse est revérifiée **au moment du clic** : entre la demande
 * et la confirmation, quelqu'un a pu s'inscrire avec l'adresse visée. Laisser
 * filer ferait trancher la contrainte d'unicité par une erreur 500 au visage du
 * pro, sur une route publique — on refuse proprement, le compte gardant son
 * adresse actuelle.
 */
final readonly class ConfirmationChangementEmail
{
    public function __construct(
        private DemandeChangementEmailRepository $demandes,
        private UserRepository $utilisateurs,
        private GenerateurJeton $generateur,
        private ClockInterface $horloge,
        private JournalAudit $journal,
    ) {
    }

    /**
     * Rend le compte basculé : la session ouverte sur l'ancien identifiant doit
     * être rafraîchie par l'appelant, sinon le firewall ne retrouve plus le
     * compte au rechargement et déconnecte le pro au moment même où il vient de
     * prouver qu'il est bien lui.
     *
     * @throws ChangementEmailInvalide
     */
    public function confirmer(string $jetonBrut): User
    {
        $maintenant = $this->horloge->now();
        $demande = $this->demandes->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));

        if (null === $demande || !$demande->estConfirmable($maintenant)) {
            throw new ChangementEmailInvalide();
        }

        $user = $this->utilisateurs->find($demande->userId());
        if (null === $user) {
            throw new ChangementEmailInvalide();
        }

        $nouvelEmail = $demande->nouvelEmail();
        if (null !== $this->utilisateurs->parAdresse($nouvelEmail)) {
            throw new ChangementEmailInvalide();
        }

        $ancienEmail = $user->getEmail();
        $user->changerEmail($nouvelEmail);
        // Le clic est exactement la preuve que réclame la vérification d'email
        // (#105) : garder le compte « non vérifié » après l'avoir apportée
        // afficherait un rappel que rien ne pourrait plus lever.
        $user->marquerEmailVerifie($maintenant);
        $this->utilisateurs->enregistrer($user);

        $demande->marquerConfirmee($maintenant);
        $this->demandes->enregistrer($demande);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $nouvelEmail,
            action: 'compte.changement_email_confirme',
            cible: 'utilisateur#'.$demande->userId(),
            contexte: ['ancien_email' => $ancienEmail, 'nouvel_email' => $nouvelEmail],
            survenuLe: $maintenant,
        ));

        return $user;
    }
}
