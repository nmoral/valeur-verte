<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\PorteeEstimation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cran de la cascade géographique (#169) : le rayon seul ne distingue plus les
 * crans (EPCI et DÉPARTEMENT ont tous deux un rayon absent), c'est le `mode` de
 * la maille qui porte la granularité. La portée en dérive sa formulation.
 */
final class PorteeEstimationTest extends TestCase
{
    #[DataProvider('formulations')]
    public function testLaFormulationSuitLeCran(PorteeEstimation $portee, ?int $rayonKm, string $attendu): void
    {
        self::assertSame($attendu, $portee->formulation($rayonKm));
    }

    /**
     * @return iterable<string, array{PorteeEstimation, int|null, string}>
     */
    public static function formulations(): iterable
    {
        yield 'local porte son rayon' => [PorteeEstimation::Local, 25, 'dans un rayon de 25 km'];
        yield 'epci nomme l\'intercommunalité' => [PorteeEstimation::Epci, null, 'au niveau intercommunal'];
        yield 'départemental' => [PorteeEstimation::Departemental, null, 'au niveau départemental'];
    }

    #[DataProvider('intitules')]
    public function testLIntituleCourtAlimenteLaStatistique(PorteeEstimation $portee, ?int $rayonKm, string $attendu): void
    {
        self::assertSame($attendu, $portee->intitule($rayonKm));
    }

    /**
     * @return iterable<string, array{PorteeEstimation, int|null, string}>
     */
    public static function intitules(): iterable
    {
        yield 'local' => [PorteeEstimation::Local, 15, '15 km'];
        yield 'epci' => [PorteeEstimation::Epci, null, 'intercommunal (EPCI)'];
        yield 'départemental' => [PorteeEstimation::Departemental, null, 'départemental'];
    }

    /**
     * Traduction du contrat data→app : la colonne `mode` fait foi quand elle est
     * présente. Une ligne héritée (mode absent, avant #169) se lit à l'ancienne :
     * un rayon présent est local, un rayon absent est le repli départemental.
     */
    #[DataProvider('lectures')]
    public function testElleSeDeduitDuModeEtDuRayon(?string $mode, ?int $rayonKm, PorteeEstimation $attendu): void
    {
        self::assertSame($attendu, PorteeEstimation::depuis($mode, $rayonKm));
    }

    /**
     * @return iterable<string, array{string|null, int|null, PorteeEstimation}>
     */
    public static function lectures(): iterable
    {
        yield 'mode local' => ['local', 10, PorteeEstimation::Local];
        yield 'mode epci' => ['epci', null, PorteeEstimation::Epci];
        yield 'mode départemental' => ['departemental', null, PorteeEstimation::Departemental];
        yield 'legacy avec rayon → local' => [null, 10, PorteeEstimation::Local];
        yield 'legacy sans rayon → départemental' => [null, null, PorteeEstimation::Departemental];
        yield 'mode inconnu avec rayon → local' => ['???', 20, PorteeEstimation::Local];
        yield 'mode inconnu sans rayon → départemental' => ['???', null, PorteeEstimation::Departemental];
    }
}
