<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Signalement;

use App\AdminInterne\Signalement\Signalement;
use PHPUnit\Framework\TestCase;

final class SignalementTest extends TestCase
{
    public function testCaptureAdresseContexteMessageEtDate(): void
    {
        $signaleLe = new \DateTimeImmutable('2026-08-13 14:00:00');

        $signalement = Signalement::creer(
            adresse: '12 rue des Lilas, 31000 Toulouse',
            contexte: ['dpe_avant' => 'E', 'dpe_apres' => 'C', 'n' => 134, 'rayon_km' => 10],
            message: 'Le gain me paraît trop élevé pour ce quartier.',
            signaleLe: $signaleLe,
        );

        self::assertSame('12 rue des Lilas, 31000 Toulouse', $signalement->adresse());
        self::assertSame(['dpe_avant' => 'E', 'dpe_apres' => 'C', 'n' => 134, 'rayon_km' => 10], $signalement->contexte());
        self::assertSame('Le gain me paraît trop élevé pour ce quartier.', $signalement->message());
        self::assertEquals($signaleLe, $signalement->signaleLe());
    }

    public function testUnMessageVideDevientNull(): void
    {
        $signalement = Signalement::creer(
            adresse: '12 rue des Lilas, 31000 Toulouse',
            contexte: [],
            message: '   ',
            signaleLe: new \DateTimeImmutable('2026-08-13 14:00:00'),
        );

        self::assertNull($signalement->message());
    }

    public function testUneAdresseVideEstRejetee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Signalement::creer(
            adresse: '   ',
            contexte: [],
            message: null,
            signaleLe: new \DateTimeImmutable('2026-08-13 14:00:00'),
        );
    }

    public function testUnSignalementEstNonTraiteParDefaut(): void
    {
        $signalement = Signalement::creer('12 rue des Lilas', [], null, new \DateTimeImmutable('2026-08-13'));

        self::assertFalse($signalement->estTraite());
        self::assertNull($signalement->traiteLe());
    }

    public function testMarquerCommeTraiteFixeLaDate(): void
    {
        $signalement = Signalement::creer('12 rue des Lilas', [], null, new \DateTimeImmutable('2026-08-13'));
        $quand = new \DateTimeImmutable('2026-08-14 09:00:00');

        $signalement->marquerTraite($quand);

        self::assertTrue($signalement->estTraite());
        self::assertEquals($quand, $signalement->traiteLe());
    }

    public function testMarquerTraiteEstIdempotentSurLaPremiereDate(): void
    {
        $signalement = Signalement::creer('12 rue des Lilas', [], null, new \DateTimeImmutable('2026-08-13'));
        $premier = new \DateTimeImmutable('2026-08-14 09:00:00');

        $signalement->marquerTraite($premier);
        $signalement->marquerTraite(new \DateTimeImmutable('2026-08-20 10:00:00'));

        // Le traitement garde sa date d'origine (append-only sur l'état).
        self::assertEquals($premier, $signalement->traiteLe());
    }
}
