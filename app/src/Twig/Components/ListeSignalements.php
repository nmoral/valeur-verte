<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\AdminInterne\Signalement\Signalement;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des signalements (#49) : triable (date, adresse), recherchable
 * (adresse, message), paginée. Zone admin — la sécurité est portée par le firewall
 * host (le endpoint LiveComponent hérite de l'access_control « admin. »).
 */
#[AsLiveComponent]
final class ListeSignalements
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
            ['cle' => 'adresse', 'libelle' => 'Adresse', 'triable' => true],
            ['cle' => 'contexte', 'libelle' => 'Contexte', 'triable' => false],
            ['cle' => 'message', 'libelle' => 'Message', 'triable' => false],
            ['cle' => 'etat', 'libelle' => 'État', 'triable' => false],
        ];
    }

    /**
     * @return list<array{cle: string, libelle: string, confirmation?: string}>
     */
    public function actionsGroupees(): array
    {
        return [
            ['cle' => 'signalements.traiter', 'libelle' => 'Marquer comme traité'],
        ];
    }

    public function identifiant(mixed $element): string
    {
        return $element instanceof Signalement ? (string) $element->id() : '';
    }

    protected function cleSource(): string
    {
        return 'signalements';
    }
}
