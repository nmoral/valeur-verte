<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Pdf;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\PalierFiabilite;
use App\BackOffice\Restitution\PorteeEstimation;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Génération du PDF à l'en-tête du pro (#32, §4/§10.4). Le contenu est rendu par
 * Twig puis converti par un port ; on exerce ici le rendu HTML via un double du
 * convertisseur qui restitue le HTML, sans dépendre du moteur PDF. On vérifie
 * que la restitution (n, rayon, millésime), la marque, le bandeau d'alerte du
 * mode dégradé (§5.3) et l'identifiant de rapport (§9.6) sont bien portés.
 */
final class GenerateurPdfRapportTest extends KernelTestCase
{
    private const REFERENCE = 'VV-20260813-A1B2C3';

    public function testLeHtmlPorteLAdresseEtLeBienAudite(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
        self::assertStringContainsString('90', $html);
        self::assertStringContainsString('500', $html);
    }

    public function testLeHtmlPorteLeMontantEtLaFourchette(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('69 972', $html);
        self::assertStringContainsString('55 000', $html);
        self::assertStringContainsString('85 000', $html);
    }

    public function testLeHtmlPorteLeContexteStatistique(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('134', $html);
        self::assertStringContainsString('10', $html);
        self::assertStringContainsString('2024', $html);
    }

    public function testLeHtmlPorteLaMarqueDuPro(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('1234-RGE-31', $html);
        self::assertStringContainsString('05 61 00 00 00', $html);
        self::assertStringContainsString('SARL Rénov Toulouse — RCS 812 345 678', $html);
        self::assertStringContainsStringIgnoringCase('#B8480F', $html);
        self::assertStringContainsString('https://cdn.example.com/logo.png', $html);
    }

    public function testLeHtmlPorteLeNomDuProDansLEntete(): void
    {
        // #110 : le rapport est à l'en-tête du pro — son nom d'entreprise habille
        // le bandeau, à la place du nom de l'outil (« Valeur Verte »).
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('class="marque">Rénov Toulouse', $html);
        self::assertStringNotContainsString('class="marque">Valeur Verte', $html);
    }

    public function testLesBadgesDpeSontDesEtiquettesSvg(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        // Saisie E → C : deux étiquettes DPE, rendues en image SVG inline par
        // l'extension Twig (l'échelle de couleurs est testée à part).
        self::assertSame(2, substr_count($html, 'data:image/svg+xml;base64,'));
        self::assertStringContainsString('alt="DPE E"', $html);
        self::assertStringContainsString('alt="DPE C"', $html);
    }

    public function testLIdentifiantDeRapportFigureDansLeDocument(): void
    {
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString(self::REFERENCE, $html);
    }

    public function testLaDateDuRapportFigureDansLeDocument(): void
    {
        // #110 : un rapport remis au client doit être daté. La date vient de
        // l'instantané (genereLe), pour rester reproductible à la régénération.
        $convertisseur = $this->convertisseurEspion();
        $this->generateur($convertisseur)->generer(
            $this->saisie(),
            $this->restitutionSolide(),
            $this->branding(),
            ReferenceRapport::depuis(self::REFERENCE),
            date: new \DateTimeImmutable('2026-08-17 10:00:00'),
        );

        self::assertStringContainsString('17/08/2026', $convertisseur->dernierHtml);
    }

    public function testLaSituationDuMenageDeclareeFigureDansLeDocument(): void
    {
        // #26/#110 : quand le pro a précisé la situation du ménage, le PDF doit la
        // porter — c'est ce qui explique le montant d'aides retenu.
        $convertisseur = $this->convertisseurEspion();
        $this->generateur($convertisseur)->generer(
            $this->saisie(),
            $this->restitutionSolide(),
            $this->branding(),
            ReferenceRapport::depuis(self::REFERENCE),
            situationMenage: 'Revenus modestes',
        );

        self::assertStringContainsString('Revenus modestes', $convertisseur->dernierHtml);
    }

    public function testLeRapportPorteLaMethodeEtLaMentionLegale(): void
    {
        // #110 : le rapport s'explique de lui-même (méthode + sources) et pose le
        // cadre — une estimation statistique n'est pas une évaluation immobilière.
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsStringIgnoringCase('ventes comparables', $html);
        self::assertStringContainsString('ne constitue pas une évaluation immobilière', $html);
    }

    public function testLeRapportCiteLesSourcesPubliquesEtLeurLicence(): void
    {
        // #59 : DVF et DPE sont diffusés sous licence ouverte Etalab, qui impose la
        // paternité — nommer la source (producteurs DGFiP/ADEME) et la licence dans
        // le document diffusé. Le PDF de rapport est l'une des surfaces visées.
        $html = $this->htmlGenere($this->restitutionSolide());

        self::assertStringContainsString('DVF', $html);
        self::assertStringContainsString('DGFiP', $html);
        self::assertStringContainsString('ADEME', $html);
        self::assertStringContainsStringIgnoringCase('licence ouverte Etalab', $html);
    }

    public function testUneEstimationFragileAfficheLeBandeauDAlerte(): void
    {
        $html = $this->htmlGenere($this->restitutionFragile());

        self::assertStringContainsStringIgnoringCase('fragile', $html);
        self::assertMatchesRegularExpression('/prudence|repli|effectif/i', $html);
    }

    public function testUneEstimationEpciDegradeeAfficheLaPorteeEtLesBandeaux(): void
    {
        // Cran EPCI dégradé (#169), classe G servie via la bande F : le PDF —
        // régénéré depuis l'instantané — situe l'intercommunalité, signale le
        // tier dégradé et la substitution de bande.
        $html = $this->htmlGenere(Restitution::depuis(
            montantEuros: 12000,
            borneBasseEuros: 6000,
            borneHauteEuros: 18000,
            n: 60,
            rayonKm: null,
            millesime: 2024,
            prixBaseEuros: 100000,
            prixSaisiParLePro: false,
            portee: PorteeEstimation::Epci,
            palier: PalierFiabilite::Degrade,
            bandesAppliquees: ['G' => 'F'],
        ));

        self::assertStringContainsString('intercommunal (EPCI)', $html);
        self::assertStringContainsStringIgnoringCase('dégradée', $html);
        self::assertStringContainsString('bande F', $html);
    }

    public function testUneEstimationIndisponibleAfficheUnMessageSansMontant(): void
    {
        $html = $this->htmlGenere(null);

        self::assertStringContainsStringIgnoringCase('indisponible', $html);
        self::assertStringNotContainsString('69 972', $html);
        // La marque et l'identifiant restent présents même sans chiffre.
        self::assertStringContainsString(self::REFERENCE, $html);
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
    }

    public function testLePdfEstLeResultatDuConvertisseur(): void
    {
        $convertisseur = $this->convertisseurEspion();
        $pdf = $this->generateur($convertisseur)->generer(
            $this->saisie(),
            $this->restitutionSolide(),
            $this->branding(),
            ReferenceRapport::depuis(self::REFERENCE),
        );

        self::assertStringStartsWith('PDF::', $pdf);
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $convertisseur->dernierHtml);
    }

    private function htmlGenere(?Restitution $restitution): string
    {
        $convertisseur = $this->convertisseurEspion();
        $this->generateur($convertisseur)->generer(
            $this->saisie(),
            $restitution,
            $this->branding(),
            ReferenceRapport::depuis(self::REFERENCE),
        );

        return $convertisseur->dernierHtml;
    }

    private function generateur(ConvertisseurPdfEspion $convertisseur): GenerateurPdfRapport
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return new GenerateurPdfRapport($twig, $convertisseur);
    }

    private function convertisseurEspion(): ConvertisseurPdfEspion
    {
        return new ConvertisseurPdfEspion();
    }

    private function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, Toulouse', 90, 500, Dpe::E, Dpe::C, 4);
    }

    private function branding(): Branding
    {
        return Branding::creer(
            Couleur::depuisHex('#B8480F'),
            '1234-RGE-31',
            '05 61 00 00 00',
            'SARL Rénov Toulouse — RCS 812 345 678',
            'https://cdn.example.com/logo.png',
            'Rénov Toulouse',
        );
    }

    private function restitutionSolide(): Restitution
    {
        return Restitution::depuis(69972, 55000, 85000, 134, 10, 2024, 147150, false);
    }

    private function restitutionFragile(): Restitution
    {
        return Restitution::depuis(69972, 40000, 100000, 40, 10, 2024, 147150, false);
    }
}
