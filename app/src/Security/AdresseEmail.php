<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Adresse email d'un compte (#331), sous ses deux formes.
 *
 * - {@see valeur()} — la forme **de connexion** : l'adresse telle qu'elle est
 *   stockée, envoyée et affichée, toujours en minuscules. Les domaines sont
 *   insensibles à la casse par le DNS et aucun fournisseur grand public ne
 *   distingue la casse de la partie locale : garder la forme saisie n'apporterait
 *   rien et obligerait à traîner deux formes partout.
 * - {@see canonique()} — la forme **d'identité**, celle qui porte l'unicité.
 *   Elle réduit les variantes qui aboutissent dans la même boîte : l'alias
 *   `+tag`, que tous les fournisseurs grand public traitent comme une
 *   sous-adresse. Sans elle, `pro+1@`, `pro+2@`… valent autant de comptes — donc
 *   autant d'essais gratuits.
 *
 * Les deux formes coexistent : le pro relève son courrier sur son alias, mais il
 * n'a qu'une identité.
 *
 * L'adresse est **validée à la construction** (`FILTER_VALIDATE_EMAIL`, comme la
 * saisie d'inscription le faisait à la main) : la partie locale et le domaine
 * existent donc toujours, et le découpage n'a pas de cas dégénéré à traiter.
 */
final class AdresseEmail implements \Stringable
{
    private function __construct(
        private readonly string $locale,
        private readonly string $domaine,
    ) {
    }

    /**
     * @throws AdresseEmailInvalide
     */
    public static function depuis(string $saisie): self
    {
        $normalisee = self::normaliser($saisie);

        if (false === filter_var($normalisee, \FILTER_VALIDATE_EMAIL)) {
            throw AdresseEmailInvalide::pour($saisie);
        }

        // Découpe sur le **dernier** arobase : seul celui-là sépare la partie
        // locale du domaine (une partie locale entre guillemets peut en contenir).
        $separateur = strrpos($normalisee, '@');
        \assert(false !== $separateur); // garanti par FILTER_VALIDATE_EMAIL

        return new self(substr($normalisee, 0, $separateur), substr($normalisee, $separateur + 1));
    }

    public static function estValide(string $saisie): bool
    {
        return false !== filter_var(self::normaliser($saisie), \FILTER_VALIDATE_EMAIL);
    }

    public function valeur(): string
    {
        return $this->locale.'@'.$this->domaine;
    }

    public function canonique(): string
    {
        return self::sansAlias($this->locale).'@'.$this->domaine;
    }

    public function __toString(): string
    {
        return $this->valeur();
    }

    private static function normaliser(string $saisie): string
    {
        return mb_strtolower(trim($saisie));
    }

    /**
     * Retire la sous-adresse « +tag ». Une partie locale qui n'est **que** l'alias
     * (« +tag@… », adresse valide) est laissée telle quelle : la vider ferait
     * collisionner toutes les adresses du domaine sur une même identité.
     */
    private static function sansAlias(string $locale): string
    {
        $plus = strpos($locale, '+');

        return false === $plus || 0 === $plus ? $locale : substr($locale, 0, $plus);
    }
}
