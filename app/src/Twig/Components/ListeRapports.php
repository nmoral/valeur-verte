<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\BackOffice\Rapport\Rapport;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des rapports & leads du pro (§9.2) : triable, recherchable
 * (référence, adresse), paginée, avec suppression en masse (cloisonnée tenant).
 * Zone « app. » — sécurité portée par le firewall host (ROLE_PRO).
 */
#[AsLiveComponent]
final class ListeRapports
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
            ['cle' => 'reference', 'libelle' => 'Réf.', 'triable' => true],
            ['cle' => 'adresse', 'libelle' => 'Adresse', 'triable' => true],
            ['cle' => 'transition', 'libelle' => 'Transition', 'triable' => false],
            ['cle' => 'valeur', 'libelle' => 'Valeur verte', 'triable' => true],
            ['cle' => 'contact', 'libelle' => 'Contact', 'triable' => false],
            ['cle' => 'source', 'libelle' => 'Source', 'triable' => false],
            ['cle' => 'pdf', 'libelle' => '', 'triable' => false],
        ];
    }

    /**
     * @return list<array{cle: string, libelle: string, confirmation?: string}>
     */
    public function actionsGroupees(): array
    {
        return [
            ['cle' => 'rapports.supprimer', 'libelle' => 'Supprimer', 'confirmation' => 'Supprimer définitivement les rapports sélectionnés ?'],
        ];
    }

    public function identifiant(mixed $element): string
    {
        return $element instanceof Rapport ? (string) $element->id() : '';
    }

    protected function cleSource(): string
    {
        return 'rapports';
    }
}
