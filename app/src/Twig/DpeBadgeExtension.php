<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fournit l'étiquette DPE « flèche énergie » du PDF (#32) sous forme d'image SVG
 * inline. L'échelle officielle des couleurs (fond + encre contrastée) vit ici,
 * unique source pour le rendu PDF ; mpdf sait rasteriser un SVG en data-URI.
 */
final class DpeBadgeExtension extends AbstractExtension
{
    /**
     * Fond et encre par classe : encre blanche sur fonds foncés (A, B, F, G),
     * encre sombre sur fonds clairs (C, D, E) pour rester lisible.
     *
     * @var array<string, array{string, string}>
     */
    private const COULEURS = [
        'A' => ['#2E9B4F', '#FFFFFF'],
        'B' => ['#52B153', '#FFFFFF'],
        'C' => ['#8CC03A', '#16160F'],
        'D' => ['#E8CB1F', '#16160F'],
        'E' => ['#EDA53B', '#16160F'],
        'F' => ['#E0743A', '#FFFFFF'],
        'G' => ['#BE2A1D', '#FFFFFF'],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dpe_badge', $this->badge(...), ['is_safe' => ['html']]),
        ];
    }

    public function badge(string $lettre): string
    {
        [$fond, $encre] = self::COULEURS[$lettre] ?? ['#6B7C80', '#FFFFFF'];

        $svg = \sprintf(
            '<svg width="54" height="26" viewBox="0 0 54 26" xmlns="http://www.w3.org/2000/svg">'
            .'<path d="M4 1 H40 L52 13 L40 25 H4 Q1 25 1 22 V4 Q1 1 4 1 Z" fill="%s"/>'
            .'<text x="21" y="18" font-family="sans-serif" font-size="15" font-weight="bold" '
            .'text-anchor="middle" fill="%s">%s</text>'
            .'</svg>',
            $fond,
            $encre,
            $lettre,
        );

        return \sprintf(
            '<img src="data:image/svg+xml;base64,%s" width="54" height="26" style="vertical-align:middle" alt="DPE %s">',
            base64_encode($svg),
            $lettre,
        );
    }
}
