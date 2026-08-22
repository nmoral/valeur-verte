<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\Notification\NotificationAdmin;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use App\Tests\Notification\Double\NotificationAdminEspionne;
use App\Widget\DetecteurPicWidget;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use App\Widget\SeuilsAntiAbusWidget;
use App\Widget\SurveillanceAntiAbusWidget;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;

/**
 * Anti-abus réactif d'une page publique (#268, épic #6).
 *
 * Ce que le préventif (#43) ne sait pas faire : voir qu'une page part en vrille et
 * réagir. La surveillance relie la détection (#268) aux trois suites possibles —
 * **alerter** (admin interne + pro concerné), **durcir** (défi Turnstile visible),
 * **couper** (suspension d'office au-delà du seuil critique) — et inscrit chaque
 * déclenchement au journal d'audit (#50).
 *
 * Deux garde-fous comptent autant que la détection : une alerte ne se répète pas à
 * chaque requête du pic (fenêtre de silence), et un trafic normal ne déclenche rien.
 */
final class SurveillanceAntiAbusWidgetTest extends KernelTestCase
{
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string EMAIL_PRO = 'pro@agence-durand.fr';

    public function testUnTraficNormalNeDeclencheRien(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 3, '11:55:00');
        $espion = new NotificationAdminEspionne();
        $mailer = new MailerEnMemoire();
        $journal = new JournalAuditEnMemoire();

        $this->surveillance($espion, $mailer, $journal)->evaluer($page);

        self::assertSame([], $espion->alertes);
        self::assertSame([], $mailer->envoyes());
        self::assertSame([], $journal->evenementsRecents());
        self::assertFalse($page->exigeChallengeVisible(new \DateTimeImmutable('2026-08-22 12:00:00')));
    }

    public function testUnPicAlerteLAdminEtLeProEtDurcitLeDefi(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');
        $espion = new NotificationAdminEspionne();
        $mailer = new MailerEnMemoire();

        $this->surveillance($espion, $mailer)->evaluer($page);

        self::assertCount(1, $espion->alertes, 'L\'admin interne doit être alerté.');
        self::assertCount(1, $mailer->envoyes(), 'Le pro concerné doit être prévenu.');
        self::assertSame(self::EMAIL_PRO, $mailer->envoyes()[0]->destinataire());
        self::assertTrue($page->exigeChallengeVisible(new \DateTimeImmutable('2026-08-22 12:30:00')));
        self::assertFalse($page->estSuspendue(), 'Un pic non critique alerte et durcit, il ne coupe pas.');
    }

    public function testLeDurcissementDuDefiExpireAvecLaFenetre(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');

        $this->surveillance()->evaluer($page);

        self::assertFalse($page->exigeChallengeVisible(new \DateTimeImmutable('2026-08-22 14:00:00')));
    }

    public function testUnPicCritiqueCoupeLaPage(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 15, '11:55:00');
        $espion = new NotificationAdminEspionne();

        $this->surveillance($espion)->evaluer($page);

        self::assertTrue($page->estSuspendue());
        self::assertNull($this->pages()->trouverActiveParJeton($page->token()));
        self::assertCount(1, $espion->alertes);
    }

    public function testChaqueDeclenchementEstInscritAuJournalDAudit(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 15, '11:55:00');
        $journal = new JournalAuditEnMemoire();

        $this->surveillance(journal: $journal)->evaluer($page);

        $evenements = $journal->evenementsRecents();
        self::assertCount(1, $evenements);
        self::assertSame('widget.pic_anti_abus', $evenements[0]->action());
        self::assertSame($page->token(), $evenements[0]->cible());
        self::assertSame('estimations', $evenements[0]->contexte()['motif'] ?? null);
        self::assertTrue($evenements[0]->contexte()['suspendue'] ?? false);
    }

    /**
     * Le pic dure : sans fenêtre de silence, chaque requête réémettrait l'alerte et
     * noierait l'admin comme le pro. Une seule alerte par fenêtre.
     */
    public function testLAlerteNeSeRepetePasDansLaFenetreDeSilence(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');
        $espion = new NotificationAdminEspionne();
        $mailer = new MailerEnMemoire();
        $horloge = new MockClock(new \DateTimeImmutable('2026-08-22 12:00:00'));
        $surveillance = $this->surveillance($espion, $mailer, horloge: $horloge);

        $surveillance->evaluer($page);
        $horloge->sleep(600);
        $surveillance->evaluer($page);

        self::assertCount(1, $espion->alertes);
        self::assertCount(1, $mailer->envoyes());
    }

    public function testLAlerteRepartApresLaFenetreDeSilence(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '11:55:00');
        $espion = new NotificationAdminEspionne();
        $horloge = new MockClock(new \DateTimeImmutable('2026-08-22 12:00:00'));
        $surveillance = $this->surveillance($espion, horloge: $horloge);

        $surveillance->evaluer($page);
        // Au-delà de la fenêtre de silence, et le pic est toujours là (les
        // événements restent dans la fenêtre glissante observée).
        $horloge->modify('2026-08-22 13:05:00');
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 6, '13:00:00');
        $surveillance->evaluer($page);

        self::assertCount(2, $espion->alertes);
    }

    /**
     * Une page déjà coupée n'a plus rien à escalader : on ne réveille personne.
     */
    public function testUnePageDejaSuspendueNeReAlertePas(): void
    {
        self::bootKernel();
        $page = $this->creerPage();
        $page->suspendre(new \DateTimeImmutable('2026-08-22 11:00:00'));
        $this->journaliser($page, TypeEvenementWidget::EstimationAffichee, 15, '11:55:00');
        $espion = new NotificationAdminEspionne();

        $this->surveillance($espion)->evaluer($page);

        self::assertSame([], $espion->alertes);
    }

    private function surveillance(
        ?NotificationAdmin $notification = null,
        ?MailerEnMemoire $mailer = null,
        ?JournalAuditEnMemoire $journal = null,
        ?MockClock $horloge = null,
    ): SurveillanceAntiAbusWidget {
        $conteneur = static::getContainer();
        $twig = $conteneur->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $utilisateurs = $conteneur->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);

        $seuils = $this->seuils();

        return new SurveillanceAntiAbusWidget(
            new DetecteurPicWidget($this->evenements(), $seuils),
            $seuils,
            $notification ?? new NotificationAdminEspionne(),
            $mailer ?? new MailerEnMemoire(),
            $utilisateurs,
            $twig,
            $journal ?? new JournalAuditEnMemoire(),
            $this->pages(),
            $horloge ?? new MockClock(new \DateTimeImmutable('2026-08-22 12:00:00')),
        );
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

    private function pages(): PagePubliqueRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new PagePubliqueRepository($registry);
    }

    private function creerPage(): PagePublique
    {
        $conteneur = static::getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $em->persist($page);

        $utilisateurs = $conteneur->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $pro = new User(self::EMAIL_PRO, ['ROLE_PRO']);
        $pro->rattacherAuTenant($tenantId);
        $utilisateurs->enregistrer($pro);

        $em->flush();

        return $page;
    }
}
