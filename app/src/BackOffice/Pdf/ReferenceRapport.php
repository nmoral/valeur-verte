<?php

declare(strict_types=1);

namespace App\BackOffice\Pdf;

/**
 * Identifiant de rapport unique (§9.6), imprimé en pied de PDF pour relier un
 * document présenté en rendez-vous au rapport qui l'a produit. Value object
 * immuable au format normalisé « VV-AAAAMMJJ-XXXXXX ».
 */
final readonly class ReferenceRapport
{
    private const FORMAT = '/^VV-\d{8}-[0-9A-Z]{6}$/';

    private function __construct(private string $valeur)
    {
    }

    public static function depuis(string $valeur): self
    {
        if (1 !== preg_match(self::FORMAT, $valeur)) {
            throw new \InvalidArgumentException(\sprintf('Référence de rapport invalide : « %s ».', $valeur));
        }

        return new self($valeur);
    }

    public function valeur(): string
    {
        return $this->valeur;
    }
}
