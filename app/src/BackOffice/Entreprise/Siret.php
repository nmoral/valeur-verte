<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

/**
 * Numéro SIRET d'un établissement : 14 chiffres. La clé Luhn n'est
 * volontairement pas vérifiée — on s'aligne sur la tolérance de la saisie
 * d'inscription (#105), l'API Recherche d'Entreprises fait foi ensuite.
 */
final class Siret implements \Stringable
{
    private const FORMAT = '/^\d{14}$/';

    private function __construct(private readonly string $valeur)
    {
    }

    public static function depuis(string $valeur): self
    {
        $normalise = self::normaliser($valeur);

        if (1 !== preg_match(self::FORMAT, $normalise)) {
            throw SiretInvalide::pour($valeur);
        }

        return new self($normalise);
    }

    public static function estValide(string $valeur): bool
    {
        return 1 === preg_match(self::FORMAT, self::normaliser($valeur));
    }

    public function valeur(): string
    {
        return $this->valeur;
    }

    public function siren(): string
    {
        return substr($this->valeur, 0, 9);
    }

    public function __toString(): string
    {
        return $this->valeur;
    }

    private static function normaliser(string $valeur): string
    {
        return preg_replace('/\s+/', '', trim($valeur)) ?? '';
    }
}
