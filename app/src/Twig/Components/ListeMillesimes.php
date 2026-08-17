<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des millésimes en revue (REG-2, #130) : triable, recherchable,
 * paginée. Les décisions (publier/abandonner) restent des formulaires par ligne.
 * Zone admin.
 */
#[AsLiveComponent]
final class ListeMillesimes
{
    use ComporteListe;
    use DefaultActionTrait;

    public function mount(): void
    {
        $this->tri = 'millesime';
        $this->direction = 'desc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'millesime', 'libelle' => 'Millésime', 'triable' => true],
            ['cle' => 'calculees', 'libelle' => 'Mailles calculées', 'triable' => true],
            ['cle' => 'bloquees', 'libelle' => 'Écarts > 25 %', 'triable' => true],
            ['cle' => 'alerte', 'libelle' => 'Écarts > 10 %', 'triable' => true],
            ['cle' => 'etat', 'libelle' => 'État', 'triable' => false],
            ['cle' => 'actions', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'millesimes';
    }
}
