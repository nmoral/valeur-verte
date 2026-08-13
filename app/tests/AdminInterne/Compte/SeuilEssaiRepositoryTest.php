<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Compte;

use App\AdminInterne\Compte\SeuilEssai;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le réglage de seuil est exercé contre un vrai PostGIS
 * (base « valeur_verte_test »), chaque test isolé par une transaction annulée (DAMA).
 */
final class SeuilEssaiRepositoryTest extends KernelTestCase
{
    private SeuilEssaiRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilEssai::class);
        self::assertInstanceOf(SeuilEssaiRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testLeSeuilCourantEstLeDefautTantQueRienNEstConfigure(): void
    {
        self::assertSame(10, $this->repository->courant()->valeur());
    }

    public function testUnSeuilEnregistreDevientLeSeuilCourant(): void
    {
        $this->repository->enregistrer(SeuilEssai::de(25));

        // Vider l'identity map : la relecture vient réellement de la base.
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        self::assertSame(25, $this->repository->courant()->valeur());
    }

    public function testEnregistrerRemplaceLeSeuilPrecedentSansEmpiler(): void
    {
        $this->repository->enregistrer(SeuilEssai::de(25));
        $this->repository->enregistrer(SeuilEssai::de(5));

        self::assertSame(5, $this->repository->courant()->valeur());
    }
}
