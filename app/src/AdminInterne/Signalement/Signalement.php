<?php

declare(strict_types=1);

namespace App\AdminInterne\Signalement;

/**
 * Retour « ce chiffre me semble faux » (#49) : un pro signale une estimation
 * douteuse avec son contexte complet (adresse, saisies, n, rayon…). Meilleure
 * source de calibration sur les premiers mois. La persistance est ajoutée à
 * l'étape repository — ici la capture et sa validation.
 */
final class Signalement
{
    /**
     * @param array<string, mixed> $contexte
     */
    private function __construct(
        private readonly string $adresse,
        private readonly array $contexte,
        private readonly ?string $message,
        private readonly \DateTimeImmutable $signaleLe,
    ) {
        if ('' === trim($this->adresse)) {
            throw new \InvalidArgumentException("L'adresse d'un signalement ne peut pas être vide.");
        }
    }

    /**
     * @param array<string, mixed> $contexte
     */
    public static function creer(string $adresse, array $contexte, ?string $message, \DateTimeImmutable $signaleLe): self
    {
        $message = null !== $message && '' !== trim($message) ? $message : null;

        return new self($adresse, $contexte, $message, $signaleLe);
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    /**
     * @return array<string, mixed>
     */
    public function contexte(): array
    {
        return $this->contexte;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function signaleLe(): \DateTimeImmutable
    {
        return $this->signaleLe;
    }
}
