<?php

declare(strict_types=1);

namespace App\Tests\Liste;

use App\Liste\ColonnesTriables;
use PHPUnit\Framework\TestCase;

final class ColonnesTriablesTest extends TestCase
{
    public function testLeDefautFaitPartieDesColonnes(): void
    {
        $triables = ColonnesTriables::de('date', 'acteur', 'action');

        self::assertSame('date', $triables->defaut());
        self::assertTrue($triables->contient('date'));
        self::assertTrue($triables->contient('acteur'));
        self::assertSame(['date', 'acteur', 'action'], $triables->toutes());
    }

    public function testContientRejetteUneColonneInconnue(): void
    {
        $triables = ColonnesTriables::de('date', 'acteur');

        self::assertFalse($triables->contient('prix'));
        self::assertFalse($triables->contient(''));
    }

    public function testNormaliserRenvoieLaColonneSiConnueSinonLeDefaut(): void
    {
        $triables = ColonnesTriables::de('date', 'acteur');

        self::assertSame('acteur', $triables->normaliser('acteur'));
        self::assertSame('date', $triables->normaliser('inconnue'));
        self::assertSame('date', $triables->normaliser(null));
    }

    public function testLesDoublonsSontEcartes(): void
    {
        $triables = ColonnesTriables::de('date', 'date', 'acteur', 'acteur');

        self::assertSame(['date', 'acteur'], $triables->toutes());
    }

    public function testUnDefautVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ColonnesTriables::de('   ');
    }
}
