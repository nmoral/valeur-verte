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
use App\BackOffice\Pdf\ReferenceRapport;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\InstantaneRapport;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\BackOffice\Restitution\Restitution;
use App\Controller\BackOffice\AuditController;
use App\Tests\Support\ConnecteUnPro;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Reste à charge sur la restitution (#26). L'écran lit **le même instantané figé**
 * que le PDF : sans ça les deux pourraient diverger, ce qui décrédibilise le
 * rapport devant le client.
 */
final class AuditResultatResteAChargeTest extends WebTestCase
{
    use ConnecteUnPro;

    private const string REFERENCE = 'VV-20260815-RC9999';

    public function testSansCategorieDeclareeLeResteAChargeEstUneFourchette(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);
        $this->poserContexte($client);

        $client->request('GET', 'http://'.self::HOTE_PRO.'/audit/resultat');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Reste à charge');
        // 17 760 € de travaux, gain de 2 classes : de 80 % à 10 % d'aide.
        self::assertSelectorTextContains('body', '3 552');
        self::assertSelectorTextContains('body', '15 984');
    }

    public function testDeclarerUneCategorieResserreLaFourchetteEnUnMontant(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);
        $this->poserContexte($client);

        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
        ]);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '7 104');
        self::assertSelectorTextNotContains('body', '15 984');
    }

    public function testUneRetoucheDeChiffrageChangeLeResteAcharge(): void
    {
        $client = static::createClient();
        $this->connecterPro($client);
        $this->poserContexte($client);

        $client->request('POST', 'http://'.self::HOTE_PRO.'/audit/resultat/declarations', [
            'categorie_revenu' => CategorieRevenu::Modeste->name,
            'chiffrage' => [Poste::Menuiseries->name => '8400'],
        ]);
        $client->followRedirect();

        // 12 000 + 8 400 = 20 400 € ; 60 % d'aide ; reste 8 160 €.
        self::assertSelectorTextContains('body', '8 160');
    }

    private function poserContexte(KernelBrowser $client): void
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, 31000 Toulouse', 100, 0, Dpe::E, Dpe::C, 5);
        $restitution = Restitution::depuis(40700, 30000, 51000, 476, 25, 2024, 147150, false);
        $bouquet = Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries);
        $periode = PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'));

        $this->rapports()->enregistrer(self::pourLeTenant($this->connecterPro($client), Rapport::creer(
            self::REFERENCE,
            new \DateTimeImmutable('2026-08-15 10:00:00'),
            Source::Audit,
            InstantaneRapport::creer(
                $saisie,
                $restitution,
                ContexteCalcul::depuis(-0.20, 0.10, 17.22),
                Branding::parDefaut(),
                ReferenceRapport::depuis(self::REFERENCE),
                new \DateTimeImmutable('2026-08-15 10:00:00'),
                $bouquet,
                Chiffrage::derive($bouquet, GrilleCout::parDefautNationale($periode), QuantitesTravaux::depuis($saisie)),
                BaremeAides::parDefautNational($periode),
            ),
            '/rapport/'.self::REFERENCE.'/pdf',
        )));

        $session = $this->sessionDuClient($client);
        $session->set(AuditController::SESSION_RESULTAT, [
            'saisie' => $saisie,
            'restitution' => $restitution,
            'reference' => self::REFERENCE,
            'bouquet' => $bouquet,
        ]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
