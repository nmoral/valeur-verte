<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\PurgeTracesEffacement;
use App\Compte\TraceEffacement;
use App\Compte\TraceEffacementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Purge des traces d'effacement (#332) : une empreinte n'est conservée que le
 * temps borné de la rétention (12 mois), au-delà duquel l'anti-abus ne se
 * justifie plus (proportionnalité RGPD). Passée par le cron (#190).
 */
final class PurgeTracesEffacementTest extends KernelTestCase
{
    public function testElleSupprimeLesTracesAuDelaDeDouzeMois(): void
    {
        self::bootKernel();
        $repository = $this->repository();
        // Horloge au 2026-08-25 → borne de rétention au 2025-08-25.
        $repository->enregistrer(TraceEffacement::creer('perimee', new \DateTimeImmutable('2025-06-01 09:00:00'), 0, null));
        $repository->enregistrer(TraceEffacement::creer('vivante', new \DateTimeImmutable('2026-06-01 09:00:00'), 0, null));
        $this->vider();

        $purge = new PurgeTracesEffacement($repository, new MockClock(new \DateTimeImmutable('2026-08-25 10:00:00')), 12);
        $supprimees = $purge->executer();
        $this->vider();

        self::assertSame(1, $supprimees);
        self::assertNull($repository->parEmpreinte('perimee'));
        self::assertNotNull($repository->parEmpreinte('vivante'));
    }

    private function repository(): TraceEffacementRepository
    {
        $repository = self::getContainer()->get(TraceEffacementRepository::class);
        self::assertInstanceOf(TraceEffacementRepository::class, $repository);

        return $repository;
    }

    private function vider(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();
    }
}
