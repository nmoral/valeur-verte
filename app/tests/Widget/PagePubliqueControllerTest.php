<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * La page publique hébergée d'une entreprise (#40, épic #6, décision D9).
 *
 * Servie sur le sous-domaine `widget.` en `/{token}/{slug}`, sans authentification :
 * le token opaque en chemin identifie le tenant propriétaire (le cloisonnement tenant
 * est levé faute de pro connecté, ce qui autorise la résolution par token). Le slug
 * n'est qu'un habillage décoratif — libre, non unique, sans effet sur la résolution.
 * La page revêt la marque du pro (nom, couleur, logo) ; le parcours d'estimation
 * viendra en #41.
 */
final class PagePubliqueControllerTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string HOTE_APP = 'app.valeurverte.localhost';

    public function testUnJetonInconnuNExistePas(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/jetonfantome/agence-durand');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaPageDUnePageActiveEstAccessibleSansAuthentification(): void
    {
        $client = static::createClient();
        $token = $this->creerPage($client, slug: 'agence-durand');

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
    }

    public function testUnePageDesactiveeNExistePas(): void
    {
        $client = static::createClient();
        $token = $this->creerPage($client, slug: 'agence-durand', active: false);

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeSlugEstDecoratifEtNInfluePasSurLaResolution(): void
    {
        // Le slug est un simple habillage : un slug qui ne correspond pas à celui
        // enregistré résout quand même la page (c'est le token qui fait foi).
        $client = static::createClient();
        $token = $this->creerPage($client, slug: 'agence-durand');

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/n-importe-quel-libelle');

        self::assertResponseIsSuccessful();
    }

    public function testDeuxPagesPeuventPartagerLeMemeSlug(): void
    {
        // Plus de contrainte d'unicité sur le slug : deux pros peuvent choisir le
        // même libellé, le token les distingue sans collision.
        $client = static::createClient();
        $token1 = $this->creerPage($client, slug: 'estimation');
        $token2 = $this->creerPage($client, slug: 'estimation');

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token1.'/estimation');
        self::assertResponseIsSuccessful();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token2.'/estimation');
        self::assertResponseIsSuccessful();
    }

    public function testLaPageRevetLeNomDuPro(): void
    {
        $crawler = $this->afficher(slug: 'renov-toulouse', nom: 'Rénov Toulouse');

        self::assertStringContainsString(
            'Rénov Toulouse',
            $crawler->filter('[data-marque="nom"]')->text(),
        );
    }

    public function testLaPageAppliqueLaCouleurDeMarque(): void
    {
        $crawler = $this->afficher(slug: 'agence-durand', couleur: '#B8480F');

        self::assertStringContainsString(
            '#B8480F',
            (string) $crawler->filter('[data-page="widget"]')->attr('style'),
            'La couleur de marque doit habiller la page (variable CSS).',
        );
    }

    public function testLaPageAfficheLeLogoQuandIlEstConfigure(): void
    {
        $crawler = $this->afficher(slug: 'agence-durand', logoUrl: 'https://cdn.exemple.fr/logo.png');

        self::assertSame(
            'https://cdn.exemple.fr/logo.png',
            $crawler->filter('img[data-marque="logo"]')->attr('src'),
        );
    }

    public function testLaMarqueParDefautSAppliqueQuandRienNestConfigure(): void
    {
        // Un pro peut publier sa page avant d'avoir personnalisé sa marque (ONB-4) :
        // la page doit rester servie, avec la couleur par défaut, sans erreur.
        $client = static::createClient();
        $token = $this->creerPage($client, slug: 'agence-durand', avecBranding: false);

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '#0E4A5C',
            (string) $crawler->filter('[data-page="widget"]')->attr('style'),
        );
    }

    public function testLaPageNestServieQueSurLeSousDomaineWidget(): void
    {
        // La même URL, demandée sur le domaine applicatif, n'existe pas : la route
        // est liée au host `widget.` (D9), pas de fuite hors de sa zone.
        $client = static::createClient();
        $token = $this->creerPage($client, slug: 'agence-durand');

        $client->request('GET', 'http://'.self::HOTE_APP.'/'.$token.'/agence-durand');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaPageNestPasIndexable(): void
    {
        // Page de génération de lead propre à un pro : on ne la laisse pas indexer
        // (réduit aussi la surface de découverte pour le scraping, épic #6).
        $crawler = $this->afficher(slug: 'agence-durand');

        self::assertStringContainsString(
            'noindex',
            (string) $crawler->filter('meta[name="robots"]')->attr('content'),
        );
    }

    private function afficher(
        string $slug,
        string $couleur = '#0E4A5C',
        ?string $nom = 'Agence Durand',
        ?string $logoUrl = null,
    ): Crawler {
        $client = static::createClient();
        $token = $this->creerPage($client, slug: $slug, couleur: $couleur, nom: $nom, logoUrl: $logoUrl);

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/'.$slug);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function creerPage(
        KernelBrowser $client,
        string $slug,
        bool $active = true,
        string $couleur = '#0E4A5C',
        ?string $nom = 'Agence Durand',
        ?string $logoUrl = null,
        bool $avecBranding = true,
    ): string {
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenant = $tenants->nouveau();
        $tenantId = $tenant->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        if ($avecBranding) {
            $branding = Branding::creer(Couleur::depuisHex($couleur), null, null, null, $logoUrl, $nom);
            $branding->setTenantId($tenantId);
            $em->persist($branding);
        }

        $token = bin2hex(random_bytes(5));
        $page = PagePublique::creer($tenantId, $token, $slug);
        if (!$active) {
            $page->desactiver();
        }
        $em->persist($page);
        $em->flush();

        return $token;
    }
}
