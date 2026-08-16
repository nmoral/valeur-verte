<?php

declare(strict_types=1);

namespace App\Tests\Erreur;

use App\Erreur\Twig\AccueilZoneExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * L'extension expose aux gabarits d'erreur l'accueil de la zone courante :
 * l'URL absolue (la zone cible peut vivre sur un autre sous-domaine) et le
 * libellé du lien de retour (#197).
 */
final class AccueilZoneExtensionTest extends TestCase
{
    public function testLUrlDAccueilCibleLaRouteDeLaZoneCourante(): void
    {
        $extension = new AccueilZoneExtension(
            $this->pileAvecHote('admin.valeur-verte.fr'),
            $this->generateurRendant('admin', 'https://admin.valeur-verte.fr/'),
        );

        self::assertSame('https://admin.valeur-verte.fr/', $extension->urlAccueilZone());
    }

    public function testLeLibelleSuitLaZoneCourante(): void
    {
        $extension = new AccueilZoneExtension(
            $this->pileAvecHote('app.valeur-verte.fr'),
            $this->generateurRendant('espace_pro', 'https://app.valeur-verte.fr/'),
        );

        self::assertStringContainsString('espace', $extension->libelleAccueilZone());
    }

    public function testSansRequeteOnRetombeSurLAccueilPublic(): void
    {
        // Hors contexte HTTP (CLI, sous-requête sans requête maître), la page
        // d'erreur doit rester rendable : repli sur l'accueil public.
        $extension = new AccueilZoneExtension(
            new RequestStack(),
            $this->generateurRendant('home', 'https://valeur-verte.fr/'),
        );

        self::assertSame('https://valeur-verte.fr/', $extension->urlAccueilZone());
    }

    public function testLesFonctionsTwigSontExposees(): void
    {
        $extension = new AccueilZoneExtension(new RequestStack(), $this->generateurRendant('home', '/'));

        $noms = array_map(static fn ($f): string => $f->getName(), $extension->getFunctions());

        self::assertContains('url_accueil_zone', $noms);
        self::assertContains('libelle_accueil_zone', $noms);
    }

    private function pileAvecHote(string $hote): RequestStack
    {
        $pile = new RequestStack();
        $pile->push(Request::create('https://'.$hote.'/'));

        return $pile;
    }

    private function generateurRendant(string $routeAttendue, string $url): UrlGeneratorInterface
    {
        $generateur = $this->createMock(UrlGeneratorInterface::class);
        $generateur->method('generate')
            ->with($routeAttendue, [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn($url);

        return $generateur;
    }
}
