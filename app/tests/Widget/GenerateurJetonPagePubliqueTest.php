<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Tests\Widget\Double\TirageJetonScripte;
use App\Widget\GenerateurJetonPagePublique;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use App\Widget\TirageJetonAleatoire;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Génération du jeton opaque d'une page publique (#38, épic #6).
 *
 * Le jeton **est** l'adresse de la page : il désigne le tenant à un visiteur sans
 * session, sur une URL ouvertement accessible. Il doit donc être non énumérable
 * (tiré au hasard, pas dérivé d'un identifiant) et unique — l'unicité se vérifie
 * **tous tenants confondus**, sinon deux pros pourraient viser le même jeton et la
 * contrainte d'unicité de la base trancherait par une erreur 500.
 */
final class GenerateurJetonPagePubliqueTest extends KernelTestCase
{
    public function testLeJetonEstTireAuHasardEtRemplitLaColonne(): void
    {
        self::bootKernel();

        $jeton = $this->generateur()->nouveau();

        // 32 caractères hexadécimaux = 16 octets d'entropie, la largeur exacte de
        // la colonne : non énumérable, et rien n'est tronqué à l'insertion.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $jeton);
    }

    public function testDeuxAppelsNeDonnentPasLeMemeJeton(): void
    {
        self::bootKernel();
        $generateur = $this->generateur();

        self::assertNotSame($generateur->nouveau(), $generateur->nouveau());
    }

    public function testUnJetonDejaPrisEstEcarteAuProfitDuTirageSuivant(): void
    {
        self::bootKernel();
        $pris = $this->jetonLibre();
        $this->creerPage($this->nouveauTenant(), $pris);

        $libre = $this->jetonLibre();
        $jeton = $this->generateur(new TirageJetonScripte([$pris, $libre]))->nouveau();

        self::assertSame($libre, $jeton);
    }

    public function testUnJetonPrisParUnAutreTenantResteUnJetonPris(): void
    {
        // Le pro est cloisonné : sans levée du filtre, la page de l'autre tenant
        // serait invisible, le jeton passerait pour libre et l'insertion casserait.
        self::bootKernel();
        $pris = $this->jetonLibre();
        $autreTenant = $this->nouveauTenant();
        $this->creerPage($autreTenant, $pris);

        $this->cloisonnerSur($this->nouveauTenant());
        $libre = $this->jetonLibre();
        $jeton = $this->generateur(new TirageJetonScripte([$pris, $libre]))->nouveau();

        self::assertSame($libre, $jeton);
    }

    public function testDesCollisionsInterminablesEchouentAuLieuDeBoucler(): void
    {
        self::bootKernel();
        $pris = $this->jetonLibre();
        $this->creerPage($this->nouveauTenant(), $pris);

        $this->expectException(\RuntimeException::class);

        $this->generateur(new TirageJetonScripte(array_fill(0, 50, $pris)))->nouveau();
    }

    private function generateur(?TirageJetonScripte $tirage = null): GenerateurJetonPagePublique
    {
        return new GenerateurJetonPagePublique(
            $this->pages(),
            $tirage ?? new TirageJetonAleatoire(),
        );
    }

    private function creerPage(int $tenantId, string $jeton): void
    {
        $em = $this->em();
        $em->persist(PagePublique::creer($tenantId, $jeton, 'agence-durand'));
        $em->flush();
    }

    private function cloisonnerSur(int $tenantId): void
    {
        $this->em()->getFilters()
            ->enable('tenant')
            ->setParameter('tenant_id', $tenantId, Types::INTEGER);
    }

    private function jetonLibre(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function nouveauTenant(): int
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        return $tenantId;
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
