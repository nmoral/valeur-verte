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
use App\Widget\VerificateurTurnstile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Câblage de la vérification Turnstile sur les endpoints publics (#43, contrôle 2).
 *
 * Quand Turnstile est configuré, un défi échoué doit bloquer l'estimation et la
 * capture (422) — ce qui prouve que les contrôleurs invoquent bien le vérificateur.
 * Le service est ici remplacé par un double configuré dont le défi échoue.
 */
final class TurnstileEndpointWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testUnDefiTurnstileEchoueRejetteLEstimation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();
        $this->turnstileConfigureQuiEchoue();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'jeton_chargement' => $this->jetonChargementValide(),
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnDefiTurnstileEchoueRejetteLaCaptureDeLead(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();
        $this->turnstileConfigureQuiEchoue();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', $this->saisie([
            'nom' => 'Martin',
            'email' => 'martin@exemple.fr',
            'consentement' => 'on',
            'jeton_chargement' => $this->jetonChargementValide(),
        ]));

        self::assertResponseStatusCodeSame(422);
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
