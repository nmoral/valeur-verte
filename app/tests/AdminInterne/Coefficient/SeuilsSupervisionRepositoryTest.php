<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\SeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : les seuils de supervision sont exercés contre un vrai
 * PostGIS, chaque test isolé par une transaction annulée (DAMA).
 */
final class SeuilsSupervisionRepositoryTest extends KernelTestCase
{
    private SeuilsSupervisionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilsSupervision::class);
        self::assertInstanceOf(SeuilsSupervisionRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testLesSeuilsCourantsSontLesDefautsTantQueRienNEstConfigure(): void
    {
        $courants = $this->repository->courants();

        self::assertSame(30.0, $courants->erreurMedianeMaxPct());
        self::assertSame(0.70, $courants->gainEcMaxLog());
    }

    public function testDesSeuilsEnregistresDeviennentLesCourants(): void
    {
        $this->repository->enregistrer(SeuilsSupervision::de(25.0, 0.60));

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $courants = $this->repository->courants();
        self::assertSame(25.0, $courants->erreurMedianeMaxPct());
        self::assertSame(0.60, $courants->gainEcMaxLog());
    }

    public function testEnregistrerRemplaceLesSeuilsPrecedents(): void
    {
        $this->repository->enregistrer(SeuilsSupervision::de(25.0, 0.60));
        $this->repository->enregistrer(SeuilsSupervision::de(22.0, 0.50));

        self::assertSame(22.0, $this->repository->courants()->erreurMedianeMaxPct());
    }
}
