<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\GrilleCout\Unite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PosteTest extends TestCase
{
    public function testLesSeptPostesDuBaremeSontDefinis(): void
    {
        self::assertCount(7, Poste::cases());
    }

    #[DataProvider('unitesAttendues')]
    public function testChaquePostePorteSonUniteDeChiffrage(Poste $poste, Unite $uniteAttendue): void
    {
        self::assertSame($uniteAttendue, $poste->unite());
    }

    /**
     * @return iterable<string, array{Poste, Unite}>
     */
    public static function unitesAttendues(): iterable
    {
        yield 'murs au m²' => [Poste::Murs, Unite::EuroParM2];
        yield 'combles au m²' => [Poste::Combles, Unite::EuroParM2];
        yield 'planchers bas au m²' => [Poste::PlanchersBas, Unite::EuroParM2];
        yield 'menuiseries à l\'unité' => [Poste::Menuiseries, Unite::EuroParUnite];
        yield 'chauffage au forfait' => [Poste::Chauffage, Unite::EuroForfait];
        yield 'ECS au forfait' => [Poste::Ecs, Unite::EuroForfait];
        yield 'VMC au forfait' => [Poste::Vmc, Unite::EuroForfait];
    }
}
