<?php

declare(strict_types=1);

namespace App\Widget;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Pdf\GenerateurReferenceRapport;
use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\InstantaneRapport;
use App\Notification\Email;
use App\Notification\Mailer;
use App\Security\MotDePasseOublie\GenerateurJeton;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Capture d'un lead widget (#42) : à la soumission du mur de contact, fige
 * l'instantané de l'estimation (ce que le visiteur a vu), enregistre une demande
 * **à confirmer** et envoie l'email d'opt-in. Aucun Rapport, aucune notif pro à
 * ce stade — c'est la confirmation qui matérialise le lead (cf. ConfirmationLead).
 */
final readonly class CaptureLead
{
    public function __construct(
        private EstimateurValeurVerte $estimateur,
        private GenerateurReferenceRapport $references,
        private GenerateurJeton $generateur,
        private DemandeLeadRepository $demandes,
        private Mailer $mailer,
        private Environment $twig,
        private ClockInterface $horloge,
        private string $appDomaine,
        private int $dureeValiditeMinutes,
    ) {
    }

    public function capturer(int $tenantId, string $token, Branding $branding, Contact $contact, string $codeInsee, SaisieAudit $saisie): void
    {
        // Recompute l'estimation pour la figer maintenant (peut lever
        // EstimationIndisponible : sans chiffre, il n'y a pas de lead à capturer).
        $resultat = $this->estimateur->estimer($codeInsee, $saisie);
        $maintenant = $this->horloge->now();

        $instantane = InstantaneRapport::creer(
            $saisie,
            $resultat->restitution(),
            $resultat->contexte(),
            $branding,
            $this->references->generer(),
            $maintenant,
        );

        $email = $contact->email();
        \assert(null !== $email);

        $jetonBrut = $this->generateur->jetonBrut();
        $this->demandes->enregistrer(DemandeLead::creer(
            $tenantId,
            $this->generateur->empreinte($jetonBrut),
            $maintenant->add(new \DateInterval('PT'.$this->dureeValiditeMinutes.'M')),
            $contact->nom(),
            $email,
            $instantane->toArray(),
            $maintenant,
        ));

        $this->mailer->envoyer(new Email(
            $email,
            'Confirmez votre demande d\'estimation',
            $this->twig->render('email/widget_lead_opt_in.html.twig', [
                'nom' => $contact->nom(),
                'lien' => \sprintf('https://widget.%s/%s/contact/confirmer/%s', $this->appDomaine, $token, $jetonBrut),
            ]),
        ));
    }
}
