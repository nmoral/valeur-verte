<?php

declare(strict_types=1);

namespace App\Simulateur;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Restitution\Restitution;
use App\Notification\Email;
use App\Notification\Mailer;
use Twig\Environment;

/**
 * Envoi du rapport du simulateur public (#385) par email. Le rapport n'est
 * délivré que par ce canal : le visiteur donne son adresse pour le recevoir.
 * L'email sert au seul envoi — sa conservation en base est décidée à part, sur
 * consentement explicite ({@see SimulateurLead}).
 */
final readonly class EnvoiRapportSimulateur
{
    public function __construct(
        private Mailer $mailer,
        private Environment $twig,
    ) {
    }

    public function envoyer(string $email, SaisieAudit $saisie, Restitution $restitution): void
    {
        $corps = $this->twig->render('simulateur/email/rapport.html.twig', [
            'saisie' => $saisie,
            'restitution' => $restitution,
        ]);

        $this->mailer->envoyer(new Email(
            $email,
            'Votre rapport de valeur verte',
            $corps,
        ));
    }
}
