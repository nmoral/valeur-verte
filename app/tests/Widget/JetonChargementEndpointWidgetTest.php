<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
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
 * Exigence d'un jeton de chargement valide sur les endpoints publics (#43).
 *
 * L'estimation (et la capture) n'acceptent une soumission que si elle porte un
 * `jeton_chargement` signé, ni trop récent (timing) ni expiré (anti-rejeu). Le
 * formulaire rendu émet un tel jeton. À défaut ou hors fenêtre : 422.
 */
final class JetonChargementEndpointWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testSansJetonLEstimationEstRejetee(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie());

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnJetonTropRecentEstRejete(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();

        // Jeton émis « à l'instant » : âge ~0 < âge min → soumission de bot.
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'jeton_chargement' => $this->jetonEmisIlYa(0),
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnJetonValideLaisseEstimer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'jeton_chargement' => $this->jetonEmisIlYa(JetonChargementWidget::AGE_MIN_SECONDES + 1),
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-widget="valeur-verte"]');
    }

    public function testLeFormulaireEmetUnJeton(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-widget="estimation"] input[name="jeton_chargement"]');
        $valeur = $client->getCrawler()->filter('input[name="jeton_chargement"]')->attr('value');
        self::assertNotEmpty($valeur);
    }

    private function jetonEmisIlYa(int $secondes): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - $secondes)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }

    private function creerPage(): string
    {
        $conteneur = static::getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), null, null, null, null, 'Agence Durand');
        $branding->setTenantId($tenantId);
        $em->persist($branding);
        $em->persist(PagePublique::creer($tenantId, $token = bin2hex(random_bytes(5)), 'agence-durand'));
        $em->flush();

        return $token;
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
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parIp);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $parPage);
        $parIp->create($ip)->reset();
        $parPage->create($token)->reset();
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
        ], $extra);
    }
}
