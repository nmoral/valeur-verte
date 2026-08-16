<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Contours départementaux projetés en chemins SVG (boîte 760×720), lus depuis un
 * asset figé.
 *
 * La géométrie ne change pas d'un millésime à l'autre : la recalculer en base
 * (``ST_Union`` de ``data.commune_contour``) à chaque affichage coûterait cher
 * pour un résultat identique. L'asset est régénéré par
 * ``docs/carte-validation/generer.py`` quand les contours bougent.
 */
final readonly class ContoursDepartements
{
    public function __construct(private string $fichier)
    {
    }

    /**
     * @return array<int|string, string> code de département → chemin SVG
     */
    public function chemins(): array
    {
        $contenu = file_get_contents($this->fichier);
        if (false === $contenu) {
            throw new \RuntimeException(\sprintf('Contours départementaux illisibles : %s.', $this->fichier));
        }

        $chemins = json_decode($contenu, true);
        if (!\is_array($chemins)) {
            throw new \RuntimeException(\sprintf('Contours départementaux mal formés : %s.', $this->fichier));
        }

        return array_filter($chemins, static fn (mixed $chemin) => \is_string($chemin));
    }
}
