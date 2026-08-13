<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\ReglageSeuilEssai;
use App\AdminInterne\Compte\SeuilEssai;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Test d'intégration : régler le seuil met à jour la source unique ET trace le
 * changement au journal d'audit (#50), pour ne pas modifier un réglage produit
 * en silence.
 */
final class ReglageSeuilEssaiTest extends KernelTestCase
{
    public function testReglerMetAJourLeSeuilEtTraceLeChangement(): void
    {
        self::bootKernel();
        $seuils = $this->seuils();
        $seuils->enregistrer(SeuilEssai::de(10));
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 15:00:00'));

        $reglage = new ReglageSeuilEssai($seuils, $journal, $clock);
        $reglage->regler(25, 'admin@example.com');

        self::assertSame(25, $seuils->courant()->valeur());

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        $evenement = $evenements[0];
        self::assertSame('admin@example.com', $evenement->acteur());
        self::assertSame('seuil_essai.modifie', $evenement->action());
        self::assertSame(['ancien' => 10, 'nouveau' => 25], $evenement->contexte());
    }

    public function testUnSeuilInvalideNeChangeRienEtNeTraceRien(): void
    {
        self::bootKernel();
        $seuils = $this->seuils();
        $seuils->enregistrer(SeuilEssai::de(10));
        $journal = new JournalAuditEnMemoire();
        $clock = new MockClock(new \DateTimeImmutable('2026-08-13 15:00:00'));

        $reglage = new ReglageSeuilEssai($seuils, $journal, $clock);

        try {
            $reglage->regler(0, 'admin@example.com');
            self::fail('Un seuil non positif aurait dû être rejeté.');
        } catch (\InvalidArgumentException) {
            // attendu
        }

        self::assertSame(10, $seuils->courant()->valeur());
        self::assertCount(0, $journal->evenementsRecents());
    }

    private function seuils(): SeuilEssaiRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilEssai::class);
        self::assertInstanceOf(SeuilEssaiRepository::class, $repository);

        return $repository;
    }
}
