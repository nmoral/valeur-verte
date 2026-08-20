<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\JetonChargementWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Jeton de chargement du widget (#43, contrôles 3-timing et 4-anti-rejeu, épic #6).
 *
 * Émis au rendu du formulaire, signé (HMAC-SHA256, secret applicatif), stateless.
 * Il porte l'instant d'émission et n'est recevable à la soumission que dans une
 * fenêtre [âge min ; âge max] :
 *  - âge min (timing) : une soumission trop rapide trahit un bot ;
 *  - âge max (anti-rejeu) : passé le délai, le jeton expire — le rejeu d'un vieux
 *    jeton est écarté, sans stockage serveur.
 * Toute signature altérée ou tout jeton malformé est rejeté.
 */
final class JetonChargementWidgetTest extends TestCase
{
    private const string SECRET = 'secret-applicatif-de-test';

    public function testUnJetonFraisEstTropRecentDoncRejete(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-08-20 12:00:00'));
        $jetons = new JetonChargementWidget(self::SECRET, $clock);

        // Soumis dans la foulée de l'émission : âge ~0 < âge min → bot.
        self::assertFalse($jetons->estRecevable($jetons->emettre()));
    }

    public function testApresLaDureeMinimaleEtAvantExpirationLeJetonEstRecevable(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-08-20 12:00:00'));
        $jetons = new JetonChargementWidget(self::SECRET, $clock);
        $jeton = $jetons->emettre();

        $clock->sleep(JetonChargementWidget::AGE_MIN_SECONDES + 1);

        self::assertTrue($jetons->estRecevable($jeton));
    }

    public function testUnJetonExpireEstRejete(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-08-20 12:00:00'));
        $jetons = new JetonChargementWidget(self::SECRET, $clock);
        $jeton = $jetons->emettre();

        $clock->sleep(JetonChargementWidget::AGE_MAX_SECONDES + 1);

        self::assertFalse($jetons->estRecevable($jeton));
    }

    public function testUneSignatureAltereeEstRejetee(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-08-20 12:00:00'));
        $jetons = new JetonChargementWidget(self::SECRET, $clock);
        $jeton = $jetons->emettre();
        $clock->sleep(JetonChargementWidget::AGE_MIN_SECONDES + 1);

        // Même horodatage, signature d'un autre secret → doit être rejeté.
        $ts = explode('.', $jeton)[0];
        $forge = $ts.'.'.hash_hmac('sha256', 'chargement-widget:'.$ts, 'mauvais-secret');

        self::assertFalse($jetons->estRecevable($forge));
    }

    public function testUnJetonMalformeEstRejete(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-08-20 12:00:00'));
        $jetons = new JetonChargementWidget(self::SECRET, $clock);

        self::assertFalse($jetons->estRecevable(''));
        self::assertFalse($jetons->estRecevable('sans-point'));
        self::assertFalse($jetons->estRecevable('pas-un-nombre.abcdef'));
    }
}
