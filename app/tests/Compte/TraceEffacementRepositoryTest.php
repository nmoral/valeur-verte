<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\TraceEffacement;
use App\Compte\TraceEffacementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la trace d'effacement (#332) est exercée contre un vrai
 * PostGIS, chaque test isolé par une transaction annulée (DAMA). La trace porte
 * l'empreinte (jamais l'adresse), la date d'effacement et l'état d'essai consommé
 * (rapports produits, fin d'essai), pour reconnaître une réinscription.
 */
final class TraceEffacementRepositoryTest extends KernelTestCase
{
    private TraceEffacementRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(TraceEffacement::class);
        self::assertInstanceOf(TraceEffacementRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testUneTraceEnregistreeSeRetrouveParEmpreinteAvecTousSesChamps(): void
    {
        $this->repository->enregistrer(TraceEffacement::creer(
            'empreinte-abc',
            new \DateTimeImmutable('2026-02-01 09:00:00'),
            3,
            new \DateTimeImmutable('2026-01-15 09:00:00'),
        ));
        $this->vider();

        $trace = $this->repository->parEmpreinte('empreinte-abc');

        self::assertNotNull($trace);
        self::assertSame('empreinte-abc', $trace->empreinte());
        self::assertEquals(new \DateTimeImmutable('2026-02-01 09:00:00'), $trace->effaceLe());
        self::assertSame(3, $trace->rapportsConsommes());
        self::assertEquals(new \DateTimeImmutable('2026-01-15 09:00:00'), $trace->finEssai());
    }

    public function testUneEmpreinteInconnueRenvoieNull(): void
    {
        self::assertNull($this->repository->parEmpreinte('jamais-vue'));
    }

    public function testLaFinDEssaiPeutEtreAbsente(): void
    {
        $this->repository->enregistrer(TraceEffacement::creer(
            'sans-fin-essai',
            new \DateTimeImmutable('2026-02-01 09:00:00'),
            0,
            null,
        ));
        $this->vider();

        $trace = $this->repository->parEmpreinte('sans-fin-essai');

        self::assertNotNull($trace);
        self::assertNull($trace->finEssai());
    }

    private function vider(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
