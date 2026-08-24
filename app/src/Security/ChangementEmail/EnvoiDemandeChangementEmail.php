<?php

declare(strict_types=1);

namespace App\Security\ChangementEmail;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Notification\Email;
use App\Notification\Mailer;
use App\Security\EmailDejaUtilise;
use App\Security\EmailInvalide;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Demande de changement d'adresse email (#310). N'écrit rien sur le compte : elle
 * émet une demande à confirmer et envoie **deux** emails.
 *
 * - à la **nouvelle** adresse, le lien de confirmation : c'est le clic qui prouve
 *   que la boîte est relevée. Sans cette preuve, une faute de frappe poserait sur
 *   le compte une adresse injoignable — or l'adresse est l'identifiant de
 *   connexion **et** la cible du mot de passe oublié (#194) : le compte serait
 *   perdu des deux côtés à la fois ;
 * - à l'**ancienne**, un avertissement sans lien : c'est le garde-fou contre la
 *   prise de contrôle d'un compte dont la session a été volée. Il nomme l'adresse
 *   visée — un avertissement qu'on ne peut pas recouper n'est pas actionnable —
 *   mais ne porte rien qui permette de valider la bascule.
 *
 * Le générateur de jetons est celui de la réinitialisation (#194) : même besoin
 * (256 bits d'aléa, empreinte SHA-256 stockée seule), aucune raison d'en écrire
 * un second.
 */
final readonly class EnvoiDemandeChangementEmail
{
    public function __construct(
        private UserRepository $utilisateurs,
        private DemandeChangementEmailRepository $demandes,
        private GenerateurJeton $generateur,
        private Mailer $mailer,
        private Environment $twig,
        private ClockInterface $horloge,
        private JournalAudit $journal,
        private string $appDomaine,
        private int $dureeValiditeMinutes,
    ) {
    }

    /**
     * @throws EmailInvalide    l'adresse n'a pas la forme d'une adresse
     * @throws EmailDejaUtilise l'adresse est celle d'un compte — fût-ce le sien
     */
    public function pour(User $user, string $nouvelEmail): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $nouvelEmail = trim($nouvelEmail);
        if (false === filter_var($nouvelEmail, \FILTER_VALIDATE_EMAIL)) {
            throw new EmailInvalide($nouvelEmail);
        }
        // Couvre aussi l'adresse actuelle du demandeur : demander sa propre
        // adresse n'est pas une bascule, et l'accepter enverrait un avertissement
        // pour un changement qui n'en est pas un.
        // Unicité sur l'**identité** (#331) : un alias de l'adresse d'autrui est
        // la même boîte, et un alias de la sienne le même compte.
        if (null !== $this->utilisateurs->parAdresse($nouvelEmail)) {
            throw new EmailDejaUtilise($nouvelEmail);
        }

        $maintenant = $this->horloge->now();
        $this->demandes->invaliderPourUtilisateur($userId, $maintenant);

        $jetonBrut = $this->generateur->jetonBrut();
        $this->demandes->enregistrer(new DemandeChangementEmail(
            $userId,
            $nouvelEmail,
            $this->generateur->empreinte($jetonBrut),
            $maintenant->add(new \DateInterval('PT'.$this->dureeValiditeMinutes.'M')),
        ));

        $this->mailer->envoyer(new Email(
            $nouvelEmail,
            'Confirmez votre nouvelle adresse email',
            $this->twig->render('email/changement_email_confirmation.html.twig', [
                'lienConfirmation' => \sprintf(
                    'https://app.%s/mon-compte/email/confirmer?jeton=%s',
                    $this->appDomaine,
                    $jetonBrut,
                ),
                'dureeValiditeMinutes' => $this->dureeValiditeMinutes,
            ]),
        ));

        $this->mailer->envoyer(new Email(
            $user->getEmail(),
            'Une demande de changement d\'adresse email a été faite sur votre compte',
            $this->twig->render('email/changement_email_avertissement.html.twig', [
                'nouvelEmail' => $nouvelEmail,
                'ancienEmail' => $user->getEmail(),
            ]),
        ));

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $user->getEmail(),
            action: 'compte.demande_changement_email',
            cible: 'utilisateur#'.$userId,
            contexte: ['nouvel_email' => $nouvelEmail],
            survenuLe: $maintenant,
        ));
    }
}
