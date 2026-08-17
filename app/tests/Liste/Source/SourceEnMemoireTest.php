<?php

declare(strict_types=1);

namespace App\Tests\Liste\Source;

use App\Liste\ColonnesTriables;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceEnMemoire;
use PHPUnit\Framework\TestCase;

final readonly class Ville
{
    public function __construct(
        public string $nom,
        public int $n,
    ) {
    }
}

final class SourceEnMemoireTest extends TestCase
{
    /**
     * @return SourceEnMemoire<Ville>
     */
    private function source(): SourceEnMemoire
    {
        return new SourceEnMemoire(
            [new Ville('Toulouse', 30), new Ville('Foix', 10), new Ville('Auch', 20)],
            ColonnesTriables::de('nom', 'n'),
            ['nom' => static fn (Ville $v): string => $v->nom, 'n' => static fn (Ville $v): int => $v->n],
            [static fn (Ville $v): string => $v->nom],
        );
    }

    public function testTriAscendantParDefautSurLaColonne(): void
    {
        $page = $this->source()->interroger(
            ParametresListe::depuis(1, 10, 'nom', 'asc', '', ColonnesTriables::de('nom', 'n')),
        );

        self::assertSame(['Auch', 'Foix', 'Toulouse'], array_map(static fn (Ville $v): string => $v->nom, $page->elements()));
        self::assertSame(3, $page->total());
    }

    public function testTriDescendantNumerique(): void
    {
        $page = $this->source()->interroger(
            ParametresListe::depuis(1, 10, 'n', 'desc', '', ColonnesTriables::de('nom', 'n')),
        );

        self::assertSame([30, 20, 10], array_map(static fn (Ville $v): int => $v->n, $page->elements()));
    }

    public function testRechercheFiltreInsensibleALaCasse(): void
    {
        $page = $this->source()->interroger(
            ParametresListe::depuis(1, 10, 'nom', 'asc', 'FOI', ColonnesTriables::de('nom', 'n')),
        );

        self::assertSame(['Foix'], array_map(static fn (Ville $v): string => $v->nom, $page->elements()));
        self::assertSame(1, $page->total());
    }

    public function testPagination(): void
    {
        $elements = [];
        for ($i = 1; $i <= 25; ++$i) {
            $elements[] = new Ville(\sprintf('V%02d', $i), $i);
        }
        $source = new SourceEnMemoire(
            $elements,
            ColonnesTriables::de('n'),
            ['n' => static fn (Ville $v): int => $v->n],
        );

        $page = $source->interroger(
            ParametresListe::depuis(2, 10, 'n', 'asc', '', ColonnesTriables::de('n')),
        );

        self::assertSame(range(11, 20), array_map(static fn (Ville $v): int => $v->n, $page->elements()));
        self::assertSame(25, $page->total());
        self::assertSame(2, $page->page());
        self::assertSame(3, $page->pages());
    }

    public function testExposeSesColonnes(): void
    {
        self::assertSame(['nom', 'n'], $this->source()->colonnes()->toutes());
    }
}
