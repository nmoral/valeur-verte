<?php

declare(strict_types=1);

namespace App\Compte;

use App\AdminInterne\Compte\CompteurRapportsConsommes;
use App\Notification\Email;
use App\Notification\Mailer;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Relance de fin d'essai (#108) : envoie une seule fois un email de rappel aux
 * comptes dont l'essai se termine dans les X prochains jours (X éditable en base).
 * Idempotent (marqueur sur le compte) : conçu pour être joué chaque jour par un
 * cron. Un essai déjà terminé n'est pas relancé a posteriori.
 */
final readonly class RelanceEssais
{
    public function __construct(
        private CompteRepository $comptes,
        private ReglagesFormuleRepository $reglages,
        private CompteurRapportsConsommes $compteur,
        private DelaiRelanceEssaiRepository $delais,
        private Mailer $mailer,
        private Connection $connexion,
        private ClockInterface $horloge,
        private Environment $twig,
        private JetonDesabonnement $jetons,
        private string $appDomaine,
    ) {
    }

    public function executer(): int
    {
        $maintenant = $this->horloge->now();
        $delai = $this->delais->courant()->jours();

        $envoyes = 0;
        foreach ($this->comptes->aRelancer() as $compte) {
            if ($compte->estDesabonneDesEmails()) {
                continue;
            }
            $etat = $compte->etatEssai(
                $this->reglages->pour($compte->formule()),
                $this->compteur->pour($compte->tenantId()),
                $maintenant,
            );
            if ($etat->termine() || $etat->joursRestants() > $delai) {
                continue;
            }

            $email = $this->emailDuTenant($compte->tenantId());
            if (null === $email) {
                continue;
            }

            $this->mailer->envoyer(new Email(
                $email,
                'Votre essai Valeur Verte se termine bientôt',
                $this->corps($compte, $etat),
            ));
            $compte->marquerRelanceEnvoyee($maintenant);
            $this->comptes->enregistrer($compte);
            ++$envoyes;
        }

        return $envoyes;
    }

    private function corps(Compte $compte, EtatEssai $etat): string
    {
        $tenantId = $compte->tenantId();

        return $this->twig->render('email/relance_essai.html.twig', [
            'joursRestants' => $etat->joursRestants(),
            'rapportsRestants' => $etat->rapportsRestants(),
            'lienEspace' => \sprintf('https://app.%s', $this->appDomaine),
            'lienDesabonnement' => \sprintf(
                'https://app.%s/desabonnement?tenant=%d&jeton=%s',
                $this->appDomaine,
                $tenantId,
                $this->jetons->pour($tenantId),
            ),
        ]);
    }

    private function emailDuTenant(int $tenantId): ?string
    {
        $email = $this->connexion->fetchOne(
            'SELECT email FROM utilisateur WHERE tenant_id = :tenant ORDER BY id ASC LIMIT 1',
            ['tenant' => $tenantId],
        );

        return \is_string($email) ? $email : null;
    }
}
