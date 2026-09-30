<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\MarqueursDbal;
use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture des marqueurs territoriaux depuis `data.marqueurs` (#341, Lot 4).
 *
 * L'app est en lecture seule sur le schéma `data` : le lecteur agrège les lignes
 * en une vue `Marqueurs` (map nom → valeur). Pour une commune, on remonte ses
 * marqueurs communaux ET ceux de son département, le communal l'emportant en cas
 * de même nom (variation locale, anti-duplicate #341).
 */
final class MarqueursDbalTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testPourCommuneAgregeCommunalEtDepartemental(): void
    {
        $this->semerMarqueur(nom: 'densite', valeur: 'rural', codeInsee: '55182');
        $this->semerMarqueur(nom: 'levier_renovation', valeur: 'fort', typeMarqueur: 'mesure', codeInsee: '55182');
        $this->semerMarqueur(nom: 'climat', valeur: 'froid', codeDepartement: '55');

        $marqueurs = (new MarqueursDbal($this->app()))->pourCommune('55182', '55');

        self::assertSame('rural', $marqueurs->valeur('densite'));
        self::assertSame('fort', $marqueurs->valeur('levier_renovation'));
        self::assertSame('froid', $marqueurs->valeur('climat')); // hérité du département
    }

    public function testLeMarqueurCommunalEcraseLeDepartemental(): void
    {
        $this->semerMarqueur(nom: 'marche', valeur: 'detendu', codeDepartement: '55');
        $this->semerMarqueur(nom: 'marche', valeur: 'tendu', codeInsee: '55182');

        $marqueurs = (new MarqueursDbal($this->app()))->pourCommune('55182', '55');

        self::assertSame('tendu', $marqueurs->valeur('marche'));
    }

    public function testPourDepartementNePrendQueLeNiveauDepartement(): void
    {
        $this->semerMarqueur(nom: 'climat', valeur: 'froid', codeDepartement: '55');
        $this->semerMarqueur(nom: 'densite', valeur: 'rural', codeInsee: '55182');

        $marqueurs = (new MarqueursDbal($this->app()))->pourDepartement('55');

        self::assertSame('froid', $marqueurs->valeur('climat'));
        self::assertNull($marqueurs->valeur('densite')); // marqueur communal ignoré
    }
}
