<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\Restitution\EffetDeSeuil;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Le PDF (#32) porte la table de l'effet de seuil (#27) reconstruite depuis
 * l'instantané figé : mêmes paliers que l'écran, régénérés à l'identique.
 */
final class GenerateurPdfRapportEffetDeSeuilTest extends KernelTestCase
{
    public function testLeHtmlPorteLaTableDeSeuilAvecLaLigneCMiseEnAvant(): void
    {
        $html = $this->htmlDepuisInstantane($this->instantane($this->effetDeSeuil()));

        self::assertStringContainsString('effet-seuil', $html);
        self::assertStringContainsString('32 579', $html); // E→D
        self::assertStringContainsString('51 482', $html); // E→C (seuil)
        self::assertStringContainsString('palier-seuil', $html);
    }

    public function testUnInstantaneSansEffetDeSeuilNAffichePasDeTable(): void
    {
        $html = $this->htmlDepuisInstantane($this->instantane(null));

        self::assertStringNotContainsString('effet-seuil', $html);
    }

    private function htmlDepuisInstantane(InstantaneRapport $instantane): string
    {
        $convertisseur = new ConvertisseurPdfEspion();
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        (new GenerateurPdfRapport($twig, $convertisseur))->depuisInstantane($instantane);

        return $convertisseur->dernierHtml;
    }

    private function effetDeSeuil(): EffetDeSeuil
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, ['E' => -0.20, 'D' => 0.0, 'C' => 0.10], 147150);
        self::assertNotNull($effet);

        return $effet;
    }

    private function instantane(?EffetDeSeuil $effetDeSeuil): InstantaneRapport
    {
        return InstantaneRapport::creer(
            SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4),
            Restitution::depuis(51482, 42000, 60000, 134, 10, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            Branding::parDefaut(),
            ReferenceRapport::depuis('VV-20260816-SEUIL2'),
            new \DateTimeImmutable('2026-08-16 10:00:00'),
            effetDeSeuil: $effetDeSeuil,
        );
    }
}
