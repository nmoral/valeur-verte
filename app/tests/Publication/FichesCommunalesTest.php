<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\BackOffice\Estimation\FournisseurPrixMaille;
use App\BackOffice\Restitution\IndicateurFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\Publication\Commune;
use App\Publication\FichesCommunales;
use App\Publication\IndexabiliteCommune;
use App\Publication\LectureModelePublieDbal;
use App\PourquoiIci\MarqueursDbal;
use App\PourquoiIci\MoteurPourquoiIci;
use App\PourquoiIci\Phrase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la fiche publique d'une commune (#360) est lue depuis le
 * schéma « data » (maille publiée + prix de marché), gatée sur la publication et
 * l'appartenance à la couverture — analogue commune de {@see \App\Publication\FichesDepartementales}.
 */
final class FichesCommunalesTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testPourUneCommunePublieeExposeSaFiche(): void
    {
        $this->semerMaille('33063', coefDpe: '{"D":0.0,"C":0.10,"B":0.18,"E":-0.15,"A":0.30}');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $fiche = $this->fiches(prix: 2500.0)->pour(new Commune('33063', 'Bordeaux', '33', '243300316'));

        self::assertNotNull($fiche);
        self::assertSame('Bordeaux', $fiche->nom);
        self::assertSame('33063', $fiche->codeInsee);
        self::assertSame(2025, $fiche->millesime);
        self::assertSame(28, $fiche->gainEc());
        self::assertSame(11, $fiche->decoteSurcote()['C']);
        self::assertSame(2500, $fiche->prixM2Median());
        self::assertSame(2763, $fiche->prixM2ParClasse()['C']);
        // semerMaille sème n=120, rayon=10 km → Solide, portée locale.
        self::assertSame(IndicateurFiabilite::Solide, $fiche->indicateur());
        self::assertSame(PorteeEstimation::Local, $fiche->portee());
    }

    public function testLePoidsDePoolingRemonteEtPiloteLIndexabilite(): void
    {
        // Maille locale mais fortement rapprochée du département (pooling < 0.5) :
        // la métrique remonte de data.coefficient_maille et bascule la page en repli.
        $this->semerMaille('33063', coefDpe: '{"D":0.0,"C":0.10}', poidsPooling: 0.3);
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $fiche = $this->fiches(prix: 2500.0)->pour(new Commune('33063', 'Bordeaux', '33', '243300316'));

        self::assertNotNull($fiche);
        self::assertSame(0.3, $fiche->poidsPooling);
        self::assertSame(IndexabiliteCommune::Repli, $fiche->indexabilite());
    }

    public function testSansPoolingUneCommuneLocaleResteIndexable(): void
    {
        // poids_pooling absent = aucun pooling = maille pleinement locale → indexable.
        $this->semerMaille('33063', coefDpe: '{"D":0.0,"C":0.10}');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $fiche = $this->fiches(prix: 2500.0)->pour(new Commune('33063', 'Bordeaux', '33', '243300316'));

        self::assertNotNull($fiche);
        self::assertNull($fiche->poidsPooling);
        self::assertSame(IndexabiliteCommune::Indexable, $fiche->indexabilite());
    }

    public function testUneCommuneHorsCouvertureNAPasDeFiche(): void
    {
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        self::assertNull($this->fiches(prix: 2500.0)->pour(new Commune('33999', 'Nulle Part', '33', null)));
    }

    public function testSansMillesimePublieAucuneFiche(): void
    {
        $this->semerMaille('33063', coefDpe: '{"D":0.0,"C":0.10}');

        self::assertNull($this->fiches(prix: 2500.0)->pour(new Commune('33063', 'Bordeaux', '33', null)));
    }

    public function testLaFicheProduitLeBlocPourquoiIci(): void
    {
        // Maille par défaut : gain E→C = 35 %, n = 120. Marqueurs communaux : marché
        // détendu + levier fort → l'hypothèse « marché détendu » est cohérente.
        $this->semerMaille('33063');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
        $this->semerMarqueur(nom: 'marche', valeur: 'detendu', codeInsee: '33063');
        $this->semerMarqueur(nom: 'levier_renovation', valeur: 'fort', typeMarqueur: 'mesure', codeInsee: '33063');

        $fiche = $this->fiches(prix: 2500.0)->pour(new Commune('33063', 'Bordeaux', '33', null));

        self::assertNotNull($fiche);
        // La phrase mesurée ouvre le bloc (fait, sans badge).
        self::assertFalse($fiche->phrases[0]->estHypothese());
        self::assertStringContainsString('+35 %', $fiche->phrases[0]->texte);
        self::assertStringContainsString('120 ventes', $fiche->phrases[0]->texte);
        // Une hypothèse contextuelle cohérente suit.
        $hypotheses = array_values(array_filter(
            $fiche->phrases,
            static fn (Phrase $p) => $p->estHypothese(),
        ));
        self::assertNotEmpty($hypotheses);
        self::assertStringContainsString('Marché détendu', $hypotheses[0]->texte);
    }

    private function fiches(?float $prix = null): FichesCommunales
    {
        // Prix de marché doublé : ce test cible la lecture de la maille, pas data.vente.
        $fournisseurPrix = new class($prix) implements FournisseurPrixMaille {
            public function __construct(private ?float $prix)
            {
            }

            public function prixM2Median(string $codeInsee): ?float
            {
                return $this->prix;
            }
        };

        return new FichesCommunales(
            $this->app(),
            new LectureModelePublieDbal($this->app()),
            $fournisseurPrix,
            new MarqueursDbal($this->app()),
            MoteurPourquoiIci::parDefaut(),
        );
    }
}
