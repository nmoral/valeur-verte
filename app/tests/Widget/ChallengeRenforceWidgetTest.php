<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Estimation\CoefficientMaille;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Escalade visible du défi anti-bot après un pic (#268, épic #6).
 *
 * Le Turnstile de la page publique est **invisible** en temps normal — c'est la
 * promesse « premier chiffre sans friction » (#41). Après un pic, la surveillance
 * durcit le défi pour une durée bornée : le widget devient **visible**, le visiteur
 * valide une case, et le coût d'un bot explose. La friction est temporaire et
 * n'apparaît que sur la page concernée.
 *
 * Les deux formulaires publics — la saisie du bien et le mur de contact — doivent
 * durcir ensemble : durcir l'un en laissant l'autre invisible n'escalade rien.
 */
final class ChallengeRenforceWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';
    private const string CLE_PUBLIQUE = 'cle-publique-de-test';

    private ?string $cleInitiale = null;

    protected function setUp(): void
    {
        // Le widget Turnstile n'est rendu que si la clé publique est configurée :
        // sans elle, il n'y a pas de défi à durcir et le test ne dirait rien.
        $cle = $_SERVER['TURNSTILE_SITE_KEY'] ?? null;
        $this->cleInitiale = \is_string($cle) ? $cle : null;
        $_SERVER['TURNSTILE_SITE_KEY'] = self::CLE_PUBLIQUE;
        $_ENV['TURNSTILE_SITE_KEY'] = self::CLE_PUBLIQUE;
    }

    protected function tearDown(): void
    {
        if (null === $this->cleInitiale) {
            unset($_SERVER['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SITE_KEY']);
        } else {
            $_SERVER['TURNSTILE_SITE_KEY'] = $this->cleInitiale;
            $_ENV['TURNSTILE_SITE_KEY'] = $this->cleInitiale;
        }

        parent::tearDown();
    }

    public function testEnTempsNormalLeDefiResteInvisible(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $page = $this->creerPage();
        $this->libererLimiteursWidget($page->token());

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$page->token().'/agence-durand');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('class="cf-turnstile"', $html);
        self::assertStringNotContainsString('data-appearance', $html);
    }

    public function testSousChallengeRenforceLeDefiDevientVisible(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $page = $this->creerPage();
        $this->libererLimiteursWidget($page->token());
        $this->durcirLeDefi($page, '+2 hours');

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$page->token().'/agence-durand');

        self::assertStringContainsString('data-appearance="always"', (string) $client->getResponse()->getContent());
    }

    public function testLeDurcissementExpireDeLuiMeme(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $page = $this->creerPage();
        $this->libererLimiteursWidget($page->token());
        $this->durcirLeDefi($page, '-1 hour');

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$page->token().'/agence-durand');

        self::assertStringNotContainsString('data-appearance', (string) $client->getResponse()->getContent());
    }

    public function testLeMurDeContactDurcitAussi(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $page = $this->creerPage();
        $this->libererLimiteursWidget($page->token());
        $this->couvrir();
        $this->durcirLeDefi($page, '+2 hours');

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$page->token().'/estimation', [
            'adresse' => '12 rue des Lilas, Toulouse',
            'code_insee' => self::CODE_INSEE,
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'jeton_chargement' => $this->jetonChargementValide(),
        ]);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-widget="mur-contact"', $html);
        self::assertStringContainsString('data-appearance="always"', $html);
    }

    private function durcirLeDefi(PagePublique $page, string $decalage): void
    {
        $page->renforcerChallengeJusqua(new \DateTimeImmutable($decalage));
        $this->em()->flush();
    }

    private function jetonChargementValide(): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - JetonChargementWidget::AGE_MIN_SECONDES - 1)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }

    private function couvrir(): void
    {
        $conteneur = static::getContainer();
        $coefficients = $conteneur->get(FournisseurCoefficientMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurCoefficientMailleEnMemoire::class, $coefficients);
        $coefficients->definir(self::CODE_INSEE, CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10, 'E' => -0.20],
        ));
        $prix = $conteneur->get(FournisseurPrixMailleEnMemoire::class);
        self::assertInstanceOf(FournisseurPrixMailleEnMemoire::class, $prix);
        $prix->definir(self::CODE_INSEE, 1635.0);
    }

    private function libererLimiteursWidget(string $token, string $ip = '127.0.0.1'): void
    {
        $conteneur = static::getContainer();
        $parIp = $conteneur->get('limiter.widget_ip');
        $parPage = $conteneur->get('limiter.widget_page');
        $parEmission = $conteneur->get('limiter.widget_emission');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parIp);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parPage);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parEmission);
        $parIp->create($ip)->reset();
        $parPage->create($token)->reset();
        $parEmission->create($ip)->reset();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function creerPage(): PagePublique
    {
        $tenants = static::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $page = PagePublique::creer($tenantId, bin2hex(random_bytes(5)), 'agence-durand');
        $this->em()->persist($page);
        $this->em()->flush();

        return $page;
    }
}
