<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\AdministrationComptes;
use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Intégration : les décisions d'administration des comptes (#47) écrivent en base
 * et sont tracées au journal d'audit (#50).
 */
final class AdministrationComptesTest extends KernelTestCase
{
    private CompteRepository $comptes;
    private JournalAuditEnMemoire $journal;
    private AdministrationComptes $administration;

    protected function setUp(): void
    {
        self::bootKernel();
        $comptes = self::getContainer()->get(CompteRepository::class);
        self::assertInstanceOf(CompteRepository::class, $comptes);
        $this->comptes = $comptes;
        $this->journal = new JournalAuditEnMemoire();
        $this->administration = new AdministrationComptes(
            $this->comptes,
            $this->journal,
            new MockClock(new \DateTimeImmutable('2026-08-17 09:00:00')),
        );
    }

    public function testSuspendMarqueLeCompteEtTraceLeJournal(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));

        self::assertTrue($this->administration->suspendre(42, 'admin@example.com'));

        $this->viderIdentityMap();
        self::assertTrue($this->comptes->pourTenant(42)?->estSuspendu());

        $evenement = $this->journal->evenementsRecents()[0];
        self::assertSame('compte.suspendu', $evenement->action());
        self::assertSame('compte#42', $evenement->cible());
        self::assertSame('admin@example.com', $evenement->acteur());
    }

    public function testSuspendreUnCompteDejaSuspenduNeFaitRien(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));
        $this->administration->suspendre(42, 'admin@example.com');

        self::assertFalse($this->administration->suspendre(42, 'admin@example.com'));
        self::assertCount(1, $this->journal->evenementsRecents());
    }

    public function testProlongerEssaiRepousseLaDateEtTrace(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));

        self::assertTrue($this->administration->prolongerEssai(42, 14, 'admin@example.com'));

        $this->viderIdentityMap();
        $jusqua = $this->comptes->pourTenant(42)?->essaiProlongeJusquau();
        self::assertEquals(new \DateTimeImmutable('2026-08-31 09:00:00'), $jusqua);

        self::assertSame('compte.essai_prolonge', $this->journal->evenementsRecents()[0]->action());
    }

    public function testDemanderSuppressionMarqueLeCompteEtTrace(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));

        self::assertTrue($this->administration->demanderSuppression(42, 'admin@example.com'));

        $this->viderIdentityMap();
        $compte = $this->comptes->pourTenant(42);
        self::assertTrue($compte?->estEnAttenteDeSuppression());
        self::assertEquals(new \DateTimeImmutable('2026-08-17 09:00:00'), $compte?->suppressionDemandeeLe());

        $evenement = $this->journal->evenementsRecents()[0];
        self::assertSame('compte.suppression_demandee', $evenement->action());
        self::assertSame('compte#42', $evenement->cible());
        self::assertSame('admin@example.com', $evenement->acteur());
    }

    public function testDemanderSuppressionSurCompteDejaEnAttenteNeFaitRien(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));
        $this->administration->demanderSuppression(42, 'admin@example.com');

        self::assertFalse($this->administration->demanderSuppression(42, 'admin@example.com'));
        self::assertCount(1, $this->journal->evenementsRecents());
    }

    public function testAnnulerSuppressionLeveLaDemandeEtTrace(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));
        $this->administration->demanderSuppression(42, 'pro@example.com');

        self::assertTrue($this->administration->annulerSuppression(42, 'pro@example.com'));

        $this->viderIdentityMap();
        self::assertFalse($this->comptes->pourTenant(42)?->estEnAttenteDeSuppression());
        self::assertSame('compte.suppression_annulee', $this->journal->evenementsRecents()[0]->action());
    }

    public function testAnnulerSuppressionSansDemandeNeFaitRien(): void
    {
        $this->comptes->enregistrer(Compte::creer(42, new \DateTimeImmutable('2026-01-01')));

        self::assertFalse($this->administration->annulerSuppression(42, 'admin@example.com'));
        self::assertCount(0, $this->journal->evenementsRecents());
    }

    private function viderIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
