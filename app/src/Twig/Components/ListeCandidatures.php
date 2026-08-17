<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des candidatures fondateur (#61) : triable, recherchable
 * (email), paginée. Les décisions (valider/refuser) restent des formulaires par
 * ligne (gestion du plafond fondateur côté contrôleur). Zone admin.
 */
#[AsLiveComponent]
final class ListeCandidatures
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
            ['cle' => 'contact', 'libelle' => 'Contact', 'triable' => true],
            ['cle' => 'tenant', 'libelle' => 'Tenant', 'triable' => true],
            ['cle' => 'date', 'libelle' => 'Candidat depuis', 'triable' => true],
            ['cle' => 'actions', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'candidatures';
    }
}
