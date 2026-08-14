<?php

declare(strict_types=1);

namespace App\Erreur;

/**
 * Zone applicative déduite du préfixe d'hôte (sous-domaine). Sert aux pages
 * d'erreur à proposer un retour vers l'accueil de la zone où l'erreur s'est
 * produite (#197) : l'admin retourne à l'administration, le pro à son espace,
 * le public (et le widget, sans accueil propre) à l'accueil.
 */
enum Zone
{
    case Admin;
    case EspacePro;
    case Public;

    public static function depuisHote(string $hote): self
    {
        $hote = strtolower($hote);

        if (str_starts_with($hote, 'admin.')) {
            return self::Admin;
        }

        if (str_starts_with($hote, 'app.')) {
            return self::EspacePro;
        }

        return self::Public;
    }

    public function route(): string
    {
        return match ($this) {
            self::Admin => 'admin',
            self::EspacePro => 'espace_pro',
            self::Public => 'home',
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Admin => 'Retour à l\'administration',
            self::EspacePro => 'Retour à votre espace',
            self::Public => 'Retour à l\'accueil',
        };
    }
}
