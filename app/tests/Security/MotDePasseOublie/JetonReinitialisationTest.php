<?php

declare(strict_types=1);

namespace App\Tests\Security\MotDePasseOublie;

use App\Security\MotDePasseOublie\JetonReinitialisation;
use PHPUnit\Framework\TestCase;

/**
 * Jeton de réinitialisation de mot de passe (#194, AUTH-1). Contrairement au
 * désabonnement (HMAC pur, idempotent), la réinitialisation exige un jeton
 * **à usage unique** et **expirant** : la validité dépend donc de l'état
 * (consommé ou non) et de l'horloge, pas seulement de la signature.
 */
final class JetonReinitialisationTest extends TestCase
{
    public function testUnJetonEstUtilisableAvantExpirationEtTantQueNonConsomme(): void
    {
        $jeton = new JetonReinitialisation(42, 'empreinte', new \DateTimeImmutable('2026-01-01 11:00:00'));

        self::assertTrue($jeton->estUtilisable(new \DateTimeImmutable('2026-01-01 10:30:00')));
    }

    public function testUnJetonExpireNestPlusUtilisable(): void
    {
        $jeton = new JetonReinitialisation(42, 'empreinte', new \DateTimeImmutable('2026-01-01 11:00:00'));

        self::assertFalse($jeton->estUtilisable(new \DateTimeImmutable('2026-01-01 11:00:01')));
    }

    public function testUnJetonConsommeNestPlusUtilisableMemeAvantExpiration(): void
    {
        $jeton = new JetonReinitialisation(42, 'empreinte', new \DateTimeImmutable('2026-01-01 11:00:00'));

        $jeton->marquerUtilise(new \DateTimeImmutable('2026-01-01 10:15:00'));

        self::assertFalse($jeton->estUtilisable(new \DateTimeImmutable('2026-01-01 10:30:00')));
    }
}
