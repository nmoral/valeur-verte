<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\AdminInterne\JournalAudit\JournalAuditEnMemoire;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Tests\Notification\Double\MailerEnMemoire;
use App\Widget\DetecteurPicWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\JetonChargementWidget;
use App\Widget\JournalParcoursWidget;
use App\Widget\PagePublique;
use App\Widget\SeuilsAntiAbusWidget;
use App\Widget\SurveillanceAntiAbusWidget;
use App\Widget\TypeEvenementWidget;
use App\Widget\VerificateurTurnstile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

/**
 * Branchement de l'anti-abus réactif sur le parcours réel (#268, épic #6).
 *
 * La détection (#268) ne vaut que par ce qui l'alimente et ce qu'elle déclenche.
 * Deux câblages sont vérifiés ici, sur les vrais endpoints :
 *
 *  - **en entrée** : chaque rejet d'un contrôle préventif (#43) — honeypot, défi
 *    Turnstile, plafond — laisse un événement rattaché à la page. Sans ça, un
 *    matraquage entièrement bloqué resterait invisible : rien à mesurer ;
 *  - **en sortie** : les étapes qui alimentent un motif déclenchent la surveillance,
 *    les autres non — une saisie d'adresse ne doit pas coûter une requête d'analyse.
 */
final class BranchementAntiAbusReactifTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const string EMAIL_PRO = 'pro@agence-durand.fr';

    public function testUnHoneypotDeclencheLaisseUnEvenementDeRejet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'site_web' => 'http://spam.example',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->compter($token, TypeEvenementWidget::PiegeDeclenche));
    }

    public function testUnDefiTurnstileEchoueLaisseUnEvenementDeRejet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->turnstileConfigureQuiEchoue();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'jeton_chargement' => $this->jetonChargementValide(),
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->compter($token, TypeEvenementWidget::TurnstileEchoue));
    }

    public function testUnPlafondAtteintLaisseUnEvenementDeRejet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->epuiserLePlafondDeLaPage($token);

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie());

        self::assertResponseStatusCodeSame(429);
        self::assertSame(1, $this->compter($token, TypeEvenementWidget::PlafondAtteint));
    }

    /**
     * Le jeton de chargement (#43) est le contrôle le moins cher : rejouer un jeton
     * périmé ou fabriqué est du matraquage, et doit se voir comme tel.
     */
    public function testUnJetonDeChargementRefuseLaisseUnEvenementDeRejet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'jeton_chargement' => 'jeton-fabrique',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->compter($token, TypeEvenementWidget::JetonRefuse));
    }

    /**
     * La capture de lead partage le même garde-fou : un bot qui vise le mur de
     * contact plutôt que l'estimation doit être aussi visible.
     */
    public function testLaCaptureDeLeadJournaliseAussiSesRejets(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', $this->saisie([
            'nom' => 'Martin',
            'email' => 'martin@exemple.fr',
            'consentement' => 'on',
            'site_web' => 'http://spam.example',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->compter($token, TypeEvenementWidget::PiegeDeclenche));
    }

    public function testUneEtapeSurveilleeDeclencheLaSurveillance(): void
    {
        self::bootKernel();
        $page = $this->page();
        $espion = new NotificationAdminEspionne();
        $journal = $this->journalAvecSurveillance($espion);

        for ($i = 0; $i < 6; ++$i) {
            $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::EstimationAffichee);
        }

        self::assertCount(1, $espion->alertes, 'Le franchissement du seuil doit alerter.');
    }

    public function testUneEtapeNonSurveilleeNeDeclenchePasLaSurveillance(): void
    {
        self::bootKernel();
        $page = $this->page();
        $espion = new NotificationAdminEspionne();
        $journal = $this->journalAvecSurveillance($espion);

        for ($i = 0; $i < 20; ++$i) {
            $journal->enregistrer($page, self::SESSION, TypeEvenementWidget::AdresseSaisie);
        }

        self::assertSame([], $espion->alertes);
    }

    /**
     * Les types mesurés par un motif sont exactement ceux qui déclenchent la
     * surveillance : la liste se dérive des motifs, elle ne se recopie pas.
     */
    public function testSeulsLesTypesMesuresDeclenchentLaSurveillance(): void
    {
        self::assertTrue(TypeEvenementWidget::EstimationAffichee->declencheSurveillance());
        self::assertTrue(TypeEvenementWidget::LeadSoumis->declencheSurveillance());
        self::assertTrue(TypeEvenementWidget::PiegeDeclenche->declencheSurveillance());
        self::assertTrue(TypeEvenementWidget::JetonRefuse->declencheSurveillance());
        self::assertFalse(TypeEvenementWidget::WidgetCharge->declencheSurveillance());
        self::assertFalse(TypeEvenementWidget::AdresseSaisie->declencheSurveillance());
        self::assertFalse(TypeEvenementWidget::DetailDeplie->declencheSurveillance());
        self::assertFalse(TypeEvenementWidget::MurContactAffiche->declencheSurveillance());
    }

    private function journalAvecSurveillance(NotificationAdminEspionne $espion): JournalParcoursWidget
    {
        $conteneur = static::getContainer();
        $twig = $conteneur->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $utilisateurs = $conteneur->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $pages = $conteneur->get(\App\Widget\PagePubliqueRepository::class);
        self::assertInstanceOf(\App\Widget\PagePubliqueRepository::class, $pages);

        // Seuils resserrés : on teste le câblage, pas la calibration de production.
        $seuils = new SeuilsAntiAbusWidget(
            fenetreMinutes: 15,
            estimations: 5,
            leads: 3,
            rejets: 4,
            facteurCritique: 3,
            silenceMinutes: 60,
            challengeRenforceMinutes: 60,
        );
        $horloge = new MockClock(new \DateTimeImmutable('2026-08-22 12:00:00'));

        $surveillance = new SurveillanceAntiAbusWidget(
            new DetecteurPicWidget($this->evenements(), $seuils),
            $seuils,
            $espion,
            new MailerEnMemoire(),
            $utilisateurs,
            $twig,
            new JournalAuditEnMemoire(),
            $pages,
            $horloge,
        );

        return new JournalParcoursWidget($this->evenements(), $horloge, $surveillance);
    }

    private function compter(string $token, TypeEvenementWidget $type): int
    {
        $comptes = $this->evenements()->compterParTypeDepuis($token, new \DateTimeImmutable('@0'));

        return $comptes[$type->value] ?? 0;
    }

    private function evenements(): EvenementWidgetRepository
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return new EvenementWidgetRepository($registry);
    }

    private function turnstileConfigureQuiEchoue(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['success' => false]))]);
        static::getContainer()->set(VerificateurTurnstile::class, new VerificateurTurnstile('secret-de-test', $http));
    }

    private function jetonChargementValide(): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - JetonChargementWidget::AGE_MIN_SECONDES - 1)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }

    private function libererLimiteursWidget(string $token, string $ip = '127.0.0.1'): void
    {
        [$parIp, $parPage] = $this->limiteurs();
        $parIp->create($ip)->reset();
        $parPage->create($token)->reset();
    }

    private function epuiserLePlafondDeLaPage(string $token, string $ip = '127.0.0.1'): void
    {
        [$parIp, $parPage] = $this->limiteurs();
        $parIp->create($ip)->reset();
        $limiteur = $parPage->create($token);
        $limiteur->reset();
        while ($limiteur->consume(1)->isAccepted()) {
        }
    }

    /**
     * @return array{0: RateLimiterFactoryInterface, 1: RateLimiterFactoryInterface}
     */
    private function limiteurs(): array
    {
        $conteneur = static::getContainer();
        $parIp = $conteneur->get('limiter.widget_ip');
        $parPage = $conteneur->get('limiter.widget_page');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parIp);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parPage);

        return [$parIp, $parPage];
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function saisie(array $extra = []): array
    {
        return array_merge([
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'session_parcours' => self::SESSION,
        ], $extra);
    }

    private function creerPage(): string
    {
        return $this->page()->token();
    }

    private function page(): PagePublique
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
