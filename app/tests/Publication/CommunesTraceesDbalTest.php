<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\CommunesTraceesDbal;
use App\Publication\CommuneTracee;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lecture des contours communaux d'un département (#392) dans le schéma « data »
 * (lecture seule, décision D6) : une jointure ``commune_contour`` ⋈ ``commune`` ⋈
 * ``coefficient_maille``, la géométrie PostGIS servie en GeoJSON puis parsée en
 * anneaux lon/lat pour la projection.
 */
final class CommunesTraceesDbalTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    private const CARRE_31001 = '{"type":"Polygon","coordinates":[[[1.0,43.0],[1.1,43.0],[1.1,43.1],[1.0,43.1],[1.0,43.0]]]}';
    private const CARRE_31002 = '{"type":"Polygon","coordinates":[[[1.2,43.0],[1.3,43.0],[1.3,43.1],[1.2,43.1],[1.2,43.0]]]}';
    private const CARRE_09001 = '{"type":"Polygon","coordinates":[[[1.6,42.9],[1.7,42.9],[1.7,43.0],[1.6,43.0],[1.6,42.9]]]}';

    public function testNeRemonteQueLesCommunesDuDepartementDemande(): void
    {
        $this->semerTerritoire();

        $traces = $this->lecteur()->pour('31');

        self::assertSame(['31001', '31002'], array_map(
            static fn (CommuneTracee $c): string => $c->codeInsee,
            $traces,
        ));
    }

    public function testChaqueContourEstParseEnAnneauxLonLat(): void
    {
        $this->semerTerritoire();

        $toulouse = $this->lecteur()->pour('31')[0];

        self::assertSame('31001', $toulouse->codeInsee);
        self::assertSame('Toulouse', $toulouse->nom);
        self::assertNotEmpty($toulouse->anneaux, 'La commune porte au moins un anneau.');
        $anneau = $toulouse->anneaux[0];
        self::assertGreaterThanOrEqual(4, \count($anneau), 'Un anneau de polygone a au moins quatre sommets.');
        // Le carré semé (lon 1,0→1,1 ; lat 43,0→43,1) revient intact en anneaux lon/lat.
        $sommets = array_map(
            static fn (array $p): array => [round($p[0], 4), round($p[1], 4)],
            $anneau,
        );
        self::assertContains([1.0, 43.0], $sommets);
        self::assertContains([1.1, 43.1], $sommets);
    }

    public function testUneCommuneMesureePorteSaMaille(): void
    {
        $this->semerTerritoire();

        // 31001 est publiée (gain E→C = +35 %) ; 31002 a un contour mais pas de maille.
        $traces = $this->lecteur()->pour('31');

        self::assertNotNull($traces[0]->maille);
        self::assertSame(35, $traces[0]->maille->gain('E', 'C'));
        self::assertNull($traces[1]->maille, 'Sans coefficient publié, la maille est absente.');
    }

    public function testUneCommuneSansReferentielGardeSonCodeCommeNom(): void
    {
        // Contour présent mais aucune ligne data.commune : le nom retombe sur le code.
        $this->semerContourCommune('31003', '31', self::CARRE_31002);

        $traces = $this->lecteur()->pour('31');

        $blagnac = array_values(array_filter(
            $traces,
            static fn (CommuneTracee $c): bool => '31003' === $c->codeInsee,
        ))[0];
        self::assertSame('31003', $blagnac->nom);
    }

    public function testUnDepartementSansContourEstVide(): void
    {
        $this->semerTerritoire();

        self::assertSame([], $this->lecteur()->pour('75'));
    }

    private function lecteur(): CommunesTraceesDbal
    {
        // Construit sur la connexion « app » (lecture seule sur data), celle du runtime.
        return new CommunesTraceesDbal($this->app());
    }

    private function semerTerritoire(): void
    {
        $this->prive();
        $this->semerContourCommune('31001', '31', self::CARRE_31001);
        $this->semerContourCommune('31002', '31', self::CARRE_31002);
        $this->semerContourCommune('09001', '09', self::CARRE_09001);
        $this->semerCommune('31001', 'Toulouse', '31');
        $this->semerCommune('31002', 'Muret', '31');
        $this->semerCommune('09001', 'Foix', '09');
        $this->semerMaille('31001', coefDpe: '{"D":0.0,"C":0.11,"E":-0.19}');
    }
}
