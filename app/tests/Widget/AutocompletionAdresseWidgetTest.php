<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Tests\Widget\Double\RechercheAdresseEnMemoire;
use App\Widget\AdresseSuggeree;
use App\Widget\EvenementWidget;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Autocomplétion d'adresse de la page publique (#287, épic #6).
 *
 * Le visiteur tape son adresse ; le navigateur interroge **notre** endpoint, qui
 * relaie vers la BAN et renvoie des propositions portant leur code INSEE. C'est
 * ce code qui rend l'estimation possible (la maille du moteur est la commune) :
 * sans lui, la page publique ne peut afficher aucun chiffre.
 *
 * Endpoint public : rattaché à une page **active** (404 sinon, kill switch #268
 * compris), plafonné par IP (#43), et muet — l'adresse tapée ne se journalise
 * nulle part (invariant de la collecte anonyme, #90).
 */
final class AutocompletionAdresseWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string HOTE_APP = 'app.valeurverte.localhost';

    public function testUneSaisieRenvoieLesSuggestionsAvecLeurCodeInsee(): void
    {
        [$client, $token] = $this->preparer();
        $this->recherche($client)->definir(
            AdresseSuggeree::creer('12 Rue des Lilas 31000 Toulouse', '31555'),
            AdresseSuggeree::creer('12 Rue des Lilas 31200 Toulouse', '31555'),
        );

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=12 rue des lilas');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            [
                ['libelle' => '12 Rue des Lilas 31000 Toulouse', 'code_insee' => '31555'],
                ['libelle' => '12 Rue des Lilas 31200 Toulouse', 'code_insee' => '31555'],
            ],
            json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testLEndpointPrimeSurLeSlugDecoratif(): void
    {
        // Le slug est libre : « adresses » ne doit pas être servi comme une page
        // habillée, sinon l'autocomplétion casserait selon le libellé choisi.
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=lilas');

        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testLaPageBrancheLeChampAdresseSurSonEndpoint(): void
    {
        // Le module d'autocomplétion ne connaît pas les routes : la page lui donne
        // l'URL de son propre endpoint, jeton compris.
        [$client, $token] = $this->preparer();

        $crawler = $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/'.$token.'/adresses',
            $crawler->filter('form[data-widget="estimation"]')->attr('data-adresse-url'),
        );
        self::assertCount(1, $crawler->filter('[data-adresse="suggestions"]'));
    }

    public function testLaPageChargeLeModuleDAutocompletion(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertStringContainsString(
            'widget_adresse',
            (string) $client->getResponse()->getContent(),
            "Sans son module, le champ adresse ne résout aucune commune : pas de chiffre possible.",
        );
    }

    public function testUneSaisieTropCourteNInterrogePasLaSource(): void
    {
        // Deux caractères ne discriminent rien : on épargne l'appel réseau plutôt
        // que de relayer chaque frappe vers la BAN.
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=li');

        self::assertResponseIsSuccessful();
        self::assertSame('[]', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->recherche($client)->appels());
    }

    public function testUnJetonInconnuNExistePas(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/jetonfantome/adresses?q=lilas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnePageDesactiveeNExistePas(): void
    {
        // Kill switch (#268) : couper la page coupe aussi ses endpoints.
        [$client, $token] = $this->preparer(active: false);

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=lilas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLEndpointNExistePasHorsDuSousDomaineWidget(): void
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_APP.'/'.$token.'/adresses?q=lilas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeDepassementDuPlafondEstRefuse(): void
    {
        [$client, $token] = $this->preparer();
        $limiteur = $this->limiteurAdresse();

        // On épuise le plafond par IP, puis on demande une suggestion de plus.
        while ($limiteur->create('127.0.0.1')->consume(1)->isAccepted()) {
        }

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=lilas');

        self::assertResponseStatusCodeSame(429);
    }

    public function testLAdresseTapeeNeSeJournalisePas(): void
    {
        // La mesure du parcours (#90) est anonyme : l'adresse elle-même n'entre
        // jamais en base, et une frappe n'ouvre pas d'événement.
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/adresses?q=12 rue des lilas');

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $evenements = $em->getRepository(EvenementWidget::class)->findAll();
        self::assertSame([], $evenements);
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function preparer(bool $active = true): array
    {
        $client = static::createClient();
        // Le double doit survivre à la requête pour être interrogé après coup.
        $client->disableReboot();

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $token = bin2hex(random_bytes(5));
        $page = PagePublique::creer(1, $token, 'agence-durand');
        if (!$active) {
            $page->desactiver();
        }
        $em->persist($page);
        $em->flush();

        $this->limiteurAdresse()->create('127.0.0.1')->reset();

        return [$client, $token];
    }

    private function limiteurAdresse(): RateLimiterFactoryInterface
    {
        $limiteur = static::getContainer()->get('limiter.widget_adresse');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiteur);

        return $limiteur;
    }

    private function recherche(KernelBrowser $client): RechercheAdresseEnMemoire
    {
        $double = $client->getContainer()->get(RechercheAdresseEnMemoire::class);
        self::assertInstanceOf(RechercheAdresseEnMemoire::class, $double);

        return $double;
    }
}
