<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

/**
 * Contact d'un rapport : sa présence fait du rapport un lead. Value object immuable.
 */
final readonly class Contact
{
    private function __construct(
        private string $nom,
        private ?string $email,
        private ?string $telephone,
    ) {
    }

    public static function creer(string $nom, ?string $email, ?string $telephone): self
    {
        $nom = trim($nom);
        if ('' === $nom) {
            throw new \InvalidArgumentException('Le nom du contact ne peut pas être vide.');
        }

        $email = self::normaliser($email);
        $telephone = self::normaliser($telephone);
        if (null === $email && null === $telephone) {
            throw new \InvalidArgumentException('Un contact doit fournir au moins un email ou un téléphone.');
        }

        return new self($nom, $email, $telephone);
    }

    public function nom(): string
    {
        return $this->nom;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function telephone(): ?string
    {
        return $this->telephone;
    }

    private static function normaliser(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
