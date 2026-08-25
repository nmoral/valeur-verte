<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Rgpd;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\AdminInterne\Rgpd\DroitRgpd;
use App\AdminInterne\Rgpd\SuiviDemandesRgpd;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Le suivi des demandes RGPD (#309) s'adosse au journal d'audit : une demande
 * reçue et sa réponse y sont consignées, avec l'échéance à un mois et les dates
 * qui prouvent le respect du délai.
 */
final class SuiviDemandesRgpdTest extends TestCase
{
    public function testConsignerUneDemandeRecueCalculeLEcheanceAUnMois(): void
    {
        $journal = new JournalAuditEnMemoire();
        $suivi = new SuiviDemandesRgpd($journal, new MockClock(new \DateTimeImmutable('2026-08-25 14:00:00')));

        $suivi->consignerDemandeRecue(42, DroitRgpd::Portabilite, new \DateTimeImmutable('2026-08-20 09:00:00'), 'admin@example.com');

        $evenement = $journal->evenementsRecents()[0];
        self::assertSame('rgpd.demande_recue', $evenement->action());
        self::assertSame('compte#42', $evenement->cible());
        self::assertSame('admin@example.com', $evenement->acteur());
        // L'événement est daté de la consignation, mais porte l'arrivée réelle.
        self::assertEquals(new \DateTimeImmutable('2026-08-25 14:00:00'), $evenement->survenuLe());

        $contexte = $evenement->contexte();
        self::assertSame(42, $contexte['tenant_id']);
        self::assertSame('portabilite', $contexte['droit']);
        self::assertSame('2026-08-20T09:00:00+00:00', $contexte['recue_le']);
        self::assertSame('2026-09-20T09:00:00+00:00', $contexte['echeance_le']);
    }

    public function testConsignerLaReponseDateDeLInstantPresent(): void
    {
        $journal = new JournalAuditEnMemoire();
        $suivi = new SuiviDemandesRgpd($journal, new MockClock(new \DateTimeImmutable('2026-09-10 11:30:00')));

        $suivi->consignerReponse(42, DroitRgpd::Acces, 'admin@example.com');

        $evenement = $journal->evenementsRecents()[0];
        self::assertSame('rgpd.demande_traitee', $evenement->action());
        self::assertSame('compte#42', $evenement->cible());
        self::assertEquals(new \DateTimeImmutable('2026-09-10 11:30:00'), $evenement->survenuLe());

        $contexte = $evenement->contexte();
        self::assertSame('acces', $contexte['droit']);
        self::assertSame('2026-09-10T11:30:00+00:00', $contexte['reponse_le']);
    }
}
