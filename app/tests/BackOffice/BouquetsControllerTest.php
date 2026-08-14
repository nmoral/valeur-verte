<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Bouquet\BouquetRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Administration des bouquets types (#35, §9.2) : le pro compose des combinaisons
 * nommées de postes, réutilisées en rendez-vous (#29). Tant qu'il n'a rien
 * configuré, les trois bouquets types par défaut sont proposés (ONB-3).
 */
final class BouquetsControllerTest extends WebTestCase
{
    public function testLEcranListeLeBouquetParDefautAvecSesPostes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bouquets');

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('h1', 'Bouquets types');
        self::assertSelectorTextContains('nav.side a.on', 'Bouquets types');

        // Le bouquet « rénovation d'ampleur standard » est actif par défaut (ONB-3)...
        self::assertSelectorTextContains('table', "Rénovation d'ampleur standard");
        // ...et couvre tous les postes : on en vérifie les extrêmes.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Isolation des murs', $html);
        self::assertStringContainsString('VMC double flux', $html);
    }

    public function testLEcranOffreLeFormulaireDeCompositionDUnBouquet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/bouquets');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/bouquets"][method="post"] input[name="nom"]');
        // Les postes se cochent (on compose une combinaison), avec les extrêmes du barème.
        self::assertSelectorExists('form[action="/bouquets"] input[type="checkbox"][name="postes[]"][value="Murs"]');
        self::assertSelectorExists('form[action="/bouquets"] input[type="checkbox"][name="postes[]"][value="Vmc"]');
    }

    public function testUnBouquetComposeEstEnregistreEtApparaitDansLaListe(): void
    {
        $client = static::createClient();

        $client->request('POST', '/bouquets', [
            'nom' => 'Isolation + chauffage',
            'postes' => ['Murs', 'Combles', 'Chauffage'],
        ]);

        self::assertResponseStatusCodeSame(302);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Isolation + chauffage');

        // Les défauts ont cédé la place aux bouquets du pro.
        $disponibles = $this->rappels($client)->disponibles();
        self::assertCount(1, $disponibles);
        self::assertSame('Isolation + chauffage', $disponibles[0]->nom());
    }

    public function testUnBouquetSansNomEstRefuse(): void
    {
        $client = static::createClient();

        $client->request('POST', '/bouquets', [
            'nom' => '   ',
            'postes' => ['Murs'],
        ]);

        self::assertResponseStatusCodeSame(422);
        // Rien n'est persisté : la liste par défaut reste servie.
        self::assertCount(3, $this->rappels($client)->disponibles());
    }

    public function testUnBouquetSansAucunPosteEstRefuse(): void
    {
        $client = static::createClient();

        $client->request('POST', '/bouquets', [
            'nom' => 'Vide',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(3, $this->rappels($client)->disponibles());
    }

    public function testUnBouquetPeutEtreSupprime(): void
    {
        $client = static::createClient();

        $client->request('POST', '/bouquets', ['nom' => 'À jeter', 'postes' => ['Murs']]);
        $client->request('POST', '/bouquets/supprimer', ['nom' => 'À jeter']);

        self::assertResponseStatusCodeSame(302);
        // Plus aucun bouquet du pro : retour aux défauts.
        self::assertCount(3, $this->rappels($client)->disponibles());
    }

    private function rappels(KernelBrowser $client): BouquetRepository
    {
        $repository = $client->getContainer()->get(BouquetRepository::class);
        self::assertInstanceOf(BouquetRepository::class, $repository);

        return $repository;
    }
}
