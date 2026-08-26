<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\FichesEpci;
use App\Publication\LectureModelePublieDbal;
use App\Publication\RepertoireDepartements;
use App\Publication\RepertoireEpci;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la fiche publique d'un EPCI (#363) agrège les mailles
 * publiées de ses communes membres (jointure ``coefficient_maille`` ⋈
 * ``commune_epci``) et bâtit l'annuaire des communes, dont le lien n'est ouvert que
 * pour les communes indexables (gating #361). Gatée sur la publication et sur la
 * présence d'au moins une commune mesurée — sinon ``null`` (le contrôleur répond 404).
 */
final class FichesEpciTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    private const COEF = '{"D":0.0,"C":0.11,"B":0.19,"E":-0.19}';

    public function testPourUnEpciMesureExposeSesChiffresEtSonAnnuaire(): void
    {
        self::bootKernel();
        $this->publier();

        $fiche = $this->fiches()->pour('243300316');

        self::assertNotNull($fiche);
        self::assertSame(2025, $fiche->millesime);
        self::assertSame('Bordeaux Métropole', $fiche->epci->nom);
        self::assertSame(2, $fiche->epci->zones);
        self::assertSame(35, $fiche->epci->gainEc);

        // Annuaire ordonné par nom (Bordeaux, Bruges) : le lien n'est ouvert que
        // pour la commune indexable ; la commune en repli (#361) reste sans lien.
        self::assertCount(2, $fiche->annuaire);
        [$bordeaux, $bruges] = $fiche->annuaire;
        self::assertSame('Bordeaux', $bordeaux->nom);
        self::assertSame('gironde', $bordeaux->departementSlug);
        self::assertSame('bordeaux', $bordeaux->communeSlug);
        self::assertTrue($bordeaux->indexable, 'Maille locale non rapprochée → indexable.');
        self::assertSame('Bruges', $bruges->nom);
        self::assertFalse($bruges->indexable, 'Maille fortement rapprochée (pooling < 0.5) → repli.');

        // Le(s) département(s) recouvert(s) : maillage montant + JSON-LD.
        self::assertCount(1, $fiche->departements);
        self::assertSame('Gironde', $fiche->departements[0]['nom']);
        self::assertSame('gironde', $fiche->departements[0]['slug']);
    }

    public function testSansMillesimePublieAucuneFiche(): void
    {
        self::bootKernel();
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->rattacherCommuneEpci('33063', '243300316');
        $this->semerMaille('33063', coefDpe: self::COEF);

        self::assertNull($this->fiches()->pour('243300316'));
    }

    public function testUnEpciInconnuNAPasDeFiche(): void
    {
        self::bootKernel();
        $this->publier();

        self::assertNull($this->fiches()->pour('999999999'));
    }

    public function testUnEpciSansCommuneMesureeEst404(): void
    {
        // EPCI connu et rattaché, mais aucune maille publiée → page vide interdite.
        self::bootKernel();
        $this->semerEpci('200000001', 'CC Sans Mesure');
        $this->semerCommune('33500', 'Nulle Part', '33');
        $this->rattacherCommuneEpci('33500', '200000001');
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        self::assertNull($this->fiches()->pour('200000001'));
    }

    private function fiches(): FichesEpci
    {
        return new FichesEpci(
            new LectureModelePublieDbal($this->app()),
            $this->app(),
            new RepertoireEpci($this->app()),
            new RepertoireDepartements(),
        );
    }

    /**
     * Bordeaux Métropole : Bordeaux mesurée localement (indexable), Bruges
     * fortement rapprochée du département (pooling 0.3 → repli #361).
     */
    private function publier(): void
    {
        $this->semerEpci('243300316', 'Bordeaux Métropole');
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->rattacherCommuneEpci('33063', '243300316');
        $this->semerMaille('33063', coefDpe: self::COEF, calculeLe: '2026-02-08 04:12:00');
        $this->semerCommune('33065', 'Bruges', '33');
        $this->rattacherCommuneEpci('33065', '243300316');
        $this->semerMaille('33065', coefDpe: self::COEF, calculeLe: '2026-02-08 04:12:00', poidsPooling: 0.3);
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');
    }
}
