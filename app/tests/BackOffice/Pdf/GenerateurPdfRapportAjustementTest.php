<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\AjustementProfessionnel;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Attribution de l'ajustement du pro dans le PDF (#30, §4). Le document reprend
 * la mesure statistique intacte, ajoute la ligne d'ajustement — explicitement
 * attribuée au professionnel, avec son motif — et affiche l'estimation retenue.
 * Sans ajustement, le PDF reste exactement celui d'aujourd'hui.
 */
final class GenerateurPdfRapportAjustementTest extends KernelTestCase
{
    private const REFERENCE = 'VV-20260814-AJ0001';
    private const MESURE = 40700;

    public function testLeHtmlPorteLaLigneDAjustementAttribueeAuPro(): void
    {
        $html = $this->htmlGenere(AjustementProfessionnel::depuis(-5000, 'État général du bien'));

        self::assertStringContainsString('Ajustement du professionnel', $html);
        self::assertStringContainsString('État général du bien', $html);
        self::assertStringContainsString('5 000', $html);
    }

    public function testLeHtmlPorteLaMesureIntacteEtLEstimationRetenue(): void
    {
        $html = $this->htmlGenere(AjustementProfessionnel::depuis(-5000, 'État général du bien'));

        // Mesure statistique conservée telle quelle…
        self::assertStringContainsString('40 700', $html);
        // …et estimation retenue = mesure + ajustement.
        self::assertStringContainsString('Estimation retenue', $html);
        self::assertStringContainsString('35 700', $html);
    }

    public function testSansAjustementAucuneLigneNEstAjoutee(): void
    {
        $html = $this->htmlGenere(null);

        self::assertStringNotContainsString('Ajustement du professionnel', $html);
        self::assertStringNotContainsString('Estimation retenue', $html);
    }

    private function htmlGenere(?AjustementProfessionnel $ajustement): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $convertisseur = new ConvertisseurPdfEspion();

        (new GenerateurPdfRapport($twig, $convertisseur))->generer(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4),
            Restitution::depuis(self::MESURE, 30000, 51000, 476, 25, 2024, 147150, false),
            Branding::parDefaut(),
            ReferenceRapport::depuis(self::REFERENCE),
            $ajustement,
        );

        return $convertisseur->dernierHtml;
    }
}
