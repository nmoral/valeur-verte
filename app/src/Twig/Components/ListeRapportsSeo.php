<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des rapports d'audit SEO (#345) : triable (date, score, URL,
 * erreurs), paginée, les plus récents d'abord. Archive en lecture seule : aucune
 * action de groupe. Zone admin.
 */
#[AsLiveComponent]
final class ListeRapportsSeo
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
            ['cle' => 'score', 'libelle' => 'Score', 'triable' => true],
            ['cle' => 'urls', 'libelle' => 'URL', 'triable' => true],
            ['cle' => 'ok', 'libelle' => 'OK', 'triable' => false],
            ['cle' => 'alerte', 'libelle' => 'Alertes', 'triable' => false],
            ['cle' => 'erreurs', 'libelle' => 'Erreurs', 'triable' => true],
            ['cle' => 'detail', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function cleSource(): string
    {
        return 'rapports_seo';
    }
}
