<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\SeuilEssai;
use PHPUnit\Framework\TestCase;

final class SeuilEssaiTest extends TestCase
{
    public function testLeSeuilParDefautEstDeDix(): void
    {
        self::assertSame(10, SeuilEssai::parDefaut()->valeur());
    }

    public function testUnSeuilPersonnaliseConserveSaValeur(): void
    {
        self::assertSame(25, SeuilEssai::de(25)->valeur());
    }

    public function testUnSeuilNonStrictementPositifEstRejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SeuilEssai::de(0);
    }
}
