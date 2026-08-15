<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\Estimation\ContexteCalcul;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Restitution\DeclarationsPro;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Le reste à charge figure dans le PDF (#26), calculé depuis l'instantané figé et
 * les déclarations du pro.
 *
 * Le PDF **doit** recevoir les déclarations : elles vivent hors de l'instantané, et
 * les oublier produirait un document qui contredit l'écran — exactement l'écart qui
 * décrédibilise un rapport devant un client.
 */
final class GenerateurPdfRapportResteAChargeTest extends KernelTestCase
{
    private const string REFERENCE = 'VV-20260815-RC0001';

    public function testLeResteAChargeFigureDansLePdf(): void
    {
        $html = $this->htmlGenere(DeclarationsPro::depuis(CategorieRevenu::Modeste, []));

        // 17 760 € de travaux (murs + menuiseries), gain E→C = 2 classes,
        // ménage modeste : 60 % de 17 760 = 10 656 €, reste 7 104 €.
        self::assertStringContainsString('Reste à charge', $html);
        self::assertStringContainsString('7 104', $html);
    }

    public function testSansCategorieDeclareeLePdfAfficheUneFourchette(): void
    {
        $html = $this->htmlGenere(DeclarationsPro::aucune());

        // De 80 % à 10 % de 17 760 € : reste entre 3 552 € et 15 984 €.
        self::assertStringContainsString('3 552', $html);
        self::assertStringContainsString('15 984', $html);
    }

    public function testLesRetouchesDuProSontRepercuteesDansLePdf(): void
    {
        $html = $this->htmlGenere(DeclarationsPro::depuis(
            CategorieRevenu::Modeste,
            [Poste::Menuiseries->name => 8400],
        ));

        // 12 000 + 8 400 = 20 400 € ; 60 % = 12 240 € ; reste 8 160 €.
        self::assertStringContainsString('8 160', $html);
    }

    public function testUnInstantaneSansChiffrageNAfficheAucunResteAcharge(): void
    {
        $html = $this->htmlGenere(DeclarationsPro::aucune(), fige: false);

        self::assertStringNotContainsString('Reste à charge', $html);
    }

    private function htmlGenere(DeclarationsPro $declarations, bool $fige = true): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $convertisseur = new ConvertisseurPdfEspion();

        (new GenerateurPdfRapport($twig, $convertisseur))
            ->depuisInstantane($this->instantane($fige), null, $declarations);

        return $convertisseur->dernierHtml;
    }

    private function instantane(bool $fige): InstantaneRapport
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);
        $bouquet = Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries);
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));

        return InstantaneRapport::creer(
            $saisie,
            Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 147150, false),
            ContexteCalcul::depuis(-0.20, 0.10, 17.22),
            Branding::parDefaut(),
            ReferenceRapport::depuis(self::REFERENCE),
            new \DateTimeImmutable('2026-08-15 10:00:00'),
            $bouquet,
            $fige ? Chiffrage::derive($bouquet, GrilleCout::parDefautNationale($periode), QuantitesTravaux::depuis($saisie)) : null,
            $fige ? BaremeAides::parDefautNational($periode) : null,
        );
    }
}
