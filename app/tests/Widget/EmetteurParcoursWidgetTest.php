<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Émetteur navigateur des étapes de parcours (#90, épic #6).
 *
 * Deux étapes n'existent que dans la page — l'adresse est saisie, le détail est
 * déplié — et n'atteignent le serveur que si la page les déclare. La page publique
 * embarque donc un module minimal qui poste vers l'endpoint de collecte.
 *
 * **Amélioration progressive** : sans JavaScript, le parcours fonctionne
 * exactement pareil (le formulaire est rendu et posté par le serveur) ; seules
 * ces deux mesures manquent. Le champ visé porte un marqueur explicite plutôt
 * qu'un sélecteur deviné.
 */
final class EmetteurParcoursWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';

    public function testLaPagePubliqueEmbarqueLEmetteurDeParcours(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'widget_parcours',
            (string) $client->getResponse()->getContent(),
            'La page doit charger le module d\'émission des étapes côté navigateur.',
        );
    }

    public function testLeChampAdressePorteLeMarqueurDeLEtape(): void
    {
        $client = static::createClient();
        $token = $this->creerPage();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertSelectorExists('input[name="adresse"][data-parcours="adresse"]');
    }

    public function testLeFormulairePorteLUrlDeCollecte(): void
    {
        // L'émetteur ne devine pas l'URL : le serveur la lui donne, token compris.
        $client = static::createClient();
        $token = $this->creerPage();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');

        self::assertSame(
            '/'.$token.'/evenement',
            $client->getCrawler()->filter('form[data-widget="estimation"]')->attr('data-parcours-url'),
        );
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
}
