<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des bouquets types du pro (#35) : triable et recherchable par
 * nom, paginée. La suppression reste un formulaire par ligne. Zone « app. ».
 */
#[AsLiveComponent]
final class ListeBouquets
{
    use ComporteListe;
    use DefaultActionTrait;

    public function mount(): void
    {
        $this->tri = 'nom';
        $this->direction = 'asc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'nom', 'libelle' => 'Nom', 'triable' => true],
            ['cle' => 'postes', 'libelle' => 'Postes couverts', 'triable' => false],
            ['cle' => 'actions', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'bouquets';
    }
}
