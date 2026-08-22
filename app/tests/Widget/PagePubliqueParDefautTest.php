<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueParDefaut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Page publique par défaut (#38, épic #6, ONB-4).
 *
 * Le pro ne crée pas sa page : elle existe dès la création du compte, avec son
 * jeton, prête à être partagée. Le principe « un rapport avant toute
 * configuration » vaut ici aussi — il n'y a rien à demander à un pro pour lui
 * ouvrir une adresse publique, et un écran vide n'apprendrait rien.
 */
final class PagePubliqueParDefautTest extends KernelTestCase
{
    public function testElleOuvreUnePageActiveAuTenantDesLaCreationDuCompte(): void
    {
        self::bootKernel();
        $tenantId = $this->nouveauTenant();

        $this->configuration()->provisionner($tenantId);

        $pages = $this->pagesDuTenant($tenantId);
        self::assertCount(1, $pages);
        self::assertTrue($pages[0]->estActive(), 'La page est ouverte, pas en attente d\'un geste du pro.');
        self::assertFalse($pages[0]->estSuspendue());
    }

    public function testLaPageNaitAvecUnJetonOpaque(): void
    {
        self::bootKernel();
        $tenantId = $this->nouveauTenant();

        $this->configuration()->provisionner($tenantId);

        $page = $this->pagesDuTenant($tenantId)[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $page->token());
    }

    public function testDeuxComptesNObtiennentPasLeMemeJeton(): void
    {
        self::bootKernel();
        $premier = $this->nouveauTenant();
        $second = $this->nouveauTenant();

        $this->configuration()->provisionner($premier);
        $this->configuration()->provisionner($second);

        self::assertNotSame(
            $this->pagesDuTenant($premier)[0]->token(),
            $this->pagesDuTenant($second)[0]->token(),
        );
    }

    public function testLeSlugParDefautEstLisibleDansUneUrl(): void
    {
        // Le slug n'entre pas dans la résolution, mais il s'affiche dans le lien
        // partagé : il doit rester présentable tant que le pro ne l'a pas changé.
        self::bootKernel();
        $tenantId = $this->nouveauTenant();

        $this->configuration()->provisionner($tenantId);

        $slug = $this->pagesDuTenant($tenantId)[0]->slug();
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    private function configuration(): PagePubliqueParDefaut
    {
        $configuration = static::getContainer()->get(PagePubliqueParDefaut::class);
        self::assertInstanceOf(PagePubliqueParDefaut::class, $configuration);

        return $configuration;
    }

    /**
     * @return list<PagePublique>
     */
    private function pagesDuTenant(int $tenantId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->getRepository(PagePublique::class)->findBy(['tenantId' => $tenantId]);
    }

    private function nouveauTenant(): int
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        return $tenantId;
    }
}
