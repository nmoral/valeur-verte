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
 * Honeypot anti-bot du widget public (#43, contrôle 3, épic #6).
 *
 * Un champ leurre masqué (`site_web`) est présent dans les formulaires publics :
 * un humain ne le voit ni ne le remplit ; un bot naïf le remplit. Toute soumission
 * où le leurre est renseigné est rejetée (422), sans friction pour l'utilisateur.
 */
final class PiegeAntiBotWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string CODE_INSEE = '31555';

    public function testLeHoneypotRempliRejetteLEstimation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', $this->saisie([
            'site_web' => 'http://spam.example',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLeHoneypotRempliRejetteLaCaptureDeLead(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        // Maille couverte : sans le honeypot la capture aboutirait — le 422 ne peut
        // donc venir que du champ leurre rempli, pas d'une estimation indisponible.
        $this->couvrir();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/contact', $this->saisie([
            'nom' => 'Martin',
            'email' => 'martin@exemple.fr',
            'consentement' => 'on',
            'site_web' => 'http://spam.example',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testLeChampPiegeEstPresentDansLeFormulaire(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-widget="estimation"] input[name="site_web"]');
    }

    public function testSansHoneypotLEstimationPasse(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $token = $this->creerPage();
        $this->libererLimiteursWidget($token);
        $this->couvrir();

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
            'jeton_chargement' => $this->jetonChargementValide(),
        ], $extra);
    }

    private function jetonChargementValide(): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $horloge = new MockClock(new \DateTimeImmutable('@'.(time() - JetonChargementWidget::AGE_MIN_SECONDES - 1)));

        return (new JetonChargementWidget($secret, $horloge))->emettre();
    }
}
