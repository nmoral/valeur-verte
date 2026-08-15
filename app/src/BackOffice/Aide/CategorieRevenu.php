<?php

declare(strict_types=1);

namespace App\BackOffice\Aide;

/**
 * Situation du ménage au regard des plafonds de ressources MaPrimeRénov' (#26).
 *
 * Les plafonds officiels dépendent du revenu fiscal de référence, du nombre de
 * personnes du foyer et de la région (Île-de-France ou non) : le produit ne collecte
 * rien de tout cela. Le pro **déclare** la catégorie, aidé par le tableau des seuils
 * affiché à côté du champ. C'est une déclaration, jamais une éligibilité instruite.
 *
 * {@see NonPrecisee} est un cas à part entière plutôt qu'un `null` : le `match` est
 * ainsi forcé de le traiter, là où un nullable se ferait oublier et produirait un
 * reste à charge faux par omission.
 */
enum CategorieRevenu
{
    case TresModeste;
    case Modeste;
    case Intermediaire;
    case Superieur;
    case NonPrecisee;

    /**
     * Les tranches réelles du barème, dans l'ordre croissant de revenus.
     * « Non précisée » n'en fait pas partie : elle donne une fourchette, pas un taux.
     *
     * @return non-empty-list<self>
     */
    public static function tranches(): array
    {
        return [self::TresModeste, self::Modeste, self::Intermediaire, self::Superieur];
    }

    public static function parNom(string $nom): self
    {
        foreach (self::cases() as $categorie) {
            if ($categorie->name === $nom) {
                return $categorie;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Situation de revenus inconnue : %s.', $nom));
    }

    public function libelle(): string
    {
        return match ($this) {
            self::TresModeste => 'Revenus très modestes',
            self::Modeste => 'Revenus modestes',
            self::Intermediaire => 'Revenus intermédiaires',
            self::Superieur => 'Revenus supérieurs',
            self::NonPrecisee => 'Non précisée',
        };
    }
}
