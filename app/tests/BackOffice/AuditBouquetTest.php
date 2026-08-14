<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Rapport\RapportRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sélection du bouquet de travaux en rendez-vous (#29, §4/§9.2) : le pro choisit
 * un bouquet pré-configuré (pas des postes un par un). Le choix est figé dans le
 * rapport et restitué. Sans configuration back-office (#5), les bouquets types
 * par défaut sont proposés.
 */
final class AuditBouquetTest extends WebTestCase
{
    private const URL = 'http://valeurverte.localhost/audit';

    public function testLeFormulaireProposeLesBouquetsTypes(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        // On sélectionne un bouquet, pas des postes un par un.
        self::assertSelectorExists('input[type="radio"][name="bouquet"][value="Isolation seule"]');
        self::assertSelectorExists('input[type="radio"][name="bouquet"][value="Chauffage seul"]');
        // Le bouquet complet est proposé et coché par défaut.
        self::assertSelectorExists('input[type="radio"][name="bouquet"][value="Rénovation d\'ampleur standard"][checked]');
    }

    public function testLeBouquetChoisiEstFigeDansLeRapportEtRestitue(): void
    {
        $client = static::createClient();

        $this->soumettre($client, [
            'adresse' => '12 rue des Lilas, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'bouquet' => 'Isolation seule',
        ]);

        self::assertResponseIsSuccessful();
        // La restitution affiche les travaux retenus.
        self::assertSelectorTextContains('body', 'Travaux retenus');
        self::assertSelectorTextContains('body', 'Isolation seule');

        // Et le bouquet est figé dans l'instantané du rapport archivé.
        $rapport = $this->rapports()->recents()[0];
        self::assertEquals(Bouquet::isolationSeule(), $rapport->instantane()->bouquet());
    }

    /**
     * @param array<string, string> $valeurs
     */
    private function soumettre(KernelBrowser $client, array $valeurs): void
    {
        $client->followRedirects();

        $crawler = $client->request('GET', self::URL);
        self::assertInstanceOf(Crawler::class, $crawler);
        $formulaire = $crawler->selectButton('Valider la saisie')->form();
        foreach ($valeurs as $champ => $valeur) {
            $formulaire[$champ] = $valeur;
        }
        $client->submit($formulaire);
    }

    private function rapports(): RapportRepository
    {
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        return $repository;
    }
}
