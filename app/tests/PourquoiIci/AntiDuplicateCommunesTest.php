<?php

declare(strict_types=1);

namespace App\Tests\PourquoiIci;

use App\BackOffice\Estimation\FournisseurPrixMaille;
use App\PourquoiIci\MarqueursDbal;
use App\PourquoiIci\MoteurPourquoiIci;
use App\Publication\Commune;
use App\Publication\FicheCommune;
use App\Publication\FichesCommunales;
use App\Publication\LectureModelePublieDbal;
use App\Tests\Publication\ProvisionneModelePublie;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Anti-duplicate SEO (#341, critère d'acceptation Lot 5) : deux communes d'un même
 * département ne doivent pas produire un bloc « Pourquoi ici ? » identique — sinon on
 * recrée du thin/duplicate content que la Vague 2 cherche à éviter.
 *
 * Le levier : elles partagent leurs marqueurs départementaux (climat…), mais leurs
 * marqueurs communaux (densité, marché commune…) varient et **écrasent** le
 * départemental de même nom (cf. {@see MarqueursDbal}).
 */
final class AntiDuplicateCommunesTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    public function testDeuxCommunesDUnMemeDepartementNOntPasLeMemeBloc(): void
    {
        // Deux communes du 31, même maille (gain E→C = 35 %), même climat départemental.
        $this->semerMaille('31001');
        $this->semerMaille('31002');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
        $this->semerMarqueur(nom: 'climat', valeur: 'froid', codeDepartement: '31');
        // Marqueurs communaux DISTINCTS (+ levier local pour la garde de cohérence).
        $this->semerMarqueur(nom: 'levier_renovation', valeur: 'fort', typeMarqueur: 'mesure', codeInsee: '31001');
        $this->semerMarqueur(nom: 'marche', valeur: 'detendu', codeInsee: '31001');
        $this->semerMarqueur(nom: 'levier_renovation', valeur: 'fort', typeMarqueur: 'mesure', codeInsee: '31002');
        $this->semerMarqueur(nom: 'densite', valeur: 'rural', codeInsee: '31002');

        $fiches = $this->fiches();
        $bloc1 = $this->textes($fiches->pour(new Commune('31001', 'Toulouse', '31', null)));
        $bloc2 = $this->textes($fiches->pour(new Commune('31002', 'Ailleurs', '31', null)));

        // Blocs globalement distincts…
        self::assertNotSame($bloc1, $bloc2);
        // …chacun porte son hypothèse communale, absente chez l'autre.
        self::assertTrue($this->contient($bloc1, 'Marché détendu'));
        self::assertFalse($this->contient($bloc1, 'rural'));
        self::assertTrue($this->contient($bloc2, 'rural'));
        self::assertFalse($this->contient($bloc2, 'Marché détendu'));
        // …tout en partageant l'hypothèse départementale (climat froid).
        self::assertTrue($this->contient($bloc1, 'froid'));
        self::assertTrue($this->contient($bloc2, 'froid'));
    }

    /**
     * @return list<string>
     */
    private function textes(?FicheCommune $fiche): array
    {
        self::assertNotNull($fiche);

        return array_map(static fn ($phrase): string => $phrase->texte, $fiche->phrases);
    }

    /**
     * @param list<string> $textes
     */
    private function contient(array $textes, string $extrait): bool
    {
        foreach ($textes as $texte) {
            if (str_contains($texte, $extrait)) {
                return true;
            }
        }

        return false;
    }

    private function fiches(): FichesCommunales
    {
        $prix = new class implements FournisseurPrixMaille {
            public function prixM2Median(string $codeInsee): float
            {
                return 2500.0;
            }
        };

        return new FichesCommunales(
            $this->app(),
            new LectureModelePublieDbal($this->app()),
            $prix,
            new MarqueursDbal($this->app()),
            MoteurPourquoiIci::parDefaut(),
        );
    }
}
