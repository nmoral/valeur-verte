<?php

declare(strict_types=1);

namespace App\Security;

use App\Notification\NotificationAdmin;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Protège l'inscription ouverte des abus (D2, étendu aux inscriptions) : fenêtre
 * glissante par IP. Sur l'inscription, le dépassement bloque (429) tout en
 * notifiant l'admin.
 */
final class LimiteurInscription
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $inscriptionIpLimiter,
        private readonly NotificationAdmin $notificationAdmin,
    ) {
    }

    public function verifier(string $ip): void
    {
        $limite = $this->inscriptionIpLimiter->create($ip)->consume(1);

        if (!$limite->isAccepted()) {
            $this->notificationAdmin->usageAnormal(
                'Inscriptions trop nombreuses',
                \sprintf('IP %s : plafond de %d inscriptions par fenêtre atteint.', $ip, $limite->getLimit()),
            );

            throw new TooManyRequestsHttpException(max(1, $limite->getRetryAfter()->getTimestamp() - time()), 'Trop de tentatives d\'inscription. Merci de réessayer plus tard.');
        }
    }
}
