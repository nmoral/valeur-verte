<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * La choroplèthe communale d'un département (#392) : contours projetés + gain vert
 * E→C par commune, dans la structure ``{w, h, dom, feats}`` que ``carte-departement.js``
 * consomme telle quelle (même patron que la carte nationale, {@see Carte}).
 *
 * Le domaine de couleur ``dom`` est l'étendue [min, max] des gains **mesurés** du
 * département : l'échelle s'ajuste au territoire au lieu d'une borne nationale figée.
 */
final readonly class CarteDepartement
{
    private ProjectionDepartement $projection;

    /**
     * @param list<CommuneTracee> $communes
     */
    public function __construct(
        private array $communes,
        ?ProjectionDepartement $projection = null,
    ) {
        $this->projection = $projection ?? new ProjectionDepartement();
    }

    /**
     * @return array{w: int, h: int, dom: array{0: int, 1: int}, feats: list<array{insee: string, nom: string, gain: ?int, path: string}>}
     */
    public function donnees(): array
    {
        $projete = $this->projection->projeter(array_map(
            static fn (CommuneTracee $commune): array => [
                'cle' => $commune->codeInsee,
                'anneaux' => $commune->anneaux,
            ],
            $this->communes,
        ));

        $feats = [];
        $gains = [];
        foreach ($this->communes as $commune) {
            $gain = $commune->maille?->gain('E', 'C');
            if (null !== $gain) {
                $gains[] = $gain;
            }
            $feats[] = [
                'insee' => $commune->codeInsee,
                'nom' => $commune->nom,
                'gain' => $gain,
                'path' => $projete['chemins'][$commune->codeInsee] ?? '',
            ];
        }

        return [
            'w' => $projete['w'],
            'h' => $projete['h'],
            'dom' => [] === $gains ? [0, 0] : [min($gains), max($gains)],
            'feats' => $feats,
        ];
    }
}
