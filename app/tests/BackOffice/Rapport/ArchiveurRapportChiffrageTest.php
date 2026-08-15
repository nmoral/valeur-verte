<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\ArchiveurRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'archivage fige le chiffrage et le barème en vigueur (#26, §9.1). C'est ce qui
 * rend le reste à charge reproductible : ni la grille du pro ni le barème national
 * ne peuvent le faire bouger après coup.
 */
final class ArchiveurRapportChiffrageTest extends KernelTestCase
{
    public function testLArchivageFigeLeChiffrageEtLeBaremeEnVigueur(): void
    {
        $rapport = $this->archiver(Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries));

        $instantane = $rapport->instantane();
        $chiffrage = $instantane->chiffrage();
        self::assertNotNull($chiffrage);
        // 120 €/m² × 100 m², puis 720 €/unité × 8 ouvrants (5 pièces).
        self::assertSame(12000, $chiffrage->montant(Poste::Murs)->euros());
        self::assertSame(5760, $chiffrage->montant(Poste::Menuiseries)->euros());

        $bareme = $instantane->baremeAides();
        self::assertNotNull($bareme);
        self::assertSame(80, $bareme->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testSansBouquetAucunChiffrageNEstFige(): void
    {
        // Pas de travaux retenus, pas de coût : on ne fige pas un chiffrage vide.
        $rapport = $this->archiver(null);

        self::assertNull($rapport->instantane()->chiffrage());
    }

    private function archiver(?Bouquet $bouquet): \App\BackOffice\Rapport\Rapport
    {
        self::bootKernel();
        $archiveur = self::getContainer()->get(ArchiveurRapport::class);
        self::assertInstanceOf(ArchiveurRapport::class, $archiveur);

        return $archiveur->archiver(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5),
            Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            $bouquet,
        );
    }
}
