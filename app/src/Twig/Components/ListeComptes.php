<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des comptes pro (#47) : triable (créé le, compte, formule),
 * recherchable (identité), paginée. Zone admin.
 */
#[AsLiveComponent]
final class ListeComptes
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
            ['cle' => 'compte', 'libelle' => 'Compte', 'triable' => true],
            ['cle' => 'date', 'libelle' => 'Créé le', 'triable' => true],
            ['cle' => 'formule', 'libelle' => 'Formule', 'triable' => true],
            ['cle' => 'rapports', 'libelle' => 'Rapports consommés', 'triable' => false],
            ['cle' => 'essai', 'libelle' => 'État d\'essai', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'comptes';
    }
}
