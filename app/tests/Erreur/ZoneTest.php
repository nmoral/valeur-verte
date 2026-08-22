<?php

declare(strict_types=1);

namespace App\Tests\Erreur;

use App\Erreur\Zone;
use PHPUnit\Framework\TestCase;

/**
 * La zone courante se déduit du préfixe d'hôte (sous-domaine) et porte, pour la
 * page d'erreur, la route d'accueil vers laquelle proposer le retour et son
 * libellé. Le retour reste ainsi dans la zone où l'erreur s'est produite (#197).
 */
final class ZoneTest extends TestCase
{
    public function testLHoteAdminMeneAAccueilAdmin(): void
    {
        $zone = Zone::depuisHote('admin.valeur-verte.fr');

        self::assertSame('admin', $zone->route());
        self::assertStringContainsString('administration', $zone->libelle());
    }

    public function testLHoteAppMeneAEspacePro(): void
    {
        $zone = Zone::depuisHote('app.valeur-verte.fr');

        self::assertSame('espace_pro', $zone->route());
        self::assertStringContainsString('espace', $zone->libelle());
    }

    public function testLHoteApexMeneALAccueilPublic(): void
    {
        $zone = Zone::depuisHote('valeur-verte.fr');

        self::assertSame('home', $zone->route());
        self::assertStringContainsString('accueil', $zone->libelle());
    }

    public function testLHoteWidgetRetombeSurLAccueilPublic(): void
    {
        // La zone widget (les pages publiques hébergées, D9) n'a pas d'accueil propre
        // — on n'y entre que par le lien d'un pro : elle retombe sur l'accueil public
        // plutôt que de proposer un retour cassé.
        $zone = Zone::depuisHote('widget.valeur-verte.fr');

        self::assertSame('home', $zone->route());
    }

    public function testLHoteInconnuRetombeSurLAccueilPublic(): void
    {
        $zone = Zone::depuisHote('inconnu.exemple.test');

        self::assertSame('home', $zone->route());
    }

    public function testLaCasseDeLHoteEstIgnoree(): void
    {
        self::assertSame('admin', Zone::depuisHote('ADMIN.valeur-verte.fr')->route());
    }
}
