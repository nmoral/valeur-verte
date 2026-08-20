<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\EvenementWidget;
use App\Widget\EvenementWidgetRepository;
use App\Widget\PagePublique;
use App\Widget\TypeEvenementWidget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Endpoint public de collecte des événements du parcours (#90, épic #6).
 *
 * Certaines étapes n'existent que dans le navigateur (l'adresse est saisie, le
 * détail est déplié) : aucune requête serveur ne les constate. Le navigateur les
 * déclare donc ici — et seulement celles-là. Les étapes que le serveur constate
 * lui-même (premier chiffre affiché, lead soumis) ne sont **jamais** acceptées de
 * l'extérieur, sans quoi n'importe qui fabriquerait un funnel flatteur.
 *
 * L'endpoint est public : plafonné par IP (#44), rattaché à une page active, et
 * il n'accepte qu'un identifiant de visite bien formé — jamais de donnée
 * personnelle, jamais de charge utile libre.
 */
final class CollecteEvenementWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testUneEtapeCoteNavigateurEstAcceptee(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $this->reinitialiser('limiter.widget_evenement', '127.0.0.1');

        $this->declarer($client, $token, 'adresse_saisie');

        self::assertResponseStatusCodeSame(204);
        self::assertSame([TypeEvenementWidget::AdresseSaisie], $this->typesDeLaSession(self::SESSION));
    }

    public function testUneEtapeConstateeParLeServeurNestPasDeclarable(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $this->reinitialiser('limiter.widget_evenement', '127.0.0.1');

        $this->declarer($client, $token, 'lead_soumis');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->typesDeLaSession(self::SESSION));
    }

    public function testUnTypeInconnuEstRejete(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $this->reinitialiser('limiter.widget_evenement', '127.0.0.1');

        $this->declarer($client, $token, 'promenade_sur_la_lune');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->typesDeLaSession(self::SESSION));
    }

    public function testUnIdentifiantDeVisiteMalformeEstRejete(): void
    {
        // Un événement sans visite rattachable ne dit rien du parcours : on le
        // refuse plutôt que d'ouvrir une visite fantôme depuis l'extérieur.
        $client = static::createClient();
        $token = $this->creerPage();
        $this->reinitialiser('limiter.widget_evenement', '127.0.0.1');

        $this->declarer($client, $token, 'adresse_saisie', session: 'pas-un-identifiant');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->typesDeLaSession('pas-un-identifiant'));
    }

    public function testUnePageDesactiveeNeCollectePas(): void
    {
        $client = static::createClient();
        $token = $this->creerPage(active: false);
        $this->reinitialiser('limiter.widget_evenement', '127.0.0.1');

        $this->declarer($client, $token, 'adresse_saisie');

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->typesDeLaSession(self::SESSION));
    }

    public function testAuDelaDuPlafondLaCollecteEst429(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();
        $ip = '203.0.113.50';
        $this->epuiser('limiter.widget_evenement', $ip);

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $this->declarer($client, $token, 'adresse_saisie');

        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->typesDeLaSession(self::SESSION));
    }

    private function declarer(
        KernelBrowser $client,
        string $token,
        string $type,
        string $session = self::SESSION,
    ): void {
        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/evenement', [
            'type' => $type,
            'session_parcours' => $session,
        ]);
    }

    /**
     * @return list<TypeEvenementWidget>
     */
    private function typesDeLaSession(string $session): array
    {
        $registry = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return array_map(
            static fn (EvenementWidget $evenement) => $evenement->type(),
            (new EvenementWidgetRepository($registry))->pourSession($session),
        );
    }

    private function creerPage(bool $active = true): string
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

        $page = PagePublique::creer($tenantId, $token = bin2hex(random_bytes(5)), 'agence-durand');
        if (!$active) {
            $page->desactiver();
        }
        $em->persist($page);
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
