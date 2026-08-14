<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\BackOffice\Bouquet\BouquetEnregistre;
use App\BackOffice\Branding\Branding;
use App\BackOffice\GrilleCout\GrilleCoutEnregistree;
use App\Security\CreateurDeCompte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * À la création d'un compte, la configuration par défaut du tenant est
 * provisionnée automatiquement — le pro n'a rien à faire. On vérifie le bout de
 * la chaîne : après inscription, les bouquets types existent pour son tenant.
 */
final class ProvisionnementCompteTest extends WebTestCase
{
    public function testUnCompteCreeRecoitSaConfigurationParDefaut(): void
    {
        $client = static::createClient();
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);

        $user = $createur->creer('provision@example.com', 'motdepasse-solide');
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        $manager = $this->entityManager();
        $bouquets = $manager->getRepository(BouquetEnregistre::class)->findBy(['tenantId' => $tenantId]);
        self::assertCount(3, $bouquets, 'Le compte doit recevoir les trois bouquets types par défaut.');

        $marques = $manager->getRepository(Branding::class)->findBy(['tenantId' => $tenantId]);
        self::assertCount(1, $marques, 'Le compte doit recevoir une marque par défaut.');

        $grilles = $manager->getRepository(GrilleCoutEnregistree::class)->findBy(['tenantId' => $tenantId]);
        self::assertCount(1, $grilles, 'Le compte doit recevoir une grille de coûts par défaut.');
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
