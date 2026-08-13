<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Estimation\ContexteCalcul;
use PHPUnit\Framework\TestCase;

/**
 * Contexte de calcul figé dans l'instantané (#33, §9.1) : les coefficients DPE
 * (effets log) réellement employés et l'erreur médiane de la maille. Sérialisable
 * en JSONB pour l'archivage immuable.
 */
final class ContexteCalculTest extends TestCase
{
    public function testExposeLesCoefficientsEtLErreurMediane(): void
    {
        $contexte = ContexteCalcul::depuis(coefDpeAvant: -0.20, coefDpeApres: 0.10, erreurMediane: 17.22);

        self::assertSame(-0.20, $contexte->coefDpeAvant());
        self::assertSame(0.10, $contexte->coefDpeApres());
        self::assertSame(17.22, $contexte->erreurMediane());
    }

    public function testSeSerialiseEtSeReconstruitALIdentique(): void
    {
        $contexte = ContexteCalcul::depuis(-0.20, 0.10, 17.22);

        self::assertEquals($contexte, ContexteCalcul::fromArray($contexte->toArray()));
    }
}
