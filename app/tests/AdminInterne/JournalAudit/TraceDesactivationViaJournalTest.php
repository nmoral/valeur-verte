<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\AdminInterne\JournalAudit\TraceDesactivationViaJournal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TraceDesactivationViaJournalTest extends TestCase
{
    public function testLaLeveeDuCloisonnementEstInscriteAuJournal(): void
    {
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 14:30:00'));
        $trace = new TraceDesactivationViaJournal($journal, $clock);

        $trace->tracer('export mensuel des rapports', 'admin@vv.test');

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        $evenement = $evenements[0];
        self::assertSame('admin@vv.test', $evenement->acteur());
        self::assertSame('tenant.cloisonnement_leve', $evenement->action());
        self::assertSame('tenant:*', $evenement->cible());
        self::assertSame(['raison' => 'export mensuel des rapports'], $evenement->contexte());
        self::assertEquals(new \DateTimeImmutable('2026-08-13 14:30:00'), $evenement->survenuLe());
    }
}
