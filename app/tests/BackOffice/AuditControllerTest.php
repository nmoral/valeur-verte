<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AuditControllerTest extends WebTestCase
{
    private const URL = 'http://valeurverte.localhost/audit';

    public function testLaPagePorteLeFormulaireDeSaisie(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="adresse"]');
        self::assertSelectorExists('input[name="surface_batie"]');
        self::assertSelectorExists('input[name="surface_terrain"]');
        self::assertSelectorExists('input[name="nombre_pieces"]');
        // Le DPE se choisit par de gros boutons (radios), pas par un sélecteur.
        self::assertSelectorExists('input[type="radio"][name="dpe_avant"][value="C"]');
        self::assertSelectorExists('input[type="radio"][name="dpe_apres"][value="C"]');
        self::assertSelectorNotExists('select[name="dpe_avant"]');
        // Autocomplétion d'adresse (progressive enhancement).
        self::assertSelectorExists('#adresse-suggestions');
        self::assertSelectorExists('script[src="/js/audit-adresse.js"]');
        // Géolocalisation : proposer l'adresse depuis la position.
        self::assertSelectorExists('#adresse-geoloc');
    }

    public function testUneSaisieValideMeneAUneConfirmation(): void
    {
        $client = static::createClient();

        $this->soumettre($client, [
            'adresse' => '12 rue des Lilas, Toulouse',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saisie validée', $html);
        self::assertStringContainsString('12 rue des Lilas, Toulouse', $html);
        self::assertSelectorNotExists('.form-error');
    }

    public function testUneSaisieHorsBorneEstBloquee(): void
    {
        $client = static::createClient();

        $this->soumettre($client, [
            'adresse' => '12 rue des Lilas',
            'surface_batie' => '5',
            'surface_terrain' => '500',
            'dpe_avant' => 'C',
            'dpe_apres' => 'E',
        ]);

        self::assertResponseStatusCodeSame(422);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('surface bâtie', $html);
        self::assertStringContainsString('DPE cible', $html);
        self::assertStringNotContainsString('Saisie validée', $html);
    }

    public function testUnRatioSurfacePiecesDouteuxAvertitSansBloquer(): void
    {
        $client = static::createClient();

        $this->soumettre($client, [
            'adresse' => '12 rue des Lilas',
            'surface_batie' => '90',
            'surface_terrain' => '500',
            'dpe_avant' => 'E',
            'dpe_apres' => 'C',
            'nombre_pieces' => '12',
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Saisie validée', $html);
        self::assertStringContainsString('surface/pièces', $html);
    }

    /**
     * @param array<string, string> $valeurs
     */
    private function soumettre(KernelBrowser $client, array $valeurs): void
    {
        // Le succès répond en Post/Redirect/Get : on suit la redirection pour
        // atterrir sur la confirmation ; un 422 (blocage) ne redirige pas.
        $client->followRedirects();

        $crawler = $client->request('GET', self::URL);
        self::assertInstanceOf(Crawler::class, $crawler);
        $formulaire = $crawler->selectButton('Valider la saisie')->form();
        foreach ($valeurs as $champ => $valeur) {
            $formulaire[$champ] = $valeur;
        }
        $client->submit($formulaire);
    }
}
