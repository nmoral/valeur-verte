<?php

declare(strict_types=1);

namespace App\Tests\Liste;

use App\Liste\ColonnesTriables;
use App\Liste\Direction;
use App\Liste\ParametresListe;
use PHPUnit\Framework\TestCase;

final class ParametresListeTest extends TestCase
{
    private function triables(): ColonnesTriables
    {
        return ColonnesTriables::de('date', 'acteur', 'action');
    }

    public function testValeursNominales(): void
    {
        $params = ParametresListe::depuis(2, 50, 'acteur', 'desc', 'toulouse', $this->triables());

        self::assertSame(2, $params->page());
        self::assertSame(50, $params->parPage());
        self::assertSame('acteur', $params->tri());
        self::assertSame(Direction::Desc, $params->direction());
        self::assertSame('toulouse', $params->recherche());
        self::assertTrue($params->aUneRecherche());
    }

    public function testLaPageEstAuMinimumUn(): void
    {
        self::assertSame(1, ParametresListe::depuis(0, 50, 'date', 'asc', '', $this->triables())->page());
        self::assertSame(1, ParametresListe::depuis(-5, 50, 'date', 'asc', '', $this->triables())->page());
    }

    public function testParPageEstBorne(): void
    {
        self::assertSame(1, ParametresListe::depuis(1, 0, 'date', 'asc', '', $this->triables())->parPage());
        self::assertSame(
            ParametresListe::PAR_PAGE_MAX,
            ParametresListe::depuis(1, 10_000, 'date', 'asc', '', $this->triables())->parPage(),
        );
    }

    public function testUnTriHorsListeBlancheRetombeSurLeDefaut(): void
    {
        // Défense anti-injection ORDER BY : seule une colonne déclarée est acceptée.
        $params = ParametresListe::depuis(1, 50, 'prix; DROP TABLE', 'asc', '', $this->triables());

        self::assertSame('date', $params->tri());
    }

    public function testUnTriNulRetombeSurLeDefaut(): void
    {
        self::assertSame('date', ParametresListe::depuis(1, 50, null, 'asc', '', $this->triables())->tri());
    }

    public function testUneDirectionInvalideRetombeSurAsc(): void
    {
        self::assertSame(Direction::Asc, ParametresListe::depuis(1, 50, 'date', 'oblique', '', $this->triables())->direction());
    }

    public function testLaRechercheEstNettoyeeEtTronquee(): void
    {
        self::assertSame('foix', ParametresListe::depuis(1, 50, 'date', 'asc', '  foix  ', $this->triables())->recherche());
        self::assertFalse(ParametresListe::depuis(1, 50, 'date', 'asc', '   ', $this->triables())->aUneRecherche());

        $longue = str_repeat('a', ParametresListe::RECHERCHE_MAX + 50);
        self::assertSame(ParametresListe::RECHERCHE_MAX, mb_strlen(
            ParametresListe::depuis(1, 50, 'date', 'asc', $longue, $this->triables())->recherche(),
        ));
    }

    public function testOffset(): void
    {
        self::assertSame(0, ParametresListe::depuis(1, 50, 'date', 'asc', '', $this->triables())->offset());
        self::assertSame(100, ParametresListe::depuis(3, 50, 'date', 'asc', '', $this->triables())->offset());
    }
}
