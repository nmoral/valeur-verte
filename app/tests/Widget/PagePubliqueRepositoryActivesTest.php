<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `PagePubliqueRepository::actives()` — la source d'URLs marque blanche de l'audit
 * SEO (#345). Le crawler tourne en CLI (aucun pro connecté, filtre de cloisonnement
 * éteint) : la méthode doit rendre les pages actives de **tous** les tenants, et
 * écarter celles retirées par le pro ou suspendues par l'admin.
 */
final class PagePubliqueRepositoryActivesTest extends KernelTestCase
{
    public function testNeRendQueLesPagesServiesAuVisiteur(): void
    {
        self::bootKernel();

        $active = $this->creer('active-durand');

        $retiree = $this->creer('retiree');
        $retiree->desactiver();

        $suspendue = $this->creer('suspendue');
        $suspendue->suspendre(new \DateTimeImmutable('2026-08-25 10:00:00'));

        $this->em()->flush();

        $tokens = array_map(static fn (PagePublique $p): string => $p->token(), $this->pages()->actives());

        self::assertContains($active->token(), $tokens);
        self::assertNotContains($retiree->token(), $tokens);
        self::assertNotContains($suspendue->token(), $tokens);
    }

    private function creer(string $slug): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), $slug);
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function pages(): PagePubliqueRepository
    {
        $pages = static::getContainer()->get(PagePubliqueRepository::class);
        self::assertInstanceOf(PagePubliqueRepository::class, $pages);

        return $pages;
    }
}
