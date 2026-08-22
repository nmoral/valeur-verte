<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

/**
 * Couleur principale de la marque, en hexadécimal (#RRGGBB). Value object immuable.
 */
final readonly class Couleur
{
    /**
     * Les deux seules couleurs de texte du produit : le blanc, et l'encre du design
     * system (`--ink` de `back-office.css`). Leurs luminances sont constantes, donc
     * calculées une fois pour toutes plutôt qu'à chaque rendu de page.
     */
    private const string BLANC = '#FFFFFF';
    private const string ENCRE = '#111820';
    private const float LUMINANCE_BLANC = 1.0;
    private const float LUMINANCE_ENCRE = 0.008767;

    /**
     * Rapport de contraste minimal exigé par WCAG pour un **grand texte** (≥ 24 px,
     * ou ≥ 18,7 px en gras) — le calibre du chiffre héros.
     */
    private const float CONTRASTE_GRAND_TEXTE = 3.0;

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

    /**
     * Couleur de texte qui se lit **sur** cette couleur (#290).
     *
     * La page publique pose le nom du pro, et les libellés de ses boutons, sur sa
     * couleur de marque — dont on ne sait rien : elle peut être un bleu nuit comme
     * un jaune vif. Poser du blanc systématiquement, c'est rendre la page illisible
     * chez le premier pro qui choisit une couleur claire.
     *
     * On tranche donc au **contraste WCAG** : la luminance relative de la couleur
     * décide, entre le blanc et l'encre du produit, laquelle des deux s'y détache le
     * mieux. Le seuil n'est pas un réglage — il tombe du calcul.
     */
    public function texteLisible(): self
    {
        $luminance = $this->luminanceRelative();

        return self::depuisHex(
            self::contraste($luminance, self::LUMINANCE_BLANC) >= self::contraste($luminance, self::LUMINANCE_ENCRE)
                ? self::BLANC
                : self::ENCRE,
        );
    }

    /**
     * Cette couleur si elle s'écrit lisiblement sur fond clair, l'encre sinon (#290).
     *
     * Le pendant de {@see self::texteLisible()} : là on posait du texte **sur** la
     * marque, ici c'est la marque qui **est** le texte — le chiffre héros de la page,
     * écrit dans la couleur du pro sur une carte blanche. Une marque claire y devient
     * invisible, et c'est précisément le seul chiffre que le visiteur est venu voir.
     *
     * Le seuil est celui de WCAG pour un **grand texte** (3:1) : ce à quoi cette
     * couleur sert ici est un nombre de 42 px en gras, pas du corps de texte.
     */
    public function lisibleSurFondClair(): self
    {
        return self::contraste($this->luminanceRelative(), self::LUMINANCE_BLANC) >= self::CONTRASTE_GRAND_TEXTE
            ? $this
            : self::depuisHex(self::ENCRE);
    }

    /**
     * Luminance relative WCAG 2.1 : composantes sRGB linéarisées, puis pondérées par
     * la sensibilité de l'œil — d'où le poids écrasant du vert, qui rend un rouge pur
     * plus « clair » qu'il n'en a l'air.
     */
    private function luminanceRelative(): float
    {
        $canaux = array_map(
            static function (int $octet): float {
                $canal = $octet / 255;

                return $canal <= 0.04045 ? $canal / 12.92 : (($canal + 0.055) / 1.055) ** 2.4;
            },
            [
                (int) hexdec(substr($this->hex, 1, 2)),
                (int) hexdec(substr($this->hex, 3, 2)),
                (int) hexdec(substr($this->hex, 5, 2)),
            ],
        );

        return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
    }

    /**
     * Rapport de contraste WCAG entre deux luminances (de 1:1 à 21:1).
     */
    private static function contraste(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
