<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le repository est exercé contre un vrai PostGIS
 * (base « valeur_verte_test »), chaque test isolé par une transaction annulée (DAMA).
 */
final class RapportRepositoryTest extends KernelTestCase
{
    private RapportRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testEnregistreEtReliteLesRapportsLesPlusRecentsDAbord(): void
    {
        $this->repository->enregistrer($this->rapport('2026-0001', '2026-08-01 09:00:00'));
        $this->repository->enregistrer($this->rapport('2026-0002', '2026-08-10 09:00:00'));

        $recents = $this->repository->recents();

        self::assertCount(2, $recents);
        self::assertSame('2026-0002', $recents[0]->reference());
        self::assertSame('2026-0001', $recents[1]->reference());
    }

    public function testLeContactDUnLeadEstPersisteEtReconstruitDepuisLaBase(): void
    {
        $contact = Contact::creer('Mme Cazeneuve', 'caze@example.com', '05 61 00 00 00');
        $this->repository->enregistrer($this->rapport('2026-0003', '2026-08-05 09:00:00', $contact));

        // Vider l'identity map : la relecture vient réellement de la base.
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $relu = $this->repository->recents()[0];
        self::assertTrue($relu->estUnLead());
        self::assertEquals($contact, $relu->contact());
    }

    public function testUnRapportSansContactReluNEstPasUnLead(): void
    {
        $this->repository->enregistrer($this->rapport('2026-0004', '2026-08-06 09:00:00'));

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $relu = $this->repository->recents()[0];
        self::assertFalse($relu->estUnLead());
        self::assertNull($relu->contact());
    }

    public function testSupprimeUniquementLesRapportsDuTenantCourant(): void
    {
        $mien = $this->rapport('2026-0100', '2026-08-01 09:00:00');
        $mien->setTenantId(1);
        $autre = $this->rapport('2026-0200', '2026-08-02 09:00:00');
        $autre->setTenantId(2);
        $this->repository->enregistrer($mien);
        $this->repository->enregistrer($autre);

        $nombre = $this->repository->supprimerParIds([(int) $mien->id(), (int) $autre->id()], 1);

        self::assertSame(1, $nombre);

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $restants = array_map(static fn (Rapport $r): string => $r->reference(), $this->repository->recents());
        self::assertContains('2026-0200', $restants);
        self::assertNotContains('2026-0100', $restants);
    }

    public function testSupprimerParIdsSansIdsNeFaitRien(): void
    {
        self::assertSame(0, $this->repository->supprimerParIds([], 1));
    }

    private function rapport(string $reference, string $creeLe, ?Contact $contact = null): Rapport
    {
        return Rapport::creer(
            $reference,
            new \DateTimeImmutable($creeLe),
            Source::Audit,
            InstantaneRapportMother::creer(),
            '/rapports/'.$reference.'.pdf',
            $contact,
        );
    }
}
