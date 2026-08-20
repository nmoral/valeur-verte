<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\BackOffice\Estimation\CoefficientMaille;
use App\Tenant\TenantRepository;
use App\Tests\BackOffice\Estimation\Double\FournisseurCoefficientMailleEnMemoire;
use App\Tests\BackOffice\Estimation\Double\FournisseurPrixMailleEnMemoire;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate limiting multi-dimensionnel du widget public (#43, lot 1, épic #6).
 *
 * Les endpoints publics coûteux — estimation (calcul) et capture de lead (emails)
 * — sont plafonnés par IP (usage individuel) et par page/tenant (une page ne peut
 * pas exploser à elle seule). Au dépassement : 429. La détection réactive et la
 * notification admin relèvent d'une issue dédiée (#268) — ici, seul le plafond.
 */
final class RateLimitWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testAuDelaDuPlafondParIpLEstimationEst429(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $ip = '203.0.113.10';
        $this->epuiser('limiter.widget_ip', $ip);

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie());

        self::assertResponseStatusCodeSame(429);
    }

    public function testAuDelaDuPlafondParIpLaCaptureDeLeadEst429(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $ip = '203.0.113.11';
        $this->epuiser('limiter.widget_ip', $ip);

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', $this->saisie([
            'nom' => 'Martin',
            'email' => 'martin@exemple.fr',
            'consentement' => 'on',
        ]));

        self::assertResponseStatusCodeSame(429);
    }

    public function testLePlafondParPageBloqueMemeUneNouvelleIp(): void
    {
        // Le plafond global de la page protège d'un pic distribué : même une IP
        // vierge est refusée une fois la page saturée.
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->epuiser('limiter.widget_page', $token);

        $client->setServerParameter('REMOTE_ADDR', '203.0.113.12');
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie());

        self::assertResponseStatusCodeSame(429);
    }

    public function testEnDecaDesPlafondsLEstimationPasse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $ip = '203.0.113.13';
        // Plafonds remis à zéro pour cette IP et cette page (isolation inter-runs).
        $this->reinitialiser('limiter.widget_ip', $ip);
        $this->reinitialiser('limiter.widget_page', $token);
        $this->couvrir();

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-widget="valeur-verte"]');
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

    private function epuiser(string $service, string $cle): void
    {
        $limiteur = $this->limiteur($service)->create($cle);
        $limiteur->reset();
        while ($limiteur->consume(1)->isAccepted()) {
        }
    }

    private function reinitialiser(string $service, string $cle): void
    {
        $this->limiteur($service)->create($cle)->reset();
    }

    private function limiteur(string $service): RateLimiterFactory
    {
        $fabrique = static::getContainer()->get($service);
        self::assertInstanceOf(RateLimiterFactory::class, $fabrique);

        return $fabrique;
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
