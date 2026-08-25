<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des snapshots de présence externe (#346) : triable (date, clics,
 * impressions, position), paginée, les plus récents d'abord. Archive en lecture
 * seule : aucune action de groupe. Zone admin.
 */
#[AsLiveComponent]
final class ListeRapportsPresence
{
    use ComporteListe;
    use DefaultActionTrait;

    public function mount(): void
    {
        $this->tri = 'date';
        $this->direction = 'desc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'date', 'libelle' => 'Collecté le', 'triable' => true],
            ['cle' => 'clics', 'libelle' => 'Clics', 'triable' => true],
            ['cle' => 'impressions', 'libelle' => 'Impressions', 'triable' => true],
            ['cle' => 'position', 'libelle' => 'Position moy.', 'triable' => true],
            ['cle' => 'urls', 'libelle' => 'URL', 'triable' => false],
            ['cle' => 'detail', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'rapports_presence';
    }
}
