<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\AnnuaireEpciDuDepartement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : l'annuaire des EPCI d'un département (#364, maillage
 * descendant) énumère les EPCI qui portent **au moins une commune mesurée** du
 * département (jointure ``coefficient_maille`` ⋈ ``commune`` ⋈ ``commune_epci`` ⋈
 * ``epci``), dédoublonnés et ordonnés par nom. Les hubs EPCI étant toujours
 * indexables (#361 ne gate que les communes), aucun filtrage d'indexabilité ici.
 */
final class AnnuaireEpciDuDepartementTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    private const COEF = '{"D":0.0,"C":0.11,"E":-0.19}';

    public function testEnumereLesEpciMesuresDuDepartementDedoublonnesParNom(): void
    {
        self::bootKernel();
        $this->semerEpci('200000001', 'CA du Grand');
        $this->semerEpci('200000002', 'CC des Coteaux');
        // Deux communes du même EPCI (A) : l'EPCI ne doit apparaître qu'une fois.
        $this->semerCommuneMesuree('33001', 'Alpha', '33', '200000001');
        $this->semerCommuneMesuree('33002', 'Beta', '33', '200000001');
        $this->semerCommuneMesuree('33003', 'Gamma', '33', '200000002');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $annuaire = $this->annuaire()->pour('33');

        self::assertCount(2, $annuaire);
        self::assertSame('CA du Grand', $annuaire[0]->nom);
        self::assertSame('ca-du-grand', $annuaire[0]->slug);
        self::assertSame('CC des Coteaux', $annuaire[1]->nom);
        self::assertSame('cc-des-coteaux', $annuaire[1]->slug);
    }

    public function testUnEpciSansCommuneMesureeEstAbsent(): void
    {
        self::bootKernel();
        $this->semerEpci('200000009', 'CC Sans Mesure');
        // Commune rattachée mais SANS maille publiée → l'EPCI ne figure pas.
        $this->semerCommune('33500', 'Vide', '33');
        $this->rattacherCommuneEpci('33500', '200000009');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        self::assertSame([], $this->annuaire()->pour('33'));
    }

    public function testUnEpciChevauchantDeuxDepartementsApparaitDansChacun(): void
    {
        self::bootKernel();
        $this->semerEpci('200000001', 'CA du Grand');
        $this->semerCommuneMesuree('33001', 'Alpha', '33', '200000001');
        $this->semerCommuneMesuree('40001', 'Zeta', '40', '200000001');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        self::assertSame('CA du Grand', $this->annuaire()->pour('33')[0]->nom);
        self::assertSame('CA du Grand', $this->annuaire()->pour('40')[0]->nom);
    }

    public function testUnDepartementSansEpciMesureDonneUnAnnuaireVide(): void
    {
        self::bootKernel();
        $this->prive();

        self::assertSame([], $this->annuaire()->pour('99'));
    }

    private function annuaire(): AnnuaireEpciDuDepartement
    {
        return new AnnuaireEpciDuDepartement($this->app());
    }

    private function semerCommuneMesuree(string $codeInsee, string $nom, string $departement, string $codeEpci): void
    {
        $this->semerCommune($codeInsee, $nom, $departement);
        $this->rattacherCommuneEpci($codeInsee, $codeEpci);
        $this->semerMaille($codeInsee, coefDpe: self::COEF);
    }
}
