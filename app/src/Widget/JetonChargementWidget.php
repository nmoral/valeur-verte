<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\Clock\ClockInterface;

/**
 * Jeton de chargement des formulaires publics du widget (#43, contrôles 3 et 4).
 *
 * Émis au rendu d'un formulaire, signé par HMAC-SHA256 avec le secret applicatif
 * (comme {@see \App\Compte\JetonDesabonnement}), il porte l'instant d'émission et
 * reste **stateless** — aucun stockage serveur. Format : `"<timestamp>.<signature>"`.
 *
 * Il n'est recevable à la soumission que dans une fenêtre d'âge :
 *  - **âge min** (timing) : une soumission plus rapide qu'un humain trahit un bot ;
 *  - **âge max** (anti-rejeu) : passé le délai le jeton expire, ce qui borne la
 *    fenêtre de rejeu d'un même jeton sans avoir à mémoriser les jetons consommés.
 *
 * Toute signature altérée ou tout jeton malformé est rejeté (comparaison en temps
 * constant, {@see hash_equals()}).
 */
final readonly class JetonChargementWidget
{
    public const int AGE_MIN_SECONDES = 2;
    public const int AGE_MAX_SECONDES = 3600;

    public function __construct(
        private string $secret,
        private ClockInterface $clock,
    ) {
    }

    public function emettre(): string
    {
        $ts = $this->clock->now()->getTimestamp();

        return $ts.'.'.$this->signature($ts);
    }

    public function estRecevable(string $jeton): bool
    {
        $parts = explode('.', $jeton);
        if (2 !== \count($parts) || !ctype_digit($parts[0])) {
            return false;
        }

        [$horodatage, $signature] = $parts;
        if (!hash_equals($this->signature((int) $horodatage), $signature)) {
            return false;
        }

        $age = $this->clock->now()->getTimestamp() - (int) $horodatage;

        return $age >= self::AGE_MIN_SECONDES && $age <= self::AGE_MAX_SECONDES;
    }

    private function signature(int $horodatage): string
    {
        return hash_hmac('sha256', 'chargement-widget:'.$horodatage, $this->secret);
    }
}
