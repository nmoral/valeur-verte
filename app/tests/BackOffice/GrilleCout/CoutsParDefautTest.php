<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\GrilleCout;

use App\BackOffice\GrilleCout\CoutsParDefaut;
use App\BackOffice\GrilleCout\GrilleCoutEnregistree;
use App\BackOffice\GrilleCout\Poste;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Configuration par défaut de la grille de coûts (#34, ONB-3) : à la création du
 * compte, la grille nationale par défaut est matérialisée pour le tenant.
 */
final class CoutsParDefautTest extends KernelTestCase
{
    private const TENANT = 778100;

    public function testElleMaterialiseLaGrilleNationalePourLeTenant(): void
    {
        self::bootKernel();
        $configuration = self::getContainer()->get(CoutsParDefaut::class);
        self::assertInstanceOf(CoutsParDefaut::class, $configuration);

        $configuration->provisionner(self::TENANT);

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $grilles = $manager->getRepository(GrilleCoutEnregistree::class)->findBy(['tenantId' => self::TENANT]);

        self::assertCount(1, $grilles);
        self::assertSame(120, $grilles[0]->versGrille()->montant(Poste::Murs)->euros());
    }
}
