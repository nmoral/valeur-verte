<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Adresse email d'un compte (#331). Porte la forme **de connexion** : l'adresse
 * telle qu'elle est stockée et affichée, toujours en minuscules — les domaines
 * sont insensibles à la casse par le DNS, et aucun fournisseur grand public ne
 * distingue la casse de la partie locale. Garder la forme saisie n'apporterait
 * rien et obligerait à traîner deux formes partout.
 */
final class AdresseEmail implements \Stringable
{
    private function __construct(private readonly string $valeur)
    {
    }

    public static function depuis(string $saisie): self
    {
        return new self(mb_strtolower(trim($saisie)));
    }

    public function valeur(): string
    {
        return $this->valeur;
    }

    public function __toString(): string
    {
        return $this->valeur;
    }
}
