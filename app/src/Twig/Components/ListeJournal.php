<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive du journal d'audit (#50) : triable (quand, acteur, action),
 * recherchable, paginée, les plus récents d'abord. Append-only : aucune action de
 * groupe. Zone admin.
 */
#[AsLiveComponent]
final class ListeJournal
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
            ['cle' => 'date', 'libelle' => 'Quand', 'triable' => true],
            ['cle' => 'acteur', 'libelle' => 'Acteur', 'triable' => true],
            ['cle' => 'action', 'libelle' => 'Action', 'triable' => true],
            ['cle' => 'cible', 'libelle' => 'Cible', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'journal';
    }
}
