<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\ListeBlanche;
use App\PourquoiIci\Marqueurs;
use App\PourquoiIci\MoteurPourquoiIci;
use App\PourquoiIci\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * La liste blanche des hypothèses contextuelles (#341, Lot 3), éprouvée bout-en-bout
 * via le moteur sur les exemples réels de l'issue (millésime 2025).
 *
 * Rappel du cadre : génération jamais libre (liste blanche seule), chaque phrase
 * contextuelle est une hypothèse, et la garde de cohérence supprime toute
 * hypothèse que la mesure contredit.
 */
final class ListeBlancheTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function hypotheses(Marqueurs $marqueurs, ?int $gainEc, int $n): array
    {
        $moteur = new MoteurPourquoiIci(ListeBlanche::reglesParDefaut());

        return array_values(array_map(
            static fn (Phrase $p) => $p->texte,
            array_filter($moteur->phrases($marqueurs, $gainEc, $n), static fn (Phrase $p) => $p->estHypothese()),
        ));
    }

    public function testEtainMeuseFroidDetenduRuralLevierFort(): void
    {
        // Froid (H1), marché détendu, rural, levier de rénovation fort → 3 hypothèses cohérentes.
        $marqueurs = Marqueurs::depuis([
            'climat' => 'froid',
            'marche' => 'detendu',
            'densite' => 'rural',
            'levier_renovation' => 'fort',
        ]);

        $hypotheses = $this->hypotheses($marqueurs, gainEc: 35, n: 42);

        self::assertCount(3, $hypotheses);
        self::assertTrue($this->auMoinsUne($hypotheses, 'froid'));
        self::assertTrue($this->auMoinsUne($hypotheses, 'détendu'));
        self::assertTrue($this->auMoinsUne($hypotheses, 'rural'));
    }

    public function testVarDouxTenduLittoralTourismeFortLevierFaible(): void
    {
        // Doux (H3), marché tendu, littoral prisé, levier faible → 2 hypothèses cohérentes.
        $marqueurs = Marqueurs::depuis([
            'climat' => 'doux',
            'marche' => 'tendu',
            'littoral' => 'oui',
            'tourisme' => 'fort',
            'densite' => 'urbain',
            'levier_renovation' => 'faible',
        ]);

        $hypotheses = $this->hypotheses($marqueurs, gainEc: 8, n: 90);

        self::assertCount(2, $hypotheses);
        self::assertTrue($this->auMoinsUne($hypotheses, 'Climat doux et marché tendu'));
        self::assertTrue($this->auMoinsUne($hypotheses, 'Littoral'));
    }

    public function testLaGardeDeCoherenceSupprimeLHypotheseFroidSiLeLevierEstFaible(): void
    {
        // Même territoire froid+détendu+rural, mais la mesure dit « levier faible » :
        // toutes ces hypothèses (qui supposent que rénover paie) sont supprimées.
        $marqueurs = Marqueurs::depuis([
            'climat' => 'froid',
            'marche' => 'detendu',
            'densite' => 'rural',
            'levier_renovation' => 'faible',
        ]);

        self::assertSame([], $this->hypotheses($marqueurs, gainEc: 5, n: 40));
    }

    public function testVarAvecLevierFortNeProduitAucuneHypothese(): void
    {
        // Mêmes marqueurs contextuels que le Var, mais la mesure dit « levier fort » :
        // les deux hypothèses (doux+tendu, littoral+tourisme) supposent un levier faible,
        // elles sont donc toutes supprimées.
        $marqueurs = Marqueurs::depuis([
            'climat' => 'doux',
            'marche' => 'tendu',
            'littoral' => 'oui',
            'tourisme' => 'fort',
            'densite' => 'urbain',
            'levier_renovation' => 'fort',
        ]);

        self::assertSame([], $this->hypotheses($marqueurs, gainEc: 40, n: 90));
    }

    public function testLaRegleLittoralExigeLittoralEtTourisme(): void
    {
        // Le littoral seul ne suffit pas…
        self::assertFalse($this->auMoinsUne(
            $this->hypotheses(
                Marqueurs::depuis(['littoral' => 'oui', 'levier_renovation' => 'faible']),
                8,
                90,
            ),
            'Littoral',
        ));
        // …le tourisme seul non plus…
        self::assertFalse($this->auMoinsUne(
            $this->hypotheses(
                Marqueurs::depuis(['tourisme' => 'fort', 'levier_renovation' => 'faible']),
                8,
                90,
            ),
            'Littoral',
        ));
        // …les deux réunis, oui.
        self::assertTrue($this->auMoinsUne(
            $this->hypotheses(
                Marqueurs::depuis([
                    'littoral' => 'oui',
                    'tourisme' => 'fort',
                    'levier_renovation' => 'faible',
                ]),
                8,
                90,
            ),
            'Littoral',
        ));
    }

    public function testLaRegleClimatDouxExigeClimatDouxEtMarcheTendu(): void
    {
        // Doux mais marché non tendu : pas d'hypothèse « doux + tendu ».
        self::assertFalse($this->auMoinsUne(
            $this->hypotheses(
                Marqueurs::depuis([
                    'climat' => 'doux',
                    'marche' => 'detendu',
                    'levier_renovation' => 'faible',
                ]),
                8,
                90,
            ),
            'Climat doux et marché tendu',
        ));
        // Marché tendu mais climat non doux : pas davantage.
        self::assertFalse($this->auMoinsUne(
            $this->hypotheses(
                Marqueurs::depuis([
                    'climat' => 'froid',
                    'marche' => 'tendu',
                    'levier_renovation' => 'faible',
                ]),
                8,
                90,
            ),
            'Climat doux et marché tendu',
        ));
    }

    public function testLeLevierMoyenSuffitAuxReglesFroidDetenduRural(): void
    {
        // La garde de ces trois règles accepte « moyen » comme « fort ».
        $marqueurs = Marqueurs::depuis([
            'climat' => 'froid',
            'marche' => 'detendu',
            'densite' => 'rural',
            'levier_renovation' => 'moyen',
        ]);

        self::assertCount(3, $this->hypotheses($marqueurs, gainEc: 22, n: 60));
    }

    public function testAucuneHypotheseNEstChiffree(): void
    {
        // Cadre de responsabilité : aucune hypothèse chiffrée — le pourcentage est
        // réservé au fait mesuré. Une zone climatique (« H1 ») n'est pas une quantité.
        foreach (ListeBlanche::reglesParDefaut() as $regle) {
            self::assertStringNotContainsString('%', $regle->texte, $regle->texte);
        }
    }

    /**
     * @param list<string> $hypotheses
     */
    private function auMoinsUne(array $hypotheses, string $extrait): bool
    {
        foreach ($hypotheses as $texte) {
            if (str_contains($texte, $extrait)) {
                return true;
            }
        }

        return false;
    }
}
