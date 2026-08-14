<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\ReglageDelaiRelance;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\Compte\DelaiRelanceEssai;
use App\Compte\DelaiRelanceEssaiRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Régler le délai de relance met à jour la source unique ET trace le changement
 * au journal d'audit (#50/#108).
 */
final class ReglageDelaiRelanceTest extends KernelTestCase
{
    public function testReglerMetAJourLeDelaiEtTraceLeChangement(): void
    {
        self::bootKernel();
        $delais = self::getContainer()->get(DelaiRelanceEssaiRepository::class);
        self::assertInstanceOf(DelaiRelanceEssaiRepository::class, $delais);
        $delais->enregistrer(DelaiRelanceEssai::de(7));
        $journal = new JournalAuditEnMemoire();

        (new ReglageDelaiRelance($delais, $journal, new MockClock(new \DateTimeImmutable('2026-08-14 10:00:00'))))
            ->regler(3, 'admin@example.com');

        self::assertSame(3, $delais->courant()->jours());
        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        self::assertSame('delai_relance_essai.modifie', $evenements[0]->action());
        self::assertSame(['ancien' => 7, 'nouveau' => 3], $evenements[0]->contexte());
    }
}
