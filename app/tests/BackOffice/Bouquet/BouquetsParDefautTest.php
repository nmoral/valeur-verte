<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\BouquetEnregistre;
use App\BackOffice\Bouquet\BouquetsParDefaut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Configuration par défaut des bouquets (#35, ONB-3) : à la création du compte,
 * les trois bouquets types sont matérialisés pour le tenant — le pro dispose
 * d'une vraie configuration éditable, sans rien avoir à faire.
 */
final class BouquetsParDefautTest extends KernelTestCase
{
    private const TENANT = 777001;

    public function testElleMaterialiseLesTroisBouquetsTypesPourLeTenant(): void
    {
        self::bootKernel();
        $configuration = self::getContainer()->get(BouquetsParDefaut::class);
        self::assertInstanceOf(BouquetsParDefaut::class, $configuration);

        $configuration->provisionner(self::TENANT);

        $noms = array_map(
            static fn (BouquetEnregistre $b): string => $b->nom(),
            $this->bouquetsDuTenant(self::TENANT),
        );
        self::assertEqualsCanonicalizing(
            ["Rénovation d'ampleur standard", 'Isolation seule', 'Chauffage seul'],
            $noms,
        );
    }

    /**
     * @return list<BouquetEnregistre>
     */
    private function bouquetsDuTenant(int $tenantId): array
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getRepository(BouquetEnregistre::class)->findBy(['tenantId' => $tenantId]);
    }
}
