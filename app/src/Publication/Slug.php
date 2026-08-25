<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Slug d'URL déterministe pour un nom de lieu (commune, EPCI) — le socle des
 * pages SEO programmatiques de la Vague 2 (#338, #359).
 *
 * Les slugs départementaux ({@see RepertoireDepartements}) sont figés à la main
 * car ils sont peu nombreux ; les ~34 746 communes imposent au contraire un
 * algorithme. Cet algorithme devient dès lors un **contrat d'URL permanent** :
 * une URL indexée ne doit jamais changer. On le garde donc **autonome** — table
 * de translittération sous notre contrôle, gelée par {@see \App\Tests\Publication\SlugTest}
 * — plutôt que délégué à un slugifieur tiers dont le comportement pourrait évoluer.
 *
 * Règles : minuscules, accents français translittérés en ASCII, toute suite de
 * caractères non `[a-z0-9]` (espaces, apostrophes, tirets multiples) réduite à un
 * seul tiret, puis tirets de bord rognés.
 */
final class Slug
{
    /**
     * Translittération des lettres accentuées françaises vers l'ASCII. Appliquée
     * après passage en minuscules, donc seules les formes minuscules sont listées.
     *
     * @var array<string, string>
     */
    private const TRANSLITTERATION = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ý' => 'y',
        'ñ' => 'n',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    private function __construct(private readonly string $valeur)
    {
    }

    public static function depuis(string $nom): self
    {
        $minuscule = mb_strtolower($nom, 'UTF-8');
        $ascii = strtr($minuscule, self::TRANSLITTERATION);
        $tirets = (string) preg_replace('/[^a-z0-9]+/', '-', $ascii);

        return new self(trim($tirets, '-'));
    }

    public function valeur(): string
    {
        return $this->valeur;
    }
}
