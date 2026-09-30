<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\PourquoiIci\FournisseurMarqueurs;
use App\PourquoiIci\Marqueurs;
use App\PourquoiIci\MoteurPourquoiIci;
use App\PourquoiIci\Phrase;
use App\Publication\FichesDepartementales;
use App\Publication\LectureModelePublie;
use App\Publication\Maille;
use App\Publication\Publication;
use PHPUnit\Framework\TestCase;

/**
 * Lecture ciblée du modèle publié pour les hubs départementaux (#337). On stube la
 * lecture DBAL (testée ailleurs) pour exercer la logique 404 : pas de millésime, ou
 * département non mesuré.
 */
final class FichesDepartementalesTest extends TestCase
{
    public function testSansMillesimePublieAucunDepartementNEstMesure(): void
    {
        // Des mailles existent, mais rien n'est publié : la liste doit rester vide
        // (on ne sert jamais un millésime non publié, REG-2).
        $fiches = new FichesDepartementales($this->lecture(null, [new Maille('33001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19])]), $this->marqueursDept([]), MoteurPourquoiIci::parDefaut());

        self::assertSame([], $fiches->codesMesures());
    }

    public function testSansMillesimePublieLaFicheEstNulle(): void
    {
        $fiches = new FichesDepartementales($this->lecture(null, [new Maille('33001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19])]), $this->marqueursDept([]), MoteurPourquoiIci::parDefaut());

        self::assertNull($fiches->pour('33'));
    }

    public function testLesCodesMesuresSontCeuxDesMaillesPubliees(): void
    {
        $fiches = new FichesDepartementales($this->lecture($this->publie(), [
            new Maille('33001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
            new Maille('09001', 'departemental', ['D' => 0.0, 'C' => 0.05, 'E' => -0.05]),
        ]), $this->marqueursDept([]), MoteurPourquoiIci::parDefaut());

        self::assertSame(['09', '33'], $fiches->codesMesures());
    }

    public function testLaFicheDUnDepartementMesurePorteSesChiffres(): void
    {
        $fiches = new FichesDepartementales($this->lecture($this->publie(), [
            new Maille('33001', 'local', ['D' => 0.0, 'C' => 0.11, 'B' => 0.19, 'E' => -0.19]),
            new Maille('33002', 'local', ['D' => 0.0, 'C' => 0.11, 'B' => 0.19, 'E' => -0.19]),
        ]), $this->marqueursDept([]), MoteurPourquoiIci::parDefaut());

        $fiche = $fiches->pour('33');

        self::assertNotNull($fiche);
        self::assertSame(2025, $fiche->millesime);
        self::assertSame('33', $fiche->departement->code);
        self::assertSame('Gironde', $fiche->departement->nom);
        self::assertSame(35, $fiche->departement->gainEc);
        self::assertSame(1, $fiche->departementsCouverts);
    }

    public function testUnDepartementSansMailleNAPasDeFiche(): void
    {
        $fiches = new FichesDepartementales($this->lecture($this->publie(), [
            new Maille('33001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
        ]), $this->marqueursDept([]), MoteurPourquoiIci::parDefaut());

        self::assertNull($fiches->pour('75'), 'Un département sans maille publiée n\'a pas de hub.');
    }

    public function testLaFicheProduitLesHypothesesAvecUnLevierDepartementalDerive(): void
    {
        // Gain E→C médian = 35 % → levier départemental « fort » dérivé, qui rend
        // cohérente l'hypothèse « froid » (marqueur départemental de la Meuse).
        $fiches = new FichesDepartementales(
            $this->lecture($this->publie(), [
                new Maille('55001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
            ]),
            $this->marqueursDept(['climat' => 'froid']),
            MoteurPourquoiIci::parDefaut(),
        );

        $fiche = $fiches->pour('55');

        self::assertNotNull($fiche);
        self::assertNotEmpty($fiche->phrases);
        // Au niveau département : aucune phrase mesurée, uniquement des hypothèses.
        foreach ($fiche->phrases as $phrase) {
            self::assertTrue($phrase->estHypothese());
        }
        self::assertStringContainsString('froid', $fiche->phrases[0]->texte);
    }

    public function testLHypotheseDepartementaleEstSupprimeeSiLeGainMedianEstFaible(): void
    {
        // Gain E→C médian faible (~8 %) → levier « faible » : l'hypothèse « froid »
        // (qui suppose que rénover paie) contredit la mesure et disparaît.
        $fiches = new FichesDepartementales(
            $this->lecture($this->publie(), [
                new Maille('55001', 'local', ['D' => 0.0, 'C' => 0.08, 'E' => 0.0]),
            ]),
            $this->marqueursDept(['climat' => 'froid']),
            MoteurPourquoiIci::parDefaut(),
        );

        $fiche = $fiches->pour('55');

        self::assertNotNull($fiche);
        self::assertSame([], $fiche->phrases);
    }

    /**
     * @param array<string, string> $dept marqueurs départementaux à servir
     */
    private function marqueursDept(array $dept): FournisseurMarqueurs
    {
        return new class($dept) implements FournisseurMarqueurs {
            /**
             * @param array<string, string> $dept
             */
            public function __construct(private array $dept)
            {
            }

            public function pourCommune(string $codeInsee, string $codeDepartement): Marqueurs
            {
                return Marqueurs::depuis([]);
            }

            public function pourDepartement(string $codeDepartement): Marqueurs
            {
                return Marqueurs::depuis($this->dept);
            }
        };
    }

    private function publie(): Publication
    {
        return new Publication(
            millesime: 2025,
            publieLe: new \DateTimeImmutable('2026-02-10'),
            calculeLe: new \DateTimeImmutable('2026-02-08'),
        );
    }

    /**
     * @param list<Maille> $mailles
     */
    private function lecture(?Publication $publication, array $mailles): LectureModelePublie
    {
        return new class($publication, $mailles) implements LectureModelePublie {
            /**
             * @param list<Maille> $mailles
             */
            public function __construct(private ?Publication $publication, private array $mailles)
            {
            }

            public function publication(): ?Publication
            {
                return $this->publication;
            }

            public function mailles(): array
            {
                return $this->mailles;
            }

            public function communesReferencees(): int
            {
                return \count($this->mailles);
            }
        };
    }
}
