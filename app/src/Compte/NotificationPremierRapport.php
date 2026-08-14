<?php

declare(strict_types=1);

namespace App\Compte;

use App\Notification\Email;
use App\Notification\Mailer;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Relance conversationnelle après le premier rapport (#109, ONB-7). Déclenchée
 * **sur événement** (la génération d'un rapport, cf. l'archivage), pas sur
 * calendrier : compense la perte de l'appel de découverte par un email personnel
 * auquel l'artisan peut répondre dans une vraie boîte (Reply-To).
 *
 * Une seule fois par compte (marqueur idempotent), jamais si le compte s'est
 * désabonné. L'envoi est **best-effort** : une panne du fournisseur ne casse
 * jamais la génération du rapport, et le compte n'est marqué qu'en cas de succès
 * — un échec sera retenté au rapport suivant.
 */
final readonly class NotificationPremierRapport
{
    public function __construct(
        private CompteRepository $comptes,
        private Connection $connexion,
        private Mailer $mailer,
        private Environment $twig,
        private ClockInterface $horloge,
        private JetonDesabonnement $jetons,
        private string $appDomaine,
        private string $contactReponse,
    ) {
    }

    public function surTenant(?int $tenantId): void
    {
        if (null === $tenantId) {
            return;
        }

        $compte = $this->comptes->pourTenant($tenantId);
        if (null === $compte || $compte->emailPremierRapportEnvoye() || $compte->estDesabonneDesEmails()) {
            return;
        }

        $destinataire = $this->emailDuTenant($tenantId);
        if (null === $destinataire) {
            return;
        }

        try {
            $this->mailer->envoyer(new Email(
                $destinataire,
                'Votre premier rapport Valeur Verte',
                $this->corps($tenantId),
                $this->contactReponse,
            ));
            $compte->marquerEmailPremierRapportEnvoye($this->horloge->now());
            $this->comptes->enregistrer($compte);
        } catch (\Throwable) {
            // Best-effort : la relance ne doit jamais casser la génération du
            // rapport. Le compte reste non marqué et sera retenté au suivant.
        }
    }

    private function corps(int $tenantId): string
    {
        return $this->twig->render('email/premier_rapport.html.twig', [
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
