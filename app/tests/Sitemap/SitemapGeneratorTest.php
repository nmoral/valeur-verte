<?php

declare(strict_types=1);

namespace App\Tests\Sitemap;

use App\Publication\LectureModelePublie;
use App\Publication\Maille;
use App\Publication\Publication;
use App\Sitemap\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le sitemap se construit par **découverte des routes** marquées
 * `options: ['sitemap' => true]` (#340) : ajouter une page publique indexable =
 * marquer sa route, sans toucher au générateur. On teste ici la source des URLs,
 * hors cache HTTP (le contrôleur, lui, mémoïse le rendu).
 */
final class SitemapGeneratorTest extends KernelTestCase
{
    private const DOMAINE = 'valeurverte.localhost';

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

    /**
     * @dataProvider cheminsPublicsIndexables
     */
    public function testChaquePagePubliqueIndexableEstListeeEnUrlAbsolue(string $chemin): void
    {
        self::bootKernel();

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

    /**
     * @dataProvider cheminsAExclure
     */
    public function testAucuneRouteNonIndexableNApparait(string $chemin): void
    {
        self::bootKernel();

        foreach (self::generateur()->urls() as $url) {
            self::assertStringNotContainsString($chemin, $url, \sprintf('« %s » ne doit pas figurer au sitemap.', $chemin));
        }
    }

    public function testAucuneUrlDeSousDomainePriveOuWidgetNApparait(): void
    {
        self::bootKernel();

        foreach (self::generateur()->urls() as $url) {
            self::assertStringNotContainsString('://app.', $url, 'L\'espace pro (ROLE_PRO) ne doit pas être exposé.');
            self::assertStringNotContainsString('://admin.', $url, 'L\'admin interne (ROLE_ADMIN) ne doit pas être exposé.');
            self::assertStringNotContainsString('://widget.', $url, 'Les pages widget sont noindex par nature (D9).');
        }
    }

    public function testLaPagePublicationEstAbsenteSansMillesimePublie(): void
    {
        self::bootKernel();
        self::getContainer()->set(LectureModelePublie::class, self::modeleSansPublication());

        self::assertNotContains(
            'https://'.self::DOMAINE.'/valeur-verte-par-departement',
            self::generateur()->urls(),
            'Aucune URL menant à un 404 (#224) ne doit figurer au sitemap.',
        );
    }

    public function testLaPagePublicationEstPresenteAvecUnMillesimePublie(): void
    {
        self::bootKernel();
        self::getContainer()->set(LectureModelePublie::class, self::modeleAvecPublication());

        self::assertContains(
            'https://'.self::DOMAINE.'/valeur-verte-par-departement',
            self::generateur()->urls(),
        );
    }

    private static function generateur(): SitemapGenerator
    {
        return self::getContainer()->get(SitemapGenerator::class);
    }

    private static function modeleSansPublication(): LectureModelePublie
    {
        return new class implements LectureModelePublie {
            public function publication(): ?Publication
            {
                return null;
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

    private static function modeleAvecPublication(): LectureModelePublie
    {
        return new class implements LectureModelePublie {
            public function publication(): ?Publication
            {
                return new Publication(
                    millesime: 2024,
                    publieLe: new \DateTimeImmutable('2026-01-01'),
                    calculeLe: new \DateTimeImmutable('2026-01-01'),
                );
            }

            /**
             * @return list<Maille>
             */
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
