<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tenant\TenantRepository;
use App\Widget\DetecteurPicWidget;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\MotifPicWidget;
use App\Widget\PagePublique;
use App\Widget\SeuilsAntiAbusWidget;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Détection des pics d'activité sur une page publique (#268, épic #6).
 *
 * L'anti-abus préventif (#43) borne chaque requête ; le volet réactif regarde la
 * **forme du trafic** sur une fenêtre glissante et signale ce qui sort de l'ordinaire :
 * trop d'estimations, trop de leads, trop de rejets. Le détecteur ne décide rien —
 * il mesure et qualifie (motif, mesure, seuil, criticité) ; l'escalade est ailleurs.
 *
 * Deux exigences structurantes : un trafic normal ne déclenche **rien** (une alerte
 * qui crie tout le temps n'est plus lue), et le dépassement le plus violent l'emporte
 * quand plusieurs seuils sautent ensemble.
 */
final class DetecteurPicWidgetTest extends KernelTestCase
{
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string MAINTENANT = '2026-08-22 12:00:00';

    public function testUnTraficSousLesSeuilsNeDeclencheRien(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 3, '11:55:00');

        self::assertNull($this->detecter($page));
    }

    public function testTropDEstimationsSurLaFenetreEstUnPic(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');

        $pic = $this->detecter($page);

        self::assertNotNull($pic);
        self::assertSame(MotifPicWidget::Estimations, $pic->motif());
        self::assertSame(6, $pic->mesure());
        self::assertSame(5, $pic->seuil());
        self::assertFalse($pic->estCritique());
    }

    public function testTropDeLeadsSurLaFenetreEstUnPic(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::LeadSoumis, 4, '11:55:00');

        $pic = $this->detecter($page);

        self::assertNotNull($pic);
        self::assertSame(MotifPicWidget::Leads, $pic->motif());
    }

    /**
     * Les trois contrôles préventifs comptent ensemble : un attaquant qui alterne
     * les rejets ne doit pas passer sous trois compteurs séparés.
     */
    public function testLesRejetsDesTroisControlesSAdditionnent(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::PlafondAtteint, 2, '11:55:00');
        $this->journaliser($page, TypeEvenementWidget::PiegeDeclenche, 2, '11:56:00');
        $this->journaliser($page, TypeEvenementWidget::TurnstileEchoue, 1, '11:57:00');

        $pic = $this->detecter($page);

        self::assertNotNull($pic);
        self::assertSame(MotifPicWidget::Rejets, $pic->motif());
        self::assertSame(5, $pic->mesure());
    }

    public function testLeTraficAnterieurALaFenetreNeComptePas(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 10, '11:40:00');

        self::assertNull($this->detecter($page));
    }

    public function testUnDepassementMassifEstCritique(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 15, '11:55:00');

        $pic = $this->detecter($page);

        self::assertNotNull($pic);
        self::assertTrue($pic->estCritique());
    }

    /**
     * Quand plusieurs seuils sautent, c'est le dépassement le plus violent
     * (rapport mesure/seuil) qui qualifie le pic — pas le premier motif examiné.
     */
    public function testLeDepassementLePlusViolentQualifieLePic(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');
        $this->journaliser($page, TypeEvenementWidget::LeadSoumis, 12, '11:56:00');

        $pic = $this->detecter($page);

        self::assertNotNull($pic);
        self::assertSame(MotifPicWidget::Leads, $pic->motif());
        self::assertSame(12, $pic->mesure());
    }

    public function testLesSeuilsParDefautLaissentPasserUnTraficDePageVitrine(): void
    {
        $seuils = SeuilsAntiAbusWidget::parDefaut();

        self::assertGreaterThan(0, $seuils->fenetreMinutes());
        self::assertGreaterThan($seuils->leads(), $seuils->estimations());
        self::assertGreaterThan(1, $seuils->facteurCritique());
        self::assertGreaterThan(0, $seuils->silenceMinutes());
    }

    private function detecter(PagePublique $page): ?\App\Widget\PicWidget
    {
        $detecteur = new DetecteurPicWidget($this->evenements(), $this->seuils());

        return $detecteur->detecter($page->token(), new \DateTimeImmutable(self::MAINTENANT));
    }

    /**
     * Seuils resserrés : on teste la règle, pas la calibration de production.
     */
    private function seuils(): SeuilsAntiAbusWidget
    {
        return new SeuilsAntiAbusWidget(
            fenetreMinutes: 15,
            estimations: 5,
            leads: 3,
            rejets: 4,
            facteurCritique: 3,
            silenceMinutes: 60,
            challengeRenforceMinutes: 60,
        );
    }

    private function journaliser(PagePublique $page, TypeEvenementWidget $type, int $combien, string $heure): void
    {
        for ($i = 0; $i < $combien; ++$i) {
            $this->evenements()->enregistrer(EvenementWidget::survenu(
                $page->tenantIdProprietaire(),
                $page->token(),
                self::SESSION,
                $type,
                new \DateTimeImmutable('2026-08-22 '.$heure),
            ));
        }
    }

    private function evenements(): EvenementWidgetRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new EvenementWidgetRepository($registry);
    }

    private function creerPage(): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $em->persist($page);
        $em->flush();

        return $page;
    }
}
