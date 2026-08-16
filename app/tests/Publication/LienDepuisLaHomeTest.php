<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Depuis « D'où vient le chiffre » (home), le visiteur peut aller voir l'étude
 * publique (#224) : la méthode annoncée devient vérifiable.
 *
 * Le lien n'existe que si un millésime est publié — la page d'étude répondant 404
 * sinon, l'afficher inconditionnellement fabriquerait un lien mort sur la vitrine.
 */
final class LienDepuisLaHomeTest extends WebTestCase
{
    use ProvisionneModelePublie;

    private const HOTE = 'valeurverte.localhost';

    public function testLaMethodeRenvoieVersLEtudeQuandUnMillesimeEstPublie(): void
    {
        $client = $this->naviguer();
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/valeur-verte-par-departement',
            $crawler->filter('#methode a[data-lien="etude"]')->attr('href'),
        );
    }

    public function testSansMillesimePublieLaHomeNAffichePasDeLienMort(): void
    {
        $client = $this->naviguer();
        $this->prive();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-lien="etude"]');
    }

    private function naviguer(): KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_HOST', self::HOTE);

        return $client;
    }
}
