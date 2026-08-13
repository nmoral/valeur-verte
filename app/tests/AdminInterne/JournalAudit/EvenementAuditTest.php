<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\EvenementAudit;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EvenementAuditTest extends TestCase
{
    public function testUnEvenementConserveQuiQuoiSurQuoiEtQuand(): void
    {
        $survenuLe = new DateTimeImmutable('2026-08-13 10:00:00');

        $evenement = new EvenementAudit(
            acteur: 'n.moral@live.fr',
            action: 'coefficient.flag_aberrant',
            cible: 'maille:31555',
            contexte: ['erreur_mediane' => 42.0],
            survenuLe: $survenuLe,
        );

        self::assertSame('n.moral@live.fr', $evenement->acteur());
        self::assertSame('coefficient.flag_aberrant', $evenement->action());
        self::assertSame('maille:31555', $evenement->cible());
        self::assertSame(['erreur_mediane' => 42.0], $evenement->contexte());
        self::assertEquals($survenuLe, $evenement->survenuLe());
    }

    public function testUneActionVideEstRejetee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EvenementAudit(
            acteur: 'n.moral@live.fr',
            action: '   ',
            cible: 'maille:31555',
            contexte: [],
            survenuLe: new DateTimeImmutable('2026-08-13 10:00:00'),
        );
    }
}
