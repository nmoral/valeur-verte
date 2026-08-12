<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

/**
 * Couleur principale de la marque, en hexadécimal (#RRGGBB). Value object immuable.
 */
final readonly class Couleur
{
    private function __construct(private string $hex)
    {
    }

    public static function depuisHex(string $hex): self
    {
        if (1 !== preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
            throw new \InvalidArgumentException(\sprintf('Code couleur hexadécimal invalide : « %s ».', $hex));
        }

        return new self(strtoupper($hex));
    }

    public function hex(): string
    {
        return $this->hex;
    }
}
