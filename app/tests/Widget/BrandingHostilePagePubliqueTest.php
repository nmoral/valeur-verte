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

/**
 * Un branding hostile ne s'exécute pas sur la page publique (#267, D9).
 *
 * La page est hébergée **sur notre origine** : ce que le pro configure y devient
 * du contenu servi par nous. Un nom ou des mentions légales piégés doivent
 * s'afficher **littéralement**, jamais s'exécuter — c'est l'échappement Twig par
 * défaut qui le garantit, et ce test le grave pour que personne n'introduise un
 * `|raw` sur ces valeurs sans faire tomber une porte.
 *
 * Cette barrière-ci est complétée par la CSP ({@see CspWidgetTest}) : la première
 * empêche l'injection, la seconde empêche l'exécution si l'injection passait.
 */
final class BrandingHostilePagePubliqueTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string NOM_PIEGE = '"><script>window.__xss=1</script>';
    private const string MENTIONS_PIEGEES = '<img src=x onerror="window.__xss=1">';

    public function testUnNomPiegeSAfficheLitteralementEtNeSExecutePas(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>window.__xss', $html, 'Le nom ne doit jamais sortir en HTML brut.');
        self::assertSame(
            self::NOM_PIEGE,
            $client->getCrawler()->filter('[data-marque="nom"]')->text(),
            'Le nom s\'affiche tel quel, échappé.',
        );
    }

    public function testUnNomPiegeNeSEchappePasDuTitreNiDeLAttributAlt(): void
    {
        // Le nom est réutilisé dans <title> et dans l'attribut alt du logo : deux
        // contextes où un guillemet mal échappé ouvrirait une balise.
        [$client, $token] = $this->preparer(logoUrl: 'https://cdn.exemple.fr/logo.png');

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertSame(self::NOM_PIEGE, $crawler->filter('title')->text());
        self::assertSame(self::NOM_PIEGE, $crawler->filter('img[data-marque="logo"]')->attr('alt'));
    }

    public function testLaCouleurDeMarqueResteUnCodeHexadecimalDansLAttributStyle(): void
    {
        // La couleur part dans un attribut `style`, avec depuis #290 la couleur de
        // texte qui s'y lit : seuls des #RRGGBB validés y entrent (cf. CouleurTest),
        // donc l'attribut ne contient rien d'autre.
        [$client, $token] = $this->preparer();

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertMatchesRegularExpression(
            '/^--marque:\s*#[0-9A-F]{6};\s*--marque-texte:\s*#[0-9A-F]{6};\s*--marque-encre:\s*#[0-9A-F]{6};?$/',
            trim((string) $crawler->filter('[data-page="widget"]')->attr('style')),
        );
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function preparer(?string $logoUrl = null): array
    {
        $client = static::createClient();
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $branding = Branding::creer(
            Couleur::depuisHex('#B8480F'),
            null,
            null,
            self::MENTIONS_PIEGEES,
            $logoUrl,
            self::NOM_PIEGE,
        );
        $branding->setTenantId($tenantId);
        $em->persist($branding);
        $em->persist(PagePublique::creer($tenantId, $token = bin2hex(random_bytes(5)), 'agence-durand'));
        $em->flush();

        return [$client, $token];
    }
}
