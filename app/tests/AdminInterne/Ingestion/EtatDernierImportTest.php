<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Ingestion;

use App\AdminInterne\Ingestion\EtatDernierImport;
use App\AdminInterne\RapportBatch\RapportBatch;
use PHPUnit\Framework\TestCase;

/**
 * État du dernier import d'un département (#46) : la dernière ingestion archivée
 * (#131), relue avec les seuils des portes DAT-3 (appariement) et DAT-5 (fraîcheur).
 * L'écran ne recalcule rien — il rend visible ce que le pipeline a déjà mesuré.
 */
final class EtatDernierImportTest extends TestCase
{
    public function testIlReprendLesMetriquesDeLIngestionArchivee(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '31',
            mutationsInserees: 12000,
            ventesAppariees: 9000,
            echecs: ['31999'],
        ));

        self::assertSame('31', $etat->departement());
        self::assertSame(2024, $etat->millesime());
        self::assertSame(12000, $etat->mutationsInserees());
        self::assertSame(0.75, $etat->tauxAppariement());
        self::assertSame(['31999'], $etat->communesEnEchec());
    }

    public function testUnAppariementSousLeSeuilDeDat3EstSignale(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '09',
            mutationsInserees: 1000,
            ventesAppariees: 599,
        ));

        self::assertTrue($etat->appariementInsuffisant());
    }

    public function testLeSeuilExactDeDat3ResteAcceptable(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '09',
            mutationsInserees: 1000,
            ventesAppariees: 600,
        ));

        self::assertFalse($etat->appariementInsuffisant());
    }

    public function testUnDepartementSansAucuneVenteNEstPasUnAppariementEffondre(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '75',
            mutationsInserees: 0,
            ventesAppariees: 0,
        ));

        self::assertNull($etat->tauxAppariement());
        self::assertFalse($etat->appariementInsuffisant());
    }

    public function testUneSourceDePlusDeDouzeMoisEstPerimee(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '31',
            mutationLaPlusRecente: new \DateTimeImmutable('2025-08-01'),
        ));

        self::assertTrue($etat->sourcePerimee(new \DateTimeImmutable('2026-08-02')));
    }

    public function testUneSourceDeDouzeMoisPileEstEncoreFraiche(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '31',
            mutationLaPlusRecente: new \DateTimeImmutable('2025-08-01'),
        ));

        self::assertFalse($etat->sourcePerimee(new \DateTimeImmutable('2026-08-01')));
    }

    public function testLHeureDeLaJourneeNePerimePasUneSource(): void
    {
        $etat = new EtatDernierImport(self::ingestion(
            departement: '31',
            mutationLaPlusRecente: new \DateTimeImmutable('2025-08-01'),
        ));

        self::assertFalse($etat->sourcePerimee(new \DateTimeImmutable('2026-08-01 14:35:00')));
    }

    public function testUneFraicheurInconnueNEstPasUneSourcePerimee(): void
    {
        $etat = new EtatDernierImport(self::ingestion(departement: '31'));

        self::assertNull($etat->mutationLaPlusRecente());
        self::assertFalse($etat->sourcePerimee(new \DateTimeImmutable('2026-08-01')));
    }

    public function testUnDepartementEstEnAlerteDesQuUnSignalEstAuRouge(): void
    {
        $sain = new EtatDernierImport(self::ingestion(
            departement: '31',
            mutationsInserees: 1000,
            ventesAppariees: 900,
            mutationLaPlusRecente: new \DateTimeImmutable('2026-06-30'),
        ));
        $appariement = new EtatDernierImport(self::ingestion(
            departement: '09',
            mutationsInserees: 1000,
            ventesAppariees: 100,
            mutationLaPlusRecente: new \DateTimeImmutable('2026-06-30'),
        ));
        $fraicheur = new EtatDernierImport(self::ingestion(
            departement: '12',
            mutationsInserees: 1000,
            ventesAppariees: 900,
            mutationLaPlusRecente: new \DateTimeImmutable('2024-01-01'),
        ));
        $maintenant = new \DateTimeImmutable('2026-08-23');

        self::assertFalse($sain->enAlerte($maintenant));
        self::assertTrue($appariement->enAlerte($maintenant));
        self::assertTrue($fraicheur->enAlerte($maintenant));
    }

    public function testIlRenvoieVersLeRapportDeBatchArchive(): void
    {
        $etat = new EtatDernierImport(self::ingestion(departement: '31'));

        self::assertSame(7, $etat->rapport()->id());
    }

    public function testUnRapportDeCalculNEstPasUnImport(): void
    {
        $calcul = RapportBatch::calcul(
            id: 8,
            departement: '31',
            millesime: 2024,
            debut: new \DateTimeImmutable('2026-08-17 05:00:00'),
            fin: new \DateTimeImmutable('2026-08-17 06:00:00'),
            communesCalculees: 500,
            refus: 60,
            rejetsInvariants: 3,
            alertesBord: 2,
            inversionsDpe: 1,
            ecartsBlocages: 4,
            ecartsAlertes: 12,
            ordreCanonique: 'id_mutation',
            echecs: [],
        );

        $this->expectException(\InvalidArgumentException::class);

        new EtatDernierImport($calcul);
    }

    /**
     * @param list<string> $echecs
     */
    private static function ingestion(
        string $departement,
        int $mutationsInserees = 1000,
        int $ventesAppariees = 800,
        array $echecs = [],
        ?\DateTimeImmutable $mutationLaPlusRecente = null,
    ): RapportBatch {
        return RapportBatch::ingestion(
            id: 7,
            departement: $departement,
            millesime: 2024,
            debut: new \DateTimeImmutable('2026-08-17 03:00:00'),
            fin: new \DateTimeImmutable('2026-08-17 04:30:00'),
            communesTraitees: 580,
            mutationsInserees: $mutationsInserees,
            ventesAppariees: $ventesAppariees,
            communesReferencees: 586,
            contoursCharges: 585,
            epciCharges: 40,
            sansDonnees: 35,
            echecs: $echecs,
            mutationLaPlusRecente: $mutationLaPlusRecente,
        );
    }
}
