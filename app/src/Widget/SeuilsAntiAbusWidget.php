<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Seuils de l'anti-abus réactif d'une page publique (#268, épic #6).
 *
 * Le préventif (#43) borne chaque requête ; ici on borne la **forme du trafic** sur
 * une fenêtre glissante. Les valeurs par défaut visent une page vitrine de pro —
 * quelques estimations par quart d'heure — et laissent donc largement passer un
 * usage normal : une alerte qui crie tout le temps n'est plus lue.
 *
 * `facteurCritique` sépare deux régimes : au-delà du seuil on alerte et on durcit
 * le défi ; au-delà du seuil multiplié par ce facteur, la page est coupée d'office.
 * `silenceMinutes` évite de réémettre la même alerte à chaque requête du pic.
 */
final readonly class SeuilsAntiAbusWidget
{
    public function __construct(
        private int $fenetreMinutes,
        private int $estimations,
        private int $leads,
        private int $rejets,
        private int $facteurCritique,
        private int $silenceMinutes,
        private int $challengeRenforceMinutes,
    ) {
    }

    public static function parDefaut(): self
    {
        return new self(
            fenetreMinutes: 15,
            estimations: 120,
            leads: 30,
            rejets: 60,
            facteurCritique: 3,
            silenceMinutes: 60,
            challengeRenforceMinutes: 120,
        );
    }

    public function fenetreMinutes(): int
    {
        return $this->fenetreMinutes;
    }

    public function estimations(): int
    {
        return $this->estimations;
    }

    public function leads(): int
    {
        return $this->leads;
    }

    public function rejets(): int
    {
        return $this->rejets;
    }

    public function facteurCritique(): int
    {
        return $this->facteurCritique;
    }

    public function silenceMinutes(): int
    {
        return $this->silenceMinutes;
    }

    public function challengeRenforceMinutes(): int
    {
        return $this->challengeRenforceMinutes;
    }

    /**
     * Début de la fenêtre glissante observée à cet instant.
     */
    public function debutFenetre(\DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        return $maintenant->sub(new \DateInterval('PT'.$this->fenetreMinutes.'M'));
    }

    public function seuilDe(MotifPicWidget $motif): int
    {
        return match ($motif) {
            MotifPicWidget::Estimations => $this->estimations,
            MotifPicWidget::Leads => $this->leads,
            MotifPicWidget::Rejets => $this->rejets,
        };
    }
}
