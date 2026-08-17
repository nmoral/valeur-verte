<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des rapports de batch (#131) : triable (date, phase,
 * département, millésime), recherchable, paginée, les plus récents d'abord.
 * Archive en lecture seule : aucune action de groupe. Zone admin.
 */
#[AsLiveComponent]
final class ListeRapportsBatch
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
            ['cle' => 'date', 'libelle' => 'Exécuté le', 'triable' => true],
            ['cle' => 'phase', 'libelle' => 'Phase', 'triable' => true],
            ['cle' => 'departement', 'libelle' => 'Dépt', 'triable' => true],
            ['cle' => 'millesime', 'libelle' => 'Millésime', 'triable' => true],
            ['cle' => 'resume', 'libelle' => 'Résumé', 'triable' => false],
            ['cle' => 'detail', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'rapports_batch';
    }
}
