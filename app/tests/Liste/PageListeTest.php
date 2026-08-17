<?php

declare(strict_types=1);

namespace App\Tests\Liste;

use App\Liste\ColonnesTriables;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use PHPUnit\Framework\TestCase;

final class PageListeTest extends TestCase
{
    public function testDeNeDecoupePasEtPorteLeTotalDuCount(): void
    {
        // Les éléments sont déjà la tranche (LIMIT/OFFSET SQL) ; le total vient du COUNT.
        $page = PageListe::de(['a', 'b'], 2, 2, 10);

        self::assertSame(['a', 'b'], $page->elements());
        self::assertSame(2, $page->page());
        self::assertSame(2, $page->parPage());
        self::assertSame(10, $page->total());
        self::assertSame(5, $page->pages());
        self::assertFalse($page->estVide());
    }

    public function testDeClampeLaPageDansLesBornes(): void
    {
        self::assertSame(5, PageListe::de([], 99, 2, 10)->page());
        self::assertSame(1, PageListe::de([], 0, 2, 10)->page());
    }

    public function testPagesVautUnQuandVide(): void
    {
        $page = PageListe::de([], 1, 50, 0);

        self::assertSame(1, $page->pages());
        self::assertSame(0, $page->total());
        self::assertTrue($page->estVide());
    }

    public function testPagePrecedenteEtSuivante(): void
    {
        $premiere = PageListe::de(['x'], 1, 10, 30);
        self::assertNull($premiere->pagePrecedente());
        self::assertSame(2, $premiere->pageSuivante());

        $derniere = PageListe::de(['x'], 3, 10, 30);
        self::assertSame(2, $derniere->pagePrecedente());
        self::assertNull($derniere->pageSuivante());
    }

    public function testDecouperEnMemoireTrancheLaListeComplete(): void
    {
        $tous = range(1, 25);
        $params = ParametresListe::depuis(2, 10, 'id', 'asc', '', ColonnesTriables::de('id'));

        $page = PageListe::decouperEnMemoire($tous, $params);

        self::assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], $page->elements());
        self::assertSame(2, $page->page());
        self::assertSame(25, $page->total());
        self::assertSame(3, $page->pages());
    }

    public function testDecouperEnMemoireClampeUnePageTropGrande(): void
    {
        $tous = range(1, 5);
        $params = ParametresListe::depuis(9, 10, 'id', 'asc', '', ColonnesTriables::de('id'));

        $page = PageListe::decouperEnMemoire($tous, $params);

        self::assertSame([1, 2, 3, 4, 5], $page->elements());
        self::assertSame(1, $page->page());
    }
}
