<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\JournalAudit;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\AdminInterne\JournalAudit\NotificationAdminViaJournal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class NotificationAdminViaJournalTest extends TestCase
{
    public function testUnUsageAnormalEstInscritAuJournal(): void
    {
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 12:00:00'));
        $notification = new NotificationAdminViaJournal($journal, $clock);

        $notification->usageAnormal('ip:81.220.11.4', '37 tentatives en 1 min');

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        $evenement = $evenements[0];
        self::assertSame('systeme', $evenement->acteur());
        self::assertSame('usage.anormal', $evenement->action());
        self::assertSame('ip:81.220.11.4', $evenement->cible());
        self::assertSame(['detail' => '37 tentatives en 1 min'], $evenement->contexte());
        self::assertEquals(new \DateTimeImmutable('2026-08-13 12:00:00'), $evenement->survenuLe());
    }
}
