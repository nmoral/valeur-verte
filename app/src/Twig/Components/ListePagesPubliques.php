<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\AdminInterne\PagePublique\PagePubliqueSupervisee;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des pages publiques (#268) : triable (page, état, dernière
 * alerte), recherchable (slug, token), paginée, avec le kill switch en actions de
 * groupe. Zone admin — la sécurité est portée par le firewall host.
 */
#[AsLiveComponent]
final class ListePagesPubliques
{
    use ComporteListe;
    use DefaultActionTrait;

    public function mount(): void
    {
        $this->tri = 'etat';
        $this->direction = 'asc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'page', 'libelle' => 'Page', 'triable' => true],
            ['cle' => 'etat', 'libelle' => 'État de service', 'triable' => true],
            ['cle' => 'defi', 'libelle' => 'Défi anti-bot', 'triable' => false],
            ['cle' => 'alerte', 'libelle' => 'Dernière alerte', 'triable' => true],
        ];
    }

    /**
     * @return list<array{cle: string, libelle: string, confirmation?: string}>
     */
    public function actionsGroupees(): array
    {
        return [
            [
                'cle' => 'pages_publiques.suspendre',
                'libelle' => 'Suspendre',
                'confirmation' => 'Couper ces pages publiques ? Leurs visiteurs verront une page inconnue.',
            ],
            ['cle' => 'pages_publiques.reactiver', 'libelle' => 'Rétablir'],
        ];
    }

    public function identifiant(mixed $element): string
    {
        return $element instanceof PagePubliqueSupervisee ? $element->token() : '';
    }

    public function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    protected function cleSource(): string
    {
        return 'pages_publiques';
    }
}
