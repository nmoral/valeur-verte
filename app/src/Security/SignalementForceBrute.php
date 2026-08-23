<?php

declare(strict_types=1);

namespace App\Security;

use App\Notification\NotificationAdmin;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Signale à l'admin qu'une connexion vient d'être verrouillée pour force brute
 * (#307). Le plafond lui-même est tenu par `login_throttling` (firewall `main`) ;
 * ce listener n'ajoute que la **visibilité** : une attaque bloquée en silence reste
 * une attaque qu'on ne voit pas — et c'est le seul signal qui distingue un
 * utilisateur tête-en-l'air d'un bourrage d'identifiants.
 *
 * Même forme d'alerte que `LimiteurInscription` (D2), pour que les deux abus
 * arrivent au même endroit.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final readonly class SignalementForceBrute
{
    public function __construct(
        private NotificationAdmin $notificationAdmin,
    ) {
    }

    public function __invoke(LoginFailureEvent $evenement): void
    {
        if (!$evenement->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $this->notificationAdmin->usageAnormal(
            'Connexions trop nombreuses',
            \sprintf(
                'IP %s : plafond de tentatives atteint sur le compte %s. Connexion verrouillée le temps de la fenêtre.',
                $evenement->getRequest()->getClientIp() ?? 'inconnue',
                $evenement->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier() ?? 'inconnu',
            ),
        );
    }
}
