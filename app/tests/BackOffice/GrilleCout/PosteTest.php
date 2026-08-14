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

    public function testUnPosteSeRetrouveParSonNom(): void
    {
        self::assertSame(Poste::PlanchersBas, Poste::parNom('PlanchersBas'));
    }

    public function testUnNomDePosteInconnuEstRejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Poste::parNom('Piscine');
    }

    #[DataProvider('unitesAttendues')]
    public function testChaquePostePorteSonUniteDeChiffrage(Poste $poste, Unite $uniteAttendue): void
    {
        self::assertSame($uniteAttendue, $poste->unite());
    }

    #[DataProvider('libellesAttendus')]
    public function testChaquePostePorteSonLibelleAffichable(Poste $poste, string $libelleAttendu): void
    {
        self::assertSame($libelleAttendu, $poste->libelle());
    }

    /**
     * @return iterable<string, array{Poste, string}>
     */
    public static function libellesAttendus(): iterable
    {
        yield 'murs' => [Poste::Murs, 'Isolation des murs'];
        yield 'combles' => [Poste::Combles, 'Isolation des combles'];
        yield 'planchers bas' => [Poste::PlanchersBas, 'Planchers bas'];
        yield 'menuiseries' => [Poste::Menuiseries, 'Menuiseries'];
        yield 'chauffage' => [Poste::Chauffage, 'Pompe à chaleur air/eau'];
        yield 'ECS' => [Poste::Ecs, 'Eau chaude sanitaire'];
        yield 'VMC' => [Poste::Vmc, 'VMC double flux'];
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
