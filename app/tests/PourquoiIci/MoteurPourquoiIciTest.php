<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\PourquoiIci\Marqueurs;
use App\PourquoiIci\MoteurPourquoiIci;
use App\PourquoiIci\Phrase;
use App\PourquoiIci\RegleContextuelle;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur de narration du bloc « Pourquoi ici ? » (#341, Lot 3).
 *
 * Il rend, dans l'ordre : la phrase MESURÉE (fait, sans badge) puis les hypothèses
 * de la liste blanche dont le contexte est réuni ET cohérent avec la mesure. Une
 * liste blanche injectée ici teste le mécanisme ; la vraie liste est couverte
 * séparément (ListeBlanche).
 */
final class MoteurPourquoiIciTest extends TestCase
{
    private function moteur(): MoteurPourquoiIci
    {
        return new MoteurPourquoiIci([
            RegleContextuelle::depuis(
                contexte: ['climat' => ['froid']],
                coherence: ['levier_renovation' => ['moyen', 'fort']],
                texte: 'Département froid : isoler paie probablement.',
            ),
        ]);
    }

    public function testParDefautCableLeMoteurSurLaListeBlanche(): void
    {
        // La fabrique par défaut (utilisée par le conteneur, moteur non autowirable)
        // monte le moteur avec la liste blanche : une commune froide au levier fort
        // en tire l'hypothèse « froid ».
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        $hypotheses = array_values(array_filter(
            MoteurPourquoiIci::parDefaut()->phrases($marqueurs, 35, 120),
            static fn (Phrase $p) => $p->estHypothese(),
        ));

        self::assertCount(1, $hypotheses);
        self::assertStringContainsString('froid', $hypotheses[0]->texte);
    }

    public function testLaPhraseMesureeOuvreLeBlocAvecLeGainEtLeNombreDeVentes(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        $phrases = $this->moteur()->phrases($marqueurs, gainEc: 35, n: 120);

        self::assertFalse($phrases[0]->estHypothese());
        self::assertStringContainsString('+35 %', $phrases[0]->texte);
        self::assertStringContainsString('120 ventes', $phrases[0]->texte);
    }

    public function testUneHypotheseCoherenteEstEmiseApresLaMesure(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        $phrases = $this->moteur()->phrases($marqueurs, gainEc: 35, n: 120);
        $hypotheses = array_values(array_filter($phrases, static fn (Phrase $p) => $p->estHypothese()));

        self::assertCount(1, $hypotheses);
        self::assertSame('Département froid : isoler paie probablement.', $hypotheses[0]->texte);
    }

    public function testUneMesureQuiContreditLeContexteNeProduitAucuneHypothese(): void
    {
        // Climat froid mais levier faible : l'hypothèse « isoler paie » est supprimée.
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'faible']);

        $phrases = $this->moteur()->phrases($marqueurs, gainEc: 8, n: 50);
        $hypotheses = array_filter($phrases, static fn (Phrase $p) => $p->estHypothese());

        self::assertSame([], array_values($hypotheses));
        // Le fait mesuré, lui, reste affiché.
        self::assertCount(1, $phrases);
        self::assertFalse($phrases[0]->estHypothese());
    }

    public function testSansGainMesureAucunePhraseMesureeNEstProduite(): void
    {
        $marqueurs = Marqueurs::depuis(['climat' => 'froid', 'levier_renovation' => 'fort']);

        $phrases = $this->moteur()->phrases($marqueurs, gainEc: null, n: 0);

        // Pas de fait chiffré à afficher : la seule phrase est l'hypothèse cohérente.
        self::assertCount(1, $phrases);
        self::assertTrue($phrases[0]->estHypothese());
    }
}
