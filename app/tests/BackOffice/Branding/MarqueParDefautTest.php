<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\MarqueParDefaut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Configuration par défaut de la marque (ONB-4) : à la création du compte, une
 * marque par défaut est matérialisée pour le tenant, éditable ensuite sans que
 * le pro parte d'un écran vide.
 */
final class MarqueParDefautTest extends KernelTestCase
{
    private const TENANT = 777100;

    public function testElleMaterialiseLaMarqueParDefautPourLeTenant(): void
    {
        self::bootKernel();
        $configuration = self::getContainer()->get(MarqueParDefaut::class);
        self::assertInstanceOf(MarqueParDefaut::class, $configuration);

        $configuration->provisionner(self::TENANT);

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $marques = $manager->getRepository(Branding::class)->findBy(['tenantId' => self::TENANT]);

        self::assertCount(1, $marques);
        self::assertEquals(Branding::parDefaut()->couleurPrincipale(), $marques[0]->couleurPrincipale());
    }
}
