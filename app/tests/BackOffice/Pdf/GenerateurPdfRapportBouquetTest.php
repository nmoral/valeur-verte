<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Le bouquet de travaux retenu en rendez-vous (#29) figure dans le PDF : le
 * client repart avec la liste des travaux sur lesquels porte l'estimation.
 */
final class GenerateurPdfRapportBouquetTest extends KernelTestCase
{
    private const REFERENCE = 'VV-20260814-BQ0001';

    public function testLeHtmlPorteLeBouquetRetenuEtSesPostes(): void
    {
        $html = $this->htmlGenere(Bouquet::isolationSeule());

        self::assertStringContainsString('Travaux retenus', $html);
        self::assertStringContainsString('Isolation seule', $html);
        self::assertStringContainsString('Isolation des murs', $html);
    }

    public function testSansBouquetAucuneLigneDeTravauxNEstAjoutee(): void
    {
        $html = $this->htmlGenere(null);

        self::assertStringNotContainsString('Travaux retenus', $html);
    }

    private function htmlGenere(?Bouquet $bouquet): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $convertisseur = new ConvertisseurPdfEspion();

        (new GenerateurPdfRapport($twig, $convertisseur))->generer(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4),
            Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 147150, false),
            Branding::parDefaut(),
            ReferenceRapport::depuis(self::REFERENCE),
            null,
            $bouquet,
        );

        return $convertisseur->dernierHtml;
    }
}
