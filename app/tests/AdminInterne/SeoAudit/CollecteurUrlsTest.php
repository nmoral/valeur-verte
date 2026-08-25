<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\CollecteurUrls;
use App\Sitemap\SitemapGenerator;
use App\Tenant\TenantRepository;
use App\Tests\Publication\ProvisionneModelePublie;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le périmètre d'URLs de l'audit SEO (#345) : pages canoniques du sitemap +
 * pages hébergées marque blanche du widget (routes à jeton, absentes du sitemap).
 */
final class CollecteurUrlsTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testCombineSitemapEtPagesWidgetActives(): void
    {
        self::bootKernel();
        $this->prive(); // provisionne le schéma « data » lu par le sitemap.

        $tenants = $this->service(TenantRepository::class);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);
        $token = bin2hex(random_bytes(5));
        $page = PagePublique::creer($tenantId, $token, 'agence-durand');
        $em = $this->service(EntityManagerInterface::class);
        $em->persist($page);
        $em->flush();

        $domaine = static::getContainer()->getParameter('app.domaine');
        self::assertIsString($domaine);

        $sitemap = $this->service(SitemapGenerator::class);
        $collecteur = new CollecteurUrls(
            $sitemap,
            $this->service(PagePubliqueRepository::class),
            $this->service(RouterInterface::class),
            $domaine,
        );
        $urls = $collecteur->urls();

        // Toutes les URLs canoniques du sitemap sont reprises…
        $canoniques = $sitemap->urls();
        foreach ($canoniques as $canonique) {
            self::assertContains($canonique, $urls);
        }

        // …plus la page widget active, en URL absolue sur le sous-domaine widget.
        $widget = array_values(array_filter($urls, static fn (string $u): bool => str_contains($u, $token)));
        self::assertCount(1, $widget);
        self::assertStringStartsWith('https://widget.'.$domaine.'/'.$token.'/agence-durand', $widget[0]);
        self::assertGreaterThan(\count($canoniques), \count($urls));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function service(string $classe): object
    {
        $service = static::getContainer()->get($classe);
        self::assertInstanceOf($classe, $service);

        return $service;
    }
}
