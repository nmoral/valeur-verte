<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafond d'émission des jetons de chargement du widget (#43, contrôles 1 & 4).
 *
 * L'émission d'un jeton (rendu du formulaire, GET) est elle-même plafonnée par IP :
 * un scraper ne peut pas moissonner des jetons frais en masse pour alimenter des
 * soumissions. Au dépassement : 429. En deçà, le formulaire se charge normalement.
 */
final class EmissionJetonRateLimitWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';

    public function testAuDelaDuPlafondDEmissionLeChargementEst429(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $ip = '203.0.113.40';
        $this->epuiser('limiter.widget_emission', $ip);

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseStatusCodeSame(429);
    }

    public function testEnDecaDuPlafondLeChargementPasse(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $ip = '203.0.113.41';
        $this->reinitialiser('limiter.widget_emission', $ip);

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-widget="estimation"] input[name="jeton_chargement"]');
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

    private function limiteur(string $service): RateLimiterFactoryInterface
    {
        $fabrique = static::getContainer()->get($service);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $fabrique);

        return $fabrique;
    }
}
