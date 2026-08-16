<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\ReglageSeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ReglageSeuilsSupervisionTest extends KernelTestCase
{
    public function testReglerMetAJourLesSeuilsEtTraceLeChangement(): void
    {
        self::bootKernel();
        $seuils = $this->seuils();
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 16:00:00'));

        $reglage = new ReglageSeuilsSupervision($seuils, $journal, $clock);
        $reglage->regler(25.0, 0.60, 'admin@example.com');

        $courants = $seuils->courants();
        self::assertSame(25.0, $courants->erreurMedianeMaxPct());
        self::assertSame(0.60, $courants->gainEcMaxLog());

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        self::assertSame('admin@example.com', $evenements[0]->acteur());
        self::assertSame('seuils_supervision.modifie', $evenements[0]->action());
    }

    public function testDesSeuilsInvalidesNeChangentRienEtNeTracentRien(): void
    {
        self::bootKernel();
        $seuils = $this->seuils();
        $seuils->enregistrer(SeuilsSupervision::de(30.0, 0.70));
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 16:00:00'));

        $reglage = new ReglageSeuilsSupervision($seuils, $journal, $clock);

        try {
            $reglage->regler(0.0, 0.60, 'admin@example.com');
            self::fail('Un seuil non positif aurait dû être rejeté.');
        } catch (\InvalidArgumentException) {
            // attendu
        }

        self::assertSame(30.0, $seuils->courants()->erreurMedianeMaxPct());
        self::assertCount(0, $journal->evenementsRecents());
    }

    private function seuils(): SeuilsSupervisionRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilsSupervision::class);
        self::assertInstanceOf(SeuilsSupervisionRepository::class, $repository);

        return $repository;
    }
}
