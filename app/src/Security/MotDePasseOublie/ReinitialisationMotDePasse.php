<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réinitialisation effective du mot de passe (#194, AUTH-1). Vérifie le jeton reçu
 * (empreinte, non expiré, non consommé), pose le nouveau mot de passe haché puis
 * **consomme** le jeton — usage unique. Tout jeton refusé lève {@see JetonInvalide},
 * sans jamais distinguer inconnu / expiré / déjà utilisé.
 */
final readonly class ReinitialisationMotDePasse
{
    public function __construct(
        private JetonReinitialisationRepository $jetons,
        private UserRepository $utilisateurs,
        private GenerateurJeton $generateur,
        private UserPasswordHasherInterface $hasher,
        private ClockInterface $horloge,
        private JournalAudit $journal,
    ) {
    }

    /**
     * Le jeton reçu (dans l'URL) désigne-t-il un jeton encore utilisable ?
     * Lecture seule : gate l'affichage du formulaire sans consommer le jeton.
     */
    public function estUtilisable(string $jetonBrut): bool
    {
        $jeton = $this->jetons->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));

        return null !== $jeton && $jeton->estUtilisable($this->horloge->now());
    }

    /**
     * @throws JetonInvalide
     */
    public function reinitialiser(string $jetonBrut, string $nouveauMotDePasse): void
    {
        $maintenant = $this->horloge->now();
        $jeton = $this->jetons->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));

        if (null === $jeton || !$jeton->estUtilisable($maintenant)) {
            throw new JetonInvalide();
        }

        $user = $this->utilisateurs->find($jeton->userId());
        if (null === $user) {
            throw new JetonInvalide();
        }

        $user->definirMotDePasse($this->hasher->hashPassword($user, $nouveauMotDePasse));
        $this->utilisateurs->enregistrer($user);

        $jeton->marquerUtilise($maintenant);
        $this->jetons->enregistrer($jeton);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $user->getEmail(),
            action: 'mot_de_passe.reinitialise',
            cible: 'utilisateur#'.$jeton->userId(),
            contexte: [],
            survenuLe: $maintenant,
        ));
    }
}
