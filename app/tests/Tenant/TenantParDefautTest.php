<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Security\CreateurDeCompte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantParDefautTest extends WebTestCase
{
    public function testLInscriptionProvisionneUnTenantUnique(): void
    {
        $client = static::createClient();
        $createur = $this->createur();

        $premier = $createur->creer('pro-a@example.com', 'motdepasse-solide', '2026-08-16');
        $second = $createur->creer('pro-b@example.com', 'motdepasse-solide', '2026-08-16');

        self::assertNotNull($premier->getTenantId(), 'Chaque compte doit être rattaché à un tenant.');
        self::assertNotNull($second->getTenantId());
        self::assertNotSame($premier->getTenantId(), $second->getTenantId());
    }

    public function testUnProConnecteActiveLeCloisonnementSurSonTenant(): void
    {
        $client = static::createClient();
        $pro = $this->createur()->creer('pro@example.com', 'motdepasse-solide', '2026-08-16');
        $client->loginUser($pro);

        $client->request('GET', 'http://app.valeurverte.localhost/');

        self::assertResponseIsSuccessful();
        $filtres = $this->entityManager()->getFilters();
        self::assertTrue($filtres->isEnabled('tenant'), 'Le cloisonnement doit être actif par défaut pour un pro connecté.');
        self::assertTrue($filtres->getFilter('tenant')->hasParameter('tenant_id'), 'Le tenant courant doit être posé sur le filtre.');
    }

    private function createur(): CreateurDeCompte
    {
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);

        return $createur;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
