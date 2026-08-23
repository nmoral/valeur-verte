<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

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
use App\BackOffice\Pdf\ConvertisseurPdf;
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\BackOffice\Restitution\DeclarationsPro;
use App\BackOffice\Restitution\Restitution;
use App\Tests\BackOffice\Pdf\Double\ConvertisseurPdfEspion;
use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le PDF téléchargé porte les déclarations du pro (#26).
 *
 * Les déclarations vivent hors de l'instantané : le contrôleur doit les passer
 * explicitement au générateur. S'il les oublie, le PDF affiche une fourchette
 * là où l'écran montre un montant — un écart entre les deux documents remis au
 * client suffit à ruiner la crédibilité du rapport.
 */
final class RapportPdfDeclarationsTest extends WebTestCase
{
    use ConnecteUnPro;

    private const string REFERENCE = 'VV-20260815-PD9999';

    public function testLePdfTelechargeRefleteLaCategorieDeclaree(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterPro($client);
        $espion = $this->espionner($client);
        $rapport = $this->archiver($tenantId);
        $rapport->declarerPar(DeclarationsPro::depuis(CategorieRevenu::Modeste, []));
        $this->rapports()->enregistrer($rapport);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/rapport/'.self::REFERENCE.'/pdf');

        self::assertResponseIsSuccessful();
        // 17 760 € de travaux, gain E→C, ménage modeste : reste 7 104 €.
        self::assertStringContainsString('7 104', $espion->dernierHtml);
    }

    public function testSansDeclarationLePdfAfficheLaFourchette(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterPro($client);
        $espion = $this->espionner($client);
        $this->archiver($tenantId);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/rapport/'.self::REFERENCE.'/pdf');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('3 552', $espion->dernierHtml);
        self::assertStringContainsString('15 984', $espion->dernierHtml);
    }

    private function espionner(KernelBrowser $client): ConvertisseurPdfEspion
    {
        $espion = new ConvertisseurPdfEspion();
        $client->getContainer()->set(ConvertisseurPdf::class, $espion);

        return $espion;
    }

    private function archiver(int $tenantId): Rapport
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);
        $bouquet = Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries);
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));

        $rapport = Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-15 10:00:00'),
            Source::Audit,
            InstantaneRapport::creer(
                $saisie,
                Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 147150, false),
                ContexteCalcul::depuis(-0.20, 0.10, 17.22),
                Branding::parDefaut(),
                ReferenceRapport::depuis(self::REFERENCE),
                new \DateTimeImmutable('2026-08-15 10:00:00'),
                $bouquet,
                Chiffrage::derive($bouquet, GrilleCout::parDefautNationale($periode), QuantitesTravaux::depuis($saisie)),
                BaremeAides::parDefautNational($periode),
            ),
            '/rapport/'.self::REFERENCE.'/pdf',
        );
        $this->rapports()->enregistrer(self::pourLeTenant($tenantId, $rapport));

        return $rapport;
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
