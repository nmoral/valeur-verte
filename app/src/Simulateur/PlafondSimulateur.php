<?php

declare(strict_types=1);

namespace App\Simulateur;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Anti-abus du simulateur public (#385) : l'endpoint est ouvert et l'envoi du
 * rapport part un email vers l'adresse saisie — sans plafond, c'est un vecteur
 * d'email-bombing. Fenêtre glissante par IP, comme l'inscription (#44) et le
 * widget (#43). L'autocomplétion est plafonnée à part (plus large) pour ménager
 * le quota de la BAN.
 */
final readonly class PlafondSimulateur
{
    public function __construct(
        private RateLimiterFactoryInterface $simulateurIpLimiter,
        private RateLimiterFactoryInterface $simulateurAdresseIpLimiter,
    ) {
    }

    public function verifierEmission(?string $ip): void
    {
        $this->verifier($this->simulateurIpLimiter, $ip);
    }

    public function verifierAdresse(?string $ip): void
    {
        $this->verifier($this->simulateurAdresseIpLimiter, $ip);
    }

    private function verifier(RateLimiterFactoryInterface $factory, ?string $ip): void
    {
        $limite = $factory->create($ip ?? 'inconnu')->consume(1);

        if (!$limite->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limite->getRetryAfter()->getTimestamp() - time()),
                'Trop de demandes depuis cette adresse. Merci de réessayer plus tard.',
            );
        }
    }
}
