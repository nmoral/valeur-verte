<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\CollecteurUrls;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le périmètre d'URLs de l'audit SEO (#345) : pages canoniques du sitemap +
 * pages hébergées marque blanche du widget (routes à jeton, absentes du sitemap).
 */
final class CollecteurUrlsTest extends KernelTestCase
{
    public function testCombineSitemapEtPagesWidgetActives(): void
    {
        self::bootKernel();

        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $token = bin2hex(random_bytes(5));
        $page = PagePublique::creer($tenants->nouveau()->id(), $token, 'agence-durand');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist($page);
        $em->flush();

        $collecteur = static::getContainer()->get(CollecteurUrls::class);
        self::assertInstanceOf(CollecteurUrls::class, $collecteur);
        $urls = $collecteur->urls();

        // Au moins une page canonique du sitemap (la home est marquée sitemap).
        self::assertNotEmpty($urls);
        self::assertContains('https://valeurverte.localhost/', $urls);

        // La page widget active, en URL absolue sur le sous-domaine widget.
        $widget = array_filter($urls, static fn (string $u): bool => str_contains($u, $token));
        self::assertCount(1, $widget);
        self::assertStringStartsWith('https://widget.valeurverte.localhost/'.$token.'/agence-durand', array_values($widget)[0]);
    }
}
