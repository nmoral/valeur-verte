<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\Unite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UniteTest extends TestCase
{
    #[DataProvider('symbolesAttendus')]
    public function testChaqueUnitePorteSonSymboleAffichable(Unite $unite, string $symboleAttendu): void
    {
        self::assertSame($symboleAttendu, $unite->symbole());
    }

    /**
     * @return iterable<string, array{Unite, string}>
     */
    public static function symbolesAttendus(): iterable
    {
        yield 'euro par m²' => [Unite::EuroParM2, '€/m²'];
        yield 'euro par unité' => [Unite::EuroParUnite, '€/u'];
        yield 'forfait' => [Unite::EuroForfait, '€'];
    }
}
