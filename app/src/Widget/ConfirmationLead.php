<?php

declare(strict_types=1);

namespace App\Widget;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Notification\Email;
use App\Notification\Mailer;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Confirmation d'un lead widget (#42) : au clic du lien d'opt-in, vérifie le jeton
 * (usage unique + expirant), matérialise le lead en Rapport(Source::Widget) — qui
 * apparaît alors dans « Rapports & leads » (#37) — et notifie le pro par email.
 * Le chiffre vient de l'instantané figé à la saisie : jamais recalculé.
 *
 * Retourne le Rapport créé, ou null si le jeton est inconnu, expiré ou déjà
 * consommé (le contrôleur en fait un 404, sans distinguer les cas).
 */
final readonly class ConfirmationLead
{
    public function __construct(
        private DemandeLeadRepository $demandes,
        private GenerateurJeton $generateur,
        private ClockInterface $horloge,
        private RapportRepository $rapports,
        private UrlGeneratorInterface $urls,
        private UserRepository $utilisateurs,
        private Mailer $mailer,
        private Environment $twig,
    ) {
    }

    public function confirmer(string $jetonBrut): ?Rapport
    {
        $demande = $this->demandes->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));
        $maintenant = $this->horloge->now();
        if (null === $demande || !$demande->estUtilisable($maintenant)) {
            return null;
        }

        $demande->marquerUtilise($maintenant);

        $instantane = InstantaneRapport::fromArray($demande->instantane());
        $reference = $instantane->reference()->valeur();
        $rapport = Rapport::creer(
            $reference,
            $instantane->genereLe(),
            Source::Widget,
            $instantane,
            $this->urls->generate('back_office_rapport_pdf', ['reference' => $reference]),
            Contact::creer($demande->contactNom(), $demande->contactEmail(), null),
        );
        $rapport->setTenantId($demande->tenantIdProprietaire());
        // enregistrer() flush l'unité de travail : la consommation du jeton (sur
        // la demande, gérée par le même EM) est persistée dans la foulée.
        $this->rapports->enregistrer($rapport);

        $this->notifierPro($demande->tenantIdProprietaire(), $rapport, $demande->contactEmail());

        return $rapport;
    }

    private function notifierPro(int $tenantId, Rapport $rapport, string $emailVisiteur): void
    {
        $pro = $this->utilisateurs->findOneBy(['tenantId' => $tenantId]);
        if (null === $pro) {
            return;
        }

        $this->mailer->envoyer(new Email(
            $pro->getEmail(),
            'Nouveau lead depuis votre page publique',
            $this->twig->render('email/widget_lead_notif_pro.html.twig', [
                'adresse' => $rapport->adresse(),
                'dpe_avant' => $rapport->dpeAvant()->value,
                'dpe_apres' => $rapport->dpeApres()->value,
                'contact_nom' => $rapport->contact()?->nom(),
                'contact_email' => $emailVisiteur,
            ]),
            // Le pro peut répondre directement au particulier.
            $emailVisiteur,
        ));
    }
}
