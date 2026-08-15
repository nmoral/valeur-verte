<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\AdminInterne\Coefficient\SeuilsSupervision;
use App\AdminInterne\Millesime\Critere;
use App\AdminInterne\Millesime\FicheMaille;
use PHPUnit\Framework\TestCase;

/**
 * Fiche de diagnostic d'une maille : tous les critères d'évaluation en clair.
 *
 * L'enjeu n'est pas d'afficher les colonnes mais de les rendre **interprétables** :
 * les coefficients DPE sont en logarithme (piège documenté du moteur), les
 * `coef_m2_*` sont des élasticités et non des prix au m², et le R² est un
 * diagnostic interne jamais publié (#23). Un affichage brut trahirait les trois.
 */
final class FicheMailleTest extends TestCase
{
    public function testLEffetDpeEstConvertiDepuisLeLogarithmeEnPourcentage(): void
    {
        // coef C = 0,11 et coef E = −0,19 → gain log 0,30 → exp(0,30) − 1 = +35,0 %.
        $critere = $this->critere('Effet du DPE', 'Gain E → C');

        self::assertSame('+35,0 %', $critere->valeur());
        self::assertStringContainsString('logarithmique', (string) $critere->explication());
    }

    public function testLeSeuilDuGainEstLuiAussiConvertiEnPourcentage(): void
    {
        // Le seuil est réglé en log (0,70) : l'afficher tel quel serait illisible.
        $critere = $this->critere('Effet du DPE', 'Gain E → C');

        self::assertStringContainsString('101', (string) $critere->seuil());
        self::assertTrue($critere->respecte());
    }

    public function testUneElasticiteNestPasPresenteeCommeUnPrixAuMetreCarre(): void
    {
        $critere = $this->critere('Sensibilité aux surfaces', 'Surface bâtie');

        self::assertSame('0,85', $critere->valeur());
        self::assertStringNotContainsString('€', $critere->valeur());
        self::assertStringContainsString('%', (string) $critere->explication());
    }

    public function testUnCritereHorsSeuilEstMarqueNonRespecte(): void
    {
        $fiche = $this->fiche(['erreur_mediane' => 42.0]);

        $critere = self::trouver($fiche, 'Fiabilité', 'Erreur médiane');
        self::assertFalse($critere->respecte());
        self::assertFalse($fiche->toutRespecte());
    }

    public function testLErreurMedianeExpliqueLaValidationCroiseeSansJargon(): void
    {
        $critere = $this->critere('Fiabilité', 'Erreur médiane');

        $explication = (string) $critere->explication();
        self::assertStringNotContainsString('CV', $explication);
        self::assertStringContainsString('prix', $explication);
    }

    public function testLeR2EstPresenteCommeDiagnosticInterneSansVerdict(): void
    {
        // #23 : le R² n'est jamais publié. Le montrer avec un ✓/✗ lui donnerait un
        // statut de critère de décision qu'il n'a pas.
        $critere = $this->critere('Fiabilité', 'Qualité d\'ajustement (R²)');

        self::assertNull($critere->respecte());
        self::assertStringContainsString('interne', (string) $critere->explication());
    }

    public function testLeModeDeCouvertureEstTraduitEnClair(): void
    {
        $critere = $this->critere('Périmètre géographique', 'Niveau de calcul');

        self::assertSame('Commune', $critere->valeur());
        self::assertSame('EPCI (intercommunalité)', $this->critere('Périmètre géographique', 'Niveau de calcul', ['mode' => 'epci'])->valeur());
        self::assertSame('Département', $this->critere('Périmètre géographique', 'Niveau de calcul', ['mode' => 'departemental'])->valeur());
    }

    public function testLesEffectifsParClasseDpeSontListesAvecLeSeuilDePublication(): void
    {
        $critere = $this->critere('Effet du DPE', 'Ventes par classe DPE');

        self::assertStringContainsString('C : 24', $critere->valeur());
        self::assertStringContainsString('20', (string) $critere->seuil());
    }

    public function testUneClasseSousLeSeuilRendLeCritereNonRespecte(): void
    {
        $critere = $this->critere('Effet du DPE', 'Ventes par classe DPE', [
            'effectifs_dpe' => '{"D":30,"C":8,"E":22}',
        ]);

        self::assertFalse($critere->respecte());
    }

    public function testLaMonotonieDpeEstEvaluee(): void
    {
        // C doit valoir mieux que E : ici l'inverse, la monotonie est rompue.
        $critere = $this->critere('Effet du DPE', 'Ordre des classes', [
            'coef_dpe' => '{"D":0.0,"C":-0.19,"E":0.11}',
        ]);

        self::assertFalse($critere->respecte());
    }

    public function testSansEcartAuPrecedentLaRubriqueEstAbsente(): void
    {
        $fiche = $this->fiche(['classe_ecart' => null, 'ecart' => null, 'ecart_coefficient' => null]);

        $titres = array_map(static fn ($rubrique): string => $rubrique->titre(), $fiche->rubriques());
        self::assertNotContains('Écart au millésime précédent', $titres);
    }

    public function testUnEcartBloquantEstExpliqueEtNonRespecte(): void
    {
        $fiche = $this->fiche([
            'classe_ecart' => 'blocage',
            'ecart' => 1.80,
            'ecart_coefficient' => 'élasticité bâti',
        ]);

        $critere = self::trouver($fiche, 'Écart au millésime précédent', 'élasticité bâti');
        self::assertSame('+180,0 %', $critere->valeur());
        self::assertFalse($critere->respecte());
        self::assertStringContainsString('écartée', (string) $critere->explication());
    }

    /**
     * @param array<string, mixed> $surcharges
     */
    private function critere(string $rubrique, string $libelle, array $surcharges = []): Critere
    {
        return self::trouver($this->fiche($surcharges), $rubrique, $libelle);
    }

    private static function trouver(FicheMaille $fiche, string $rubrique, string $libelle): Critere
    {
        foreach ($fiche->rubriques() as $bloc) {
            if ($bloc->titre() !== $rubrique) {
                continue;
            }
            foreach ($bloc->criteres() as $critere) {
                if ($critere->libelle() === $libelle) {
                    return $critere;
                }
            }
        }

        self::fail(\sprintf('Critère « %s » absent de la rubrique « %s ».', $libelle, $rubrique));
    }

    /**
     * @param array<string, mixed> $surcharges
     */
    private function fiche(array $surcharges = []): FicheMaille
    {
        return FicheMaille::depuis([
            'code_insee' => '31555',
            'millesime' => 2024,
            'rayon_retenu_km' => 15,
            'n_transactions' => 120,
            'r2' => 0.87,
            'erreur_mediane' => 17.0,
            'coef_m2_bati' => 0.85,
            'coef_m2_terrain' => 0.25,
            'coef_dpe' => '{"D":0.0,"C":0.11,"E":-0.19}',
            'effectifs_dpe' => '{"D":30,"C":24,"E":22}',
            'mode' => 'local',
            'palier' => 'standard',
            'alerte' => null,
            'classe_ecart' => null,
            'ecart' => null,
            'ecart_coefficient' => null,
            ...$surcharges,
        ], SeuilsSupervision::parDefaut());
    }
}
