<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\AdminInterne\Coefficient\MailleSupervisee;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive de supervision des coefficients (#45) : triable (dont les
 * signalées en tête par défaut), recherchable (code INSEE), paginée. Lecture
 * seule sur le schéma data — aucune action de groupe. Zone admin.
 */
#[AsLiveComponent]
final class ListeCoefficients
{
    use ComporteListe;
    use DefaultActionTrait;

    public function mount(): void
    {
        $this->tri = 'signalement';
        $this->direction = 'desc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'code', 'libelle' => 'Code INSEE', 'triable' => true],
            ['cle' => 'millesime', 'libelle' => 'Millésime', 'triable' => true],
            ['cle' => 'n', 'libelle' => 'n', 'triable' => true],
            ['cle' => 'rayon', 'libelle' => 'Rayon', 'triable' => false],
            ['cle' => 'erreur', 'libelle' => 'Erreur médiane', 'triable' => true],
            ['cle' => 'gain', 'libelle' => 'Gain E→C (log)', 'triable' => true],
            ['cle' => 'signalement', 'libelle' => 'Signalement', 'triable' => true],
        ];
    }

    public function classeLigne(mixed $element): string
    {
        return $element instanceof MailleSupervisee && $element->estAberrante() ? 'alerte' : '';
    }

    protected function parPage(): int
    {
        return 50;
    }

    protected function cleSource(): string
    {
        return 'coefficients';
    }
}
