<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\DiagnosticMaille;
use App\AdminInterne\Coefficient\MailleSupervisee;
use App\AdminInterne\Coefficient\PageMailles;
use App\AdminInterne\Coefficient\SeuilsSupervision;
use PHPUnit\Framework\TestCase;

final class PageMaillesTest extends TestCase
{
    public function testDecoupeLaPremierePage(): void
    {
        $page = PageMailles::decouper($this->mailles(5), page: 1, taille: 2);

        self::assertSame(1, $page->page());
        self::assertSame(3, $page->pages());
        self::assertSame(5, $page->total());
        self::assertSame(['00', '01'], $this->codes($page));
    }

    public function testDecoupeUnePageDuMilieu(): void
    {
        $page = PageMailles::decouper($this->mailles(5), page: 2, taille: 2);

        self::assertSame(['02', '03'], $this->codes($page));
    }

    public function testUnePageAuDelaEstRameneeALaDerniere(): void
    {
        $page = PageMailles::decouper($this->mailles(5), page: 99, taille: 2);

        self::assertSame(3, $page->page());
        self::assertSame(['04'], $this->codes($page));
    }

    public function testUneListeVideDonneUneSeulePageVide(): void
    {
        $page = PageMailles::decouper([], page: 1, taille: 2);

        self::assertSame(1, $page->page());
        self::assertSame(1, $page->pages());
        self::assertSame(0, $page->total());
        self::assertSame([], $this->codes($page));
    }

    /**
     * @return list<MailleSupervisee>
     */
    private function mailles(int $combien): array
    {
        $seuils = SeuilsSupervision::de(30.0, 0.70);
        $mailles = [];
        for ($i = 0; $i < $combien; ++$i) {
            $mailles[] = new MailleSupervisee(
                codeInsee: \sprintf('%02d', $i),
                millesime: 2024,
                n: 100,
                rayonKm: 10,
                erreurMediane: 17.0,
                gainEcLog: 0.30,
                diagnostic: DiagnosticMaille::pour(17.0, 0.30, null, $seuils),
            );
        }

        return $mailles;
    }

    /**
     * @return list<string>
     */
    private function codes(PageMailles $page): array
    {
        return array_map(static fn (MailleSupervisee $m): string => $m->codeInsee(), $page->mailles());
    }
}
