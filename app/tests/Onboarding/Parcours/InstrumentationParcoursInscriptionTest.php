<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Parcours;

use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\TypeEvenementInscription;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Le parcours d'inscription est instrumenté de bout en bout (#111) : afficher
 * l'écran, renseigner un SIRET et créer un compte laissent chacun une trace
 * anonyme, de sorte que l'abandon sur l'écran SIRET se lise. Le funnel étant tenu
 * par la session HTTP, l'affichage et la création d'une même visite partagent leur
 * identifiant — cette visite n'est donc pas comptée comme un abandon.
 */
final class InstrumentationParcoursInscriptionTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';
    private const SIRET_CONNU = '12345678901234';

    public function testAfficherLEcranEnregistreLEtapeAffichee(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertSame(1, $this->comptes()[TypeEvenementInscription::InscriptionAffichee->value] ?? 0);
    }

    public function testUnRechargementNeCompteQuUneSeuleVisiteAffichee(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription');
        $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertSame(1, $this->comptes()[TypeEvenementInscription::InscriptionAffichee->value] ?? 0);
    }

    public function testLeLookupSiretEnregistreLEtapeSiretRenseigne(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription/entreprise/'.self::SIRET_CONNU);

        self::assertSame(1, $this->comptes()[TypeEvenementInscription::SiretRenseigne->value] ?? 0);
    }

    public function testUneInscriptionValideCloutLeFunnelSansAbandon(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'trace@example.com', 'motdepasse-solide', self::SIRET_CONNU);

        self::assertResponseRedirects('http://app.'.self::HOTE.'/audit');
        $comptes = $this->comptes();
        // Affichage puis création, même visite : une session distincte de chaque côté.
        self::assertSame(1, $comptes[TypeEvenementInscription::InscriptionAffichee->value] ?? 0);
        self::assertSame(1, $comptes[TypeEvenementInscription::CompteCree->value] ?? 0);
    }

    /**
     * @return array<string, int>
     */
    private function comptes(): array
    {
        $repository = self::getContainer()->get(EvenementInscriptionRepository::class);
        self::assertInstanceOf(EvenementInscriptionRepository::class, $repository);

        return $repository->compterSessionsParType();
    }

    private function soumettreInscription(KernelBrowser $client, string $email, string $motDePasse, string $siret): void
    {
        $limiteur = self::getContainer()->get('limiter.inscription_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $limiteur);
        $limiteur->create('127.0.0.1')->reset();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/inscription');
        self::assertInstanceOf(Crawler::class, $crawler);
        $formulaire = $crawler->selectButton('Créer mon compte')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $formulaire['siret'] = $siret;
        $caseCgu = $formulaire['cgu'];
        self::assertInstanceOf(ChoiceFormField::class, $caseCgu);
        $caseCgu->tick();
        $client->submit($formulaire);
    }
}
