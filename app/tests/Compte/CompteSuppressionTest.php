<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Compte;
use PHPUnit\Framework\TestCase;

final class CompteSuppressionTest extends TestCase
{
    public function testUnCompteNEstPasEnAttenteDeSuppressionParDefaut(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));

        self::assertFalse($compte->estEnAttenteDeSuppression());
        self::assertNull($compte->suppressionDemandeeLe());
    }

    public function testDemanderPuisAnnulerLaSuppression(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));
        $quand = new \DateTimeImmutable('2026-02-01 10:00:00');

        $compte->demanderSuppression($quand);
        self::assertTrue($compte->estEnAttenteDeSuppression());
        self::assertEquals($quand, $compte->suppressionDemandeeLe());

        $compte->annulerSuppression();
        self::assertFalse($compte->estEnAttenteDeSuppression());
        self::assertNull($compte->suppressionDemandeeLe());
    }

    public function testDemanderLaSuppressionEstIdempotentSurLaPremiereDate(): void
    {
        $compte = Compte::creer(1, new \DateTimeImmutable('2026-01-01'));
        $premier = new \DateTimeImmutable('2026-02-01 10:00:00');

        $compte->demanderSuppression($premier);
        $compte->demanderSuppression(new \DateTimeImmutable('2026-03-01 10:00:00'));

        self::assertEquals($premier, $compte->suppressionDemandeeLe());
    }
}
