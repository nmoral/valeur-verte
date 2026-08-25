<?php

declare(strict_types=1);

namespace App\Compte;

/**
 * État d'essai d'un compte (#108) : combine le **temps** et le **quota** de
 * rapports de sa formule. L'essai court tant que la date de fin n'est pas
 * atteinte ET que le quota reste disponible ; il se termine dès qu'une des deux
 * limites tombe. La restitution artisan et l'email de relance lisent cet état.
 */
final readonly class EtatEssai
{
    private function __construct(
        private int $rapportsConsommes,
        private int $quotaRapports,
        private int $joursRestants,
        private bool $tempsEcoule,
        private bool $neutralise,
    ) {
    }

    public static function calculer(
        int $rapportsConsommes,
        ParametresFormule $parametres,
        \DateTimeImmutable $creeLe,
        \DateTimeImmutable $maintenant,
        ?\DateTimeImmutable $essaiNeutraliseLe = null,
    ): self {
        $fin = $creeLe->modify(\sprintf('+%d days', $parametres->dureeEssaiJours()));
        $secondesRestantes = $fin->getTimestamp() - $maintenant->getTimestamp();
        $joursRestants = max(0, (int) ceil($secondesRestantes / 86400));

        return new self(
            max(0, $rapportsConsommes),
            $parametres->quotaRapports(),
            $joursRestants,
            $maintenant >= $fin,
            null !== $essaiNeutraliseLe,
        );
    }

    public function enEssai(): bool
    {
        return !$this->neutralise && $this->rapportsRestants() > 0 && !$this->tempsEcoule;
    }

    public function termine(): bool
    {
        return !$this->enEssai();
    }

    public function rapportsRestants(): int
    {
        return max(0, $this->quotaRapports - $this->rapportsConsommes);
    }

    public function joursRestants(): int
    {
        return $this->joursRestants;
    }

    public function rapportsConsommes(): int
    {
        return $this->rapportsConsommes;
    }

    /**
     * Le quota est-il consommé à hauteur d'au moins ce pourcentage ? Base de la
     * notification à 80 % (#108), sans coder le seuil de notification ici.
     */
    public function quotaConsommeAuMoins(int $pourcentage): bool
    {
        return $this->rapportsConsommes * 100 >= $this->quotaRapports * $pourcentage;
    }
}
