<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\BackOffice\Bouquet\BouquetEnregistre;
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

        $bouquets = $this->entityManager()->getRepository(BouquetEnregistre::class)->findBy(['tenantId' => $tenantId]);
        self::assertCount(3, $bouquets, 'Le compte doit recevoir les trois bouquets types par défaut.');
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
