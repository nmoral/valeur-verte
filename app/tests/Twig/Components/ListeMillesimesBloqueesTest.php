<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\AdminInterne\Millesime\DecisionMillesime;
use App\Tests\AdminInterne\Millesime\ProvisionneSchemaData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Action de groupe sur les mailles à vérifier (#239/#130) : débloquer la
 * sélection en une fois depuis la liste, une fois le millésime publié.
 *
 * Deux pièges couverts :
 *  - proposer la sélection alors que le millésime n'est pas publié (le déblocage
 *    est refusé côté SQL) → aucune case de sélection tant qu'il ne l'est pas ;
 *  - laisser les mailles débloquées dans la liste (elles doivent en sortir).
 */
final class ListeMillesimesBloqueesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use ProvisionneSchemaData;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->provisionner();
        $this->semerBloquee('31004');
    }

    public function testLesCasesDeSelectionApparaissentSeulementSiPublie(): void
    {
        $sans = (string) $this->composant(false)->render();
        self::assertStringNotContainsString('data-model="selection"', $sans);

        $this->publier(2024);
        $avec = (string) $this->composant(true)->render();
        self::assertStringContainsString('data-model="selection"', $avec);
    }

    public function testLActionDeGroupeDebloqueLaSelectionEtLaSortDeLaListe(): void
    {
        $this->publier(2024);

        $composant = $this->composant(true);
        $composant->set('selection', ['2024:31003', '2024:31004']);

        $html = (string) $composant->call('executerGroupe', ['cle' => 'millesimes.publier_mailles'])->render();

        self::assertStringContainsString('2 maille(s) débloquée(s).', $html);
        // Rechargée depuis la base : les deux mailles servies ont quitté la liste.
        self::assertStringContainsString('Aucune maille écartée', $html);
    }

    public function testUneActionNonAutoriseeSansPublicationEstRejetee(): void
    {
        $composant = $this->composant(false);
        $composant->set('selection', ['2024:31003']);

        $this->expectException(\InvalidArgumentException::class);

        $composant->call('executerGroupe', ['cle' => 'millesimes.publier_mailles']);
    }

    private function composant(bool $publie): TestLiveComponent
    {
        return $this->createLiveComponent('ListeMillesimesBloquees', [
            'millesime' => 2024,
            'millesimePublie' => $publie,
        ]);
    }

    private function semerBloquee(string $codeInsee): void
    {
        $this->prive()->executeStatement(
            "INSERT INTO data.coefficient_maille_historique
                (code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
                 coef_m2_bati, coef_m2_terrain, coef_dpe, effectifs_dpe,
                 mode, palier, classe_ecart, ecart, ecart_coefficient) VALUES
             (:code, 2024, 10, 95, 0.74, 19.50, 0.88, 0.28, '{\"D\":0.0,\"C\":0.11,\"E\":-0.19}', '{\"D\":30,\"C\":24,\"E\":22}', 'local', 'standard', 'blocage', 1.60, 'élasticité bâti')",
            ['code' => $codeInsee],
        );
    }

    private function publier(int $millesime): void
    {
        $decision = self::getContainer()->get(DecisionMillesime::class);
        self::assertInstanceOf(DecisionMillesime::class, $decision);
        $decision->publier($millesime, 'admin@vv.test');
    }
}
