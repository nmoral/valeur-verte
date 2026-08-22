<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Kill switch d'une page publique (#268, épic #6).
 *
 * Deux interrupteurs indépendants, qui ne se marchent pas dessus :
 *  - `active` : l'interrupteur du **pro** (#38), il retire sa page quand il veut ;
 *  - `suspendueLe` : le coup d'arrêt de l'**admin interne** (ou de l'escalade
 *    automatique), qui doit tenir même si le pro rebascule son interrupteur.
 *
 * Une page suspendue n'existe plus pour le visiteur : elle ne se résout plus, donc
 * la page publique répond 404 — comme une page inconnue, sans rien révéler.
 */
final class KillSwitchPagePubliqueTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';

    public function testUnePageNeuveNEstPasSuspendue(): void
    {
        self::bootKernel();
        $page = PagePublique::creer($this->nouveauTenant(), $this->jeton(), 'agence-durand');

        self::assertFalse($page->estSuspendue());
    }

    public function testLaSuspensionRetireLaPageDeLaResolution(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        $page->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $this->em()->flush();

        self::assertTrue($page->estSuspendue());
        self::assertNull($this->pages()->trouverActiveParJeton($page->token()));
    }

    public function testLaReactivationRendLaPageAuVisiteur(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        $page->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $page->reactiver();
        $this->em()->flush();

        self::assertFalse($page->estSuspendue());
        self::assertNotNull($this->pages()->trouverActiveParJeton($page->token()));
    }

    /**
     * L'interrupteur du pro et la suspension de l'admin sont deux verrous distincts :
     * réactiver côté admin ne rallume pas une page que le pro a lui-même retirée.
     */
    public function testLaReactivationAdminNeRallumePasUnePageRetireeParLePro(): void
    {
        self::bootKernel();
        $page = $this->creerPage();

        $page->desactiver();
        $page->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $page->reactiver();
        $this->em()->flush();

        self::assertFalse($page->estActive());
        self::assertNull($this->pages()->trouverActiveParJeton($page->token()));
    }

    public function testUnePageSuspendueRepond404AuVisiteur(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $page = $this->creerPage();
        $page->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $this->em()->flush();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$page->token().'/agence-durand');

        self::assertResponseStatusCodeSame(404);
    }

    private function creerPage(): PagePublique
    {
        $page = PagePublique::creer($this->nouveauTenant(), $this->jeton(), 'agence-durand');
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }

    private function nouveauTenant(): int
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        return $tenantId;
    }

    private function jeton(): string
    {
        return bin2hex(random_bytes(5));
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function pages(): PagePubliqueRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new PagePubliqueRepository($registry);
    }
}
