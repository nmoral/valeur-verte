<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Notification\Email;
use App\Notification\Mailer;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Demande de réinitialisation de mot de passe (#194, AUTH-1). Sur un email connu :
 * invalide les jetons vivants du compte, en émet un nouveau (haché en base, brut
 * dans le lien) et envoie l'email transactionnel. Sur un email inconnu : ne fait
 * **rien** — le contrôleur affiche la même confirmation neutre dans les deux cas
 * (anti-énumération de comptes).
 */
final readonly class DemandeReinitialisation
{
    public function __construct(
        private UserRepository $utilisateurs,
        private JetonReinitialisationRepository $jetons,
        private GenerateurJeton $generateur,
        private Mailer $mailer,
        private Environment $twig,
        private ClockInterface $horloge,
        private JournalAudit $journal,
        private string $appDomaine,
        private int $dureeValiditeMinutes,
    ) {
    }

    public function pour(string $email): void
    {
        $user = $this->utilisateurs->findOneBy(['email' => $email]);
        if (null === $user) {
            return;
        }

        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $maintenant = $this->horloge->now();
        $this->jetons->invaliderPourUtilisateur($userId, $maintenant);

        $jetonBrut = $this->generateur->jetonBrut();
        $this->jetons->enregistrer(new JetonReinitialisation(
            $userId,
            $this->generateur->empreinte($jetonBrut),
            $maintenant->add(new \DateInterval('PT'.$this->dureeValiditeMinutes.'M')),
        ));

        $this->mailer->envoyer(new Email(
            $email,
            'Réinitialisation de votre mot de passe',
            $this->corps($jetonBrut),
        ));

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $email,
            action: 'mot_de_passe.demande_reinitialisation',
            cible: 'utilisateur#'.$userId,
            contexte: [],
            survenuLe: $maintenant,
        ));
    }

    private function corps(string $jetonBrut): string
    {
        return $this->twig->render('email/mot_de_passe_oublie.html.twig', [
            'lienReinitialisation' => \sprintf(
                'https://app.%s/mot-de-passe-oublie/reinitialiser?jeton=%s',
                $this->appDomaine,
                $jetonBrut,
            ),
            'dureeValiditeMinutes' => $this->dureeValiditeMinutes,
        ]);
    }
}
