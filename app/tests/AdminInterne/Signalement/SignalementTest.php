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
}
