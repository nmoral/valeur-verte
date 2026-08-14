<?php

declare(strict_types=1);

namespace App\Security\VerificationEmail;

use App\Notification\Email;
use App\Notification\Mailer;
use App\Security\User;
use Twig\Environment;

/**
 * Envoi de l'email de vérification (#105, ONB-1). Email **transactionnel** portant
 * un lien signé (expéditeur no-reply@, pas d'adresse de réponse). Envoyé à
 * l'inscription et re-déclenchable depuis le bandeau de rappel. Sans effet tant
 * que le compte n'est pas persisté (pas d'identifiant à signer).
 */
final readonly class EnvoiVerificationEmail
{
    public function __construct(
        private Mailer $mailer,
        private Environment $twig,
        private JetonVerificationEmail $jetons,
        private string $appDomaine,
    ) {
    }

    public function envoyerPour(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        try {
            $this->mailer->envoyer(new Email(
                $user->getEmail(),
                'Confirmez votre adresse email',
                $this->corps($userId),
            ));
        } catch (\Throwable) {
            // Best-effort (ONB-1 « jamais bloquant ») : une panne du fournisseur
            // ne doit jamais casser l'inscription ni le renvoi. Le bandeau de
            // rappel reste affiché et permet de redemander l'email plus tard.
        }
    }

    private function corps(int $userId): string
    {
        return $this->twig->render('email/verification_email.html.twig', [
            'lienVerification' => \sprintf(
                'https://app.%s/verification-email?utilisateur=%d&jeton=%s',
                $this->appDomaine,
                $userId,
                $this->jetons->pour($userId),
            ),
        ]);
    }
}
