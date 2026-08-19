<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\ContoursDepartements;
use App\Publication\EtudePubliee;
use App\Publication\Maille;
use App\Publication\Publication;
use App\Tests\Publication\Double\LectureModelePublieEnMemoire;
use PHPUnit\Framework\TestCase;

/**
 * L'étude publiée (#48 + #224) : ce que la page affiche du modèle — le millésime
 * **publié** (REG-2), sa date, sa couverture, et les médianes nationales citées
 * dans le bandeau de preuve.
 *
 * Rien n'est publié tant qu'un millésime n'a pas été validé à la main : dans ce
 * cas il n'y a pas d'étude, et la page n'existe pas.
 */
final class EtudePublieeTest extends TestCase
{
    public function testSansMillesimePublieIlNYAPasDEtude(): void
    {
        $etude = self::etude(new LectureModelePublieEnMemoire(null, [], 34_945))->courante();

        self::assertNull($etude);
    }

    public function testLEtudePorteLeMillesimePublieEtSesDates(): void
    {
        $etude = self::etude()->courante();

        self::assertNotNull($etude);
        self::assertSame(2025, $etude->millesime);
        self::assertSame('2026-02-10', $etude->publieLe->format('Y-m-d'));
        self::assertNotNull($etude->calculeLe);
        self::assertSame('2026-02-08', $etude->calculeLe->format('Y-m-d'));
    }

    public function testLEtudeCompteLesZonesMesureesEtLesDepartementsCouverts(): void
    {
        $etude = self::etude()->courante();

        self::assertNotNull($etude);
        self::assertSame(4, $etude->zonesMesurees);
        self::assertSame(2, $etude->departementsCouverts);
        self::assertSame(34_945, $etude->communesReferencees);
    }

    public function testLesMedianesNationalesSontCellesDesDepartements(): void
    {
        $etude = self::etude()->courante();

        self::assertNotNull($etude);
        // Médianes départementales : 31 → +35 % / +21 %, 09 → +11 % / +5 %.
        self::assertSame(23, $etude->medianeGainEc);
        self::assertSame(13, $etude->medianeGainEd);
    }

    public function testLaCarteEstAssembleeAvecLesContoursFiges(): void
    {
        $etude = self::etude()->courante();

        self::assertNotNull($etude);
        self::assertSame(['31', '09'], array_column($etude->carte['feats'], 'dept'));
    }

    public function testLesConstatsSontDerivesDesDepartementsPublies(): void
    {
        $etude = self::etude()->courante();

        self::assertNotNull($etude);
        // 31 (Haute-Garonne) : +35 % E→C, +21 % E→D ; 09 (Ariège) : +11 %, +5 %.
        self::assertSame(35, $etude->constats->gainsLesPlusForts[0]->valeur);
        self::assertSame(['Haute-Garonne'], $etude->constats->gainsLesPlusForts[0]->noms);
        self::assertSame(1, $etude->constats->departementsAuDela30);
        // Écart du second palier : 31 → 35−21=14, 09 → 11−5=6.
        self::assertSame(['Haute-Garonne', 'Ariège'], $etude->constats->ecartSecondPalier->noms);
        self::assertSame(6, $etude->constats->ecartSecondPalier->seuil);
    }

    public function testLesDepartementsDessinesSansMetriqueSontComptesAbsents(): void
    {
        // Contours de trois départements (31, 09, 75) mais seuls 31 et 09 publiés :
        // Paris est dessiné sans chiffres — donc compté absent.
        $etude = self::etude(null, 'contours-avec-absent.json')->courante();

        self::assertNotNull($etude);
        self::assertSame(1, $etude->departementsAbsents);
    }

    private static function etude(
        ?LectureModelePublieEnMemoire $lecture = null,
        string $contours = 'contours-de-test.json',
    ): EtudePubliee {
        $lecture ??= new LectureModelePublieEnMemoire(
            new Publication(
                2025,
                new \DateTimeImmutable('2026-02-10 09:30:00'),
                new \DateTimeImmutable('2026-02-08 04:12:00'),
            ),
            [
                new Maille('31001', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
                new Maille('31002', 'local', ['D' => 0.0, 'C' => 0.11, 'E' => -0.19]),
                new Maille('09001', 'departemental', ['D' => 0.0, 'C' => 0.05, 'E' => -0.05]),
                new Maille('09002', 'departemental', ['D' => 0.0, 'C' => 0.05, 'E' => -0.05]),
            ],
            34_945,
        );

        return new EtudePubliee(
            $lecture,
            new ContoursDepartements(\dirname(__DIR__).'/Publication/'.$contours),
        );
    }
}
