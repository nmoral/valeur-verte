<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\StatutRge;
use PHPUnit\Framework\TestCase;

final class StatutRgeTest extends TestCase
{
    public function testLesValeursSontStablesPourLaSerialisation(): void
    {
        self::assertSame('rge', StatutRge::Rge->value);
        self::assertSame('non_rge', StatutRge::NonRge->value);
        self::assertSame('indetermine', StatutRge::Indetermine->value);
    }

    public function testChaqueStatutPorteUnLibelleLisible(): void
    {
        self::assertSame('RGE', StatutRge::Rge->libelle());
        self::assertSame('Non RGE', StatutRge::NonRge->libelle());
        self::assertSame('Indéterminé', StatutRge::Indetermine->libelle());
    }
}
