<?php

declare(strict_types=1);

namespace App\Tests\Sitemap;

use App\Publication\LectureModelePublie;
use App\Publication\Publication;
use App\Sitemap\SitemapGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Le sitemap se construit par **découverte des routes** marquées
 * `options: ['sitemap' => true]` (#340) : ajouter une page publique indexable =
 * marquer sa route, sans toucher au générateur. On teste ici la source des URLs,
 * hors cache HTTP (le contrôleur, lui, mémoïse le rendu). L'état « millésime
 * publié » est stubé — la lecture DBAL a son propre test.
 */
final class SitemapGeneratorTest extends KernelTestCase
{
    private const DOMAINE = 'valeurverte.localhost';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsPublicsIndexables(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
        yield 'CGU' => ['/cgu'];
        yield 'CGV' => ['/cgv'];
        yield 'connexion' => ['/login'];
        yield 'inscription' => ['/inscription'];
    }

    #[DataProvider('cheminsPublicsIndexables')]
    public function testChaquePagePubliqueIndexableEstListeeEnUrlAbsolue(string $chemin): void
    {
        $urls = self::generateur()->urls();

        self::assertContains('https://'.self::DOMAINE.$chemin, $urls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cheminsAExclure(): iterable
    {
        // Jetons signés / usage unique : URL non partageable, expire.
        yield 'vérification email' => ['/verification-email'];
        yield 'désabonnement' => ['/desabonnement'];
        yield 'demande de reset' => ['/mot-de-passe-oublie'];
        yield 'réinitialisation' => ['/mot-de-passe-oublie/reinitialiser'];
        // Endpoint applicatif, pas une page.
        yield 'déconnexion' => ['/logout'];
    }

    #[DataProvider('cheminsAExclure')]
    public function testAucuneRouteNonIndexableNApparait(string $chemin): void
    {
        foreach (self::generateur()->urls() as $url) {
            self::assertStringNotContainsString($chemin, $url, \sprintf('« %s » ne doit pas figurer au sitemap.', $chemin));
        }
    }

    public function testAucuneUrlDeSousDomainePriveOuWidgetNApparait(): void
    {
        foreach (self::generateur()->urls() as $url) {
            self::assertStringNotContainsString('://app.', $url, 'L\'espace pro (ROLE_PRO) ne doit pas être exposé.');
            self::assertStringNotContainsString('://admin.', $url, 'L\'admin interne (ROLE_ADMIN) ne doit pas être exposé.');
            self::assertStringNotContainsString('://widget.', $url, 'Les pages widget sont noindex par nature (D9).');
        }
    }

    public function testLaPagePublicationEstAbsenteSansMillesimePublie(): void
    {
        self::assertNotContains(
            'https://'.self::DOMAINE.'/valeur-verte-par-departement',
            self::generateur(null)->urls(),
            'Aucune URL menant à un 404 (#224) ne doit figurer au sitemap.',
        );
    }

    public function testLaPagePublicationEstPresenteAvecUnMillesimePublie(): void
    {
        $publie = new Publication(
            millesime: 2024,
            publieLe: new \DateTimeImmutable('2026-01-01'),
            calculeLe: new \DateTimeImmutable('2026-01-01'),
        );

        self::assertContains(
            'https://'.self::DOMAINE.'/valeur-verte-par-departement',
            self::generateur($publie)->urls(),
        );
    }

    public function testLaGenerationNAlterePasDurablementLeContexteDuRouteur(): void
    {
        // Le générateur bascule le contexte du routeur sur le domaine canonique
        // (HTTPS) le temps de produire les URLs, puis doit le restaurer : sinon
        // toute génération d'URL ultérieure de la requête partirait sur le mauvais
        // host/scheme.
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $hoteAvant = $router->getContext()->getHost();
        $schemeAvant = $router->getContext()->getScheme();

        self::generateur()->urls();

        self::assertSame($hoteAvant, $router->getContext()->getHost());
        self::assertSame($schemeAvant, $router->getContext()->getScheme());
    }

    public function testLesUrlsSontTrieesPourUnDiffStable(): void
    {
        $urls = self::generateur()->urls();

        $triees = $urls;
        sort($triees);
        self::assertSame($triees, $urls, 'Le sitemap doit être trié, indépendant de l\'ordre de déclaration des routes.');
    }

    private static function generateur(?Publication $publication = null): SitemapGenerator
    {
        self::getContainer()->set(LectureModelePublie::class, self::modele($publication));

        $generateur = self::getContainer()->get(SitemapGenerator::class);
        self::assertInstanceOf(SitemapGenerator::class, $generateur);

        return $generateur;
    }

    private static function modele(?Publication $publication): LectureModelePublie
    {
        return new class($publication) implements LectureModelePublie {
            public function __construct(private ?Publication $publication)
            {
            }

            public function publication(): ?Publication
            {
                return $this->publication;
            }

            public function mailles(): array
            {
                return [];
            }

            public function communesReferencees(): int
            {
                return 0;
            }
        };
    }
}
