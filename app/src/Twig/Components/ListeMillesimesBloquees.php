<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\AdminInterne\Millesime\LectureMillesimes;
use App\AdminInterne\Millesime\MailleBloquee;
use App\Liste\ColonnesTriables;
use App\Liste\Source\SourceEnMemoire;
use App\Liste\SourceListe;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Liste interactive des mailles écartées d'un millésime (REG-1/REG-2) : triable
 * (écart, commune), recherchable (code INSEE), paginée. Paramétrée par le
 * millésime — la source est construite à la volée. Le déblocage reste un
 * formulaire par ligne (garde SQL : millésime publié). Zone admin.
 */
#[AsLiveComponent]
final class ListeMillesimesBloquees
{
    use ComporteListe;
    use DefaultActionTrait;

    #[LiveProp]
    public int $millesime = 0;

    #[LiveProp]
    public bool $millesimePublie = false;

    public function __construct(
        private readonly LectureMillesimes $lecture,
    ) {
    }

    public function mount(): void
    {
        $this->tri = 'ecart';
        $this->direction = 'desc';
    }

    /**
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [
            ['cle' => 'code', 'libelle' => 'Commune', 'triable' => true],
            ['cle' => 'ecart', 'libelle' => 'Écart', 'triable' => true],
            ['cle' => 'coefficient', 'libelle' => 'Coefficient', 'triable' => false],
            ['cle' => 'actions', 'libelle' => '', 'triable' => false],
        ];
    }

    protected function source(): SourceListe
    {
        return new SourceEnMemoire(
            $this->lecture->maillesBloquees($this->millesime),
            ColonnesTriables::de('ecart', 'code'),
            [
                'ecart' => static fn (MailleBloquee $m): float => $m->ecart(),
                'code' => static fn (MailleBloquee $m): string => $m->codeInsee(),
            ],
            [static fn (MailleBloquee $m): string => $m->codeInsee()],
        );
    }

    protected function cleSource(): string
    {
        return 'millesimes_bloquees';
    }
}
